<?php
/**
 * Module #1 — WooCommerce.
 *
 * Threads the browser dava_session_id into the checkout order (hidden field
 * + order meta) and, once the order reaches a paid status, sends a confirmed
 * conversion event to Dáva carrying that session id.
 *
 * Session capture works on both checkouts and writes the same order meta
 * keys on each: the classic (shortcode) checkout through a hidden field, the
 * Block Checkout through Store API extension data (namespace `dava-tracking`,
 * one property `session_id`, sent by public/js/checkout-block.js).
 *
 * Send-path note: this module uses `transaction_id = String(order_id)`, the
 * bare WooCommerce order number. The native Dáva WooCommerce webhook keys its
 * event on the same bare order number, so a site running BOTH the webhook AND
 * this plugin gets one conversion per order (the webhook supplies the order
 * data, this plugin supplies the session join). That merge only happens once
 * the Dáva API release that keys webhook events by the bare order id is
 * deployed on the workspace; before that release the two deliveries are two
 * rows. A Dáva workspace connects one WooCommerce store. Do NOT change
 * `transaction_id` to a custom format: that would break the merge.
 *
 * When "Require cookie consent before tracking" is on, no order is sent
 * unless the visitor had consented at checkout (Dava_Tracking_Settings::
 * has_visitor_consented() — WP Consent API if a provider plugin is active,
 * else the dava-consent cookie) — captured onto the order at checkout since
 * the later status transition can't see the live request.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_WooCommerce {

    /**
     * Same status set the T2 webhook translator accepts
     * (wooCommerceTranslator.ts CONVERSION_STATUSES) — kept identical so the
     * two paths trigger on the exact same order transitions and can dedupe.
     */
    const CONVERSION_STATUSES = ['processing', 'completed', 'on-hold'];

    const SESSION_META_KEY = '_dava_session_id';
    const SENT_META_KEY = '_dava_event_sent';
    const CONSENT_META_KEY = '_dava_consent';

    // Store API extension data (Block Checkout). The namespace and the one
    // property name are also hard-coded in public/js/checkout-block.js.
    const BLOCK_NAMESPACE = 'dava-tracking';
    const BLOCK_STORE_API_ENDPOINT = 'checkout';

    // First WooCommerce version where the stable JS action `setExtensionData`
    // exists (the older `__internalSetExtensionData` is deprecated since 9.9.0
    // in favour of it). Below it the Block path is not guaranteed; the classic
    // path is unaffected. Shown on Tools > Site Health.
    const MIN_WC_VERSION_BLOCK_CHECKOUT = '9.9.0';

    // The two session id shapes the Dáva API accepts (isValidSessionId in the
    // API's sessionClassification.ts): a UUID, or the snippet fallback id.
    // /v1/events answers 400 for any other dava_session_id, which would lose
    // the whole conversion, so anything else is dropped before it is stored.
    // \A and \z, not ^ and $: PHP's $ also matches before a trailing newline.
    const SESSION_ID_UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';
    const SESSION_ID_FALLBACK_PATTERN = '/\A[0-9a-z]{1,20}-[0-9a-z]{1,20}-[0-9a-z]{1,20}\z/';

    // Backfill: for a site that enables tracking after already having
    // WooCommerce orders, or that fixed a config issue and wants to recover
    // conversions missed while it was broken. Fixed window/cap instead of a
    // date picker or background job — keeps this a same-request admin action
    // rather than needing a queue.
    const BACKFILL_ACTION = 'dava_tracking_backfill';
    const BACKFILL_NONCE_ACTION = 'dava_tracking_backfill_nonce';
    const BACKFILL_DAYS = 30;
    const BACKFILL_LIMIT = 200;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function register_hooks() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_script']);
        add_action('woocommerce_after_order_notes', [$this, 'render_session_field']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'capture_session_id']);

        // Block Checkout: the classic hooks above never fire for it. The
        // extension data is registered once WooCommerce Blocks has loaded
        // (immediately if it already did), and the order meta is written when
        // the Store API builds the order from the checkout request.
        if (did_action('woocommerce_blocks_loaded')) {
            $this->register_block_checkout_data();
        } else {
            add_action('woocommerce_blocks_loaded', [$this, 'register_block_checkout_data']);
        }
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'capture_block_session_id'], 10, 2);

        add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_changed'], 10, 4);
        add_action('admin_post_' . self::BACKFILL_ACTION, [$this, 'handle_backfill_request']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_privacy_eraser']);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_dava_journey_link']);
    }

    /**
     * A direct link from the order edit screen into that visitor's actual
     * journey in the Dáva dashboard — real route (apps/web/src/views/
     * Dashboard/views/Sessions/_routes.tsx), not a guess: /sessions/:sessionKey
     * where sessionKey is the literal session_id UUID, which is exactly what
     * _dava_session_id holds. Only shown once an event has actually been
     * sent for this order — before that there's no session to jump to from
     * Dáva's side even if the order happens to carry a session id.
     */
    public function render_dava_journey_link($order) {
        if (!$order->get_meta(self::SENT_META_KEY)) {
            return;
        }

        $session_id = $order->get_meta(self::SESSION_META_KEY);
        if (empty($session_id)) {
            return;
        }

        printf(
            '<p class="form-field form-field-wide"><a href="%s" target="_blank" rel="noopener noreferrer">%s &#8599;</a></p>',
            esc_url(trailingslashit(DAVA_TRACKING_DASHBOARD_URL) . 'sessions/' . rawurlencode($session_id)),
            esc_html__('View this visitor\'s journey in Dáva', 'dava-tracking')
        );
    }

    /**
     * Registers with WordPress's built-in Tools > Erase Personal Data flow
     * (Requests page). _dava_session_id is a pseudonymous identifier tied to
     * a specific visitor's browsing session, so it's erased on request like
     * any other personal data this order carries. _dava_consent (a plain
     * yes/no flag, not identifying on its own) goes with it since it's
     * meaningless once the session id is gone. _dava_event_sent is
     * deliberately NOT touched — it's an operational guard against
     * re-sending, not personal data about the visitor, and removing it could
     * cause a duplicate send if the order is ever reprocessed.
     */
    public function register_privacy_eraser($erasers) {
        $erasers['dava-tracking-woocommerce'] = [
            'eraser_friendly_name' => __('Dáva Tracking (WooCommerce order data)', 'dava-tracking'),
            'callback' => [$this, 'erase_personal_data'],
        ];
        return $erasers;
    }

    public function erase_personal_data($email_address, $page = 1) {
        $per_page = 20;
        $orders = wc_get_orders([
            'billing_email' => $email_address,
            'limit' => $per_page,
            'page' => $page,
            'return' => 'objects',
        ]);

        $removed = false;
        foreach ($orders as $order) {
            if ($order->get_meta(self::SESSION_META_KEY) === '' && $order->get_meta(self::CONSENT_META_KEY) === '') {
                continue;
            }
            $order->delete_meta_data(self::SESSION_META_KEY);
            $order->delete_meta_data(self::CONSENT_META_KEY);
            $order->save();
            $removed = true;
        }

        return [
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => [],
            'done' => count($orders) < $per_page,
        ];
    }

    public function enqueue_checkout_script() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $options = Dava_Tracking_Settings::get_options();
        if (empty($options['enabled'])) {
            return;
        }

        wp_enqueue_script(
            'dava-tracking-checkout',
            DAVA_TRACKING_PLUGIN_URL . 'public/js/checkout.js',
            [Dava_Tracking_Listener::SESSION_HELPER_HANDLE],
            DAVA_TRACKING_VERSION,
            true
        );

        // Block Checkout script. Skipped only when the page positively uses
        // the classic [woocommerce_checkout] shortcode, so a block-theme
        // template (no post content to inspect) still gets it. WordPress
        // silently does not print a script whose dependency is not
        // registered, so an older WooCommerce without the Blocks data store
        // simply never loads it.
        $post = get_post();
        $is_classic_shortcode_page = $post && has_shortcode($post->post_content, 'woocommerce_checkout');
        if (!$is_classic_shortcode_page) {
            wp_enqueue_script(
                'dava-tracking-checkout-block',
                DAVA_TRACKING_PLUGIN_URL . 'public/js/checkout-block.js',
                [Dava_Tracking_Listener::SESSION_HELPER_HANDLE, 'wp-data', 'wc-blocks-data-store'],
                DAVA_TRACKING_VERSION,
                true
            );
        }
    }

    /**
     * Registers the Store API checkout extension data `dava-tracking` with one
     * property, `session_id`. The schema must never reject a checkout: the
     * property accepts a string or null, the validate callback always passes,
     * and anything that is not a valid session id is reduced to '' when the
     * order is written (capture_block_session_id).
     */
    public function register_block_checkout_data() {
        if (!function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }

        try {
            // Returns true or a WP_Error; a failure only means no Block
            // capture, never a checkout error.
            woocommerce_store_api_register_endpoint_data([
                'endpoint' => self::BLOCK_STORE_API_ENDPOINT,
                'namespace' => self::BLOCK_NAMESPACE,
                'schema_callback' => [$this, 'block_checkout_schema'],
                'schema_type' => ARRAY_A,
            ]);
        } catch (\Throwable $e) {
            return;
        }
    }

    public function block_checkout_schema() {
        return [
            'session_id' => [
                'description' => __('Dáva browser session id.', 'dava-tracking'),
                'type' => ['string', 'null'],
                'context' => ['view', 'edit'],
                'arg_options' => [
                    'validate_callback' => '__return_true',
                    'sanitize_callback' => [$this, 'sanitize_block_session_id'],
                ],
            ],
        ];
    }

    public function sanitize_block_session_id($value) {
        return $this->normalize_session_id($value);
    }

    /**
     * Returns the session id when it has a shape the Dáva API accepts, else ''.
     * Anything that is not a string (array, number, null) is ''. Shared by the
     * Block path, the classic path and the send path.
     */
    private function normalize_session_id($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match(self::SESSION_ID_UUID_PATTERN, $value) === 1 || preg_match(self::SESSION_ID_FALLBACK_PATTERN, $value) === 1) {
            return $value;
        }

        return '';
    }

    /**
     * Block Checkout counterpart of capture_session_id(): same meta keys, same
     * consent rule, written through the order CRUD object (HPOS-safe).
     *
     * Runs inside the Store API request that builds the order, so the visitor's
     * own cookies are present for the consent check. The Store API saves the
     * order after this action returns, so this does NOT call $order->save().
     * A throw here would put the Checkout Block in an error state and block the
     * sale, so everything is caught: the worst outcome is an order without
     * Dáva meta.
     */
    public function capture_block_session_id($order, $request = null) {
        try {
            if (!is_object($order) || !method_exists($order, 'update_meta_data')) {
                return;
            }

            $session_id = $this->extract_block_session_id($request);
            if ($session_id !== '') {
                $order->update_meta_data(self::SESSION_META_KEY, $session_id);
            }

            $order->update_meta_data(self::CONSENT_META_KEY, Dava_Tracking_Settings::has_visitor_consented() ? '1' : '0');
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * extensions may be absent, null, a scalar or an array; the namespace entry
     * and its session_id likewise. Only a valid session id string comes out.
     */
    private function extract_block_session_id($request) {
        if (!is_object($request) || !method_exists($request, 'get_param')) {
            return '';
        }

        $extensions = $request->get_param('extensions');
        if (!is_array($extensions) || !isset($extensions[self::BLOCK_NAMESPACE])) {
            return '';
        }

        $data = $extensions[self::BLOCK_NAMESPACE];
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (!is_array($data) || !isset($data['session_id'])) {
            return '';
        }

        return $this->normalize_session_id($data['session_id']);
    }

    /**
     * Raw hidden field, not a WooCommerce checkout field — deliberately kept
     * outside WooCommerce's own field system (same pattern as the Contact
     * Form module) so it can't be renamed/validated/stripped by third-party
     * checkout-field-editor plugins.
     */
    public function render_session_field() {
        echo '<input type="hidden" name="dava_session_id" id="dava_session_id" value="" />';
    }

    /**
     * HPOS-safe: uses the order CRUD object (update_meta_data + save) rather
     * than update_post_meta(), which silently no-ops once High-Performance
     * Order Storage is enabled (WC 8+ default) since order meta then lives
     * in a custom table, not wp_postmeta.
     *
     * Also captures consent here, at checkout, rather than reading the
     * dava-consent cookie when the order later reaches a paid status: that
     * status change can happen long afterward, from a payment gateway
     * webhook or an admin manually editing the order — neither carries the
     * original visitor's cookies. This is the only point in the order's
     * lifecycle where we're actually in that visitor's own request.
     */
    public function capture_session_id($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // An invalid value is dropped, not stored: /v1/events rejects the whole
        // event for a malformed dava_session_id (see normalize_session_id).
        $session_id = isset($_POST['dava_session_id']) ? $this->normalize_session_id(wp_unslash($_POST['dava_session_id'])) : '';
        if ($session_id !== '') {
            $order->update_meta_data(self::SESSION_META_KEY, $session_id);
        }

        $order->update_meta_data(self::CONSENT_META_KEY, Dava_Tracking_Settings::has_visitor_consented() ? '1' : '0');
        $order->save();
    }

    /**
     * Fires on every status transition, so an order that goes
     * pending -> processing -> completed must only send once. The
     * _dava_event_sent guard (checked/set on the order itself) handles that
     * regardless of which conversion status it first lands on — EXCEPT that
     * get_meta() then save() isn't atomic, so two near-simultaneous firings
     * for the same order (e.g. a payment gateway retrying its webhook) could
     * both read the guard as unset before either saves it, double-sending.
     * add_option() is used purely as a mutex: its underlying INSERT is
     * subject to wp_options' UNIQUE key on option_name, so only the first of
     * two concurrent calls for the same order_id can ever hold the lock.
     */
    public function handle_order_status_changed($order_id, $from_status, $to_status, $order) {
        if (!in_array($to_status, self::CONVERSION_STATUSES, true)) {
            return;
        }

        $this->send_conversion_event_for_order($order, $order_id, $to_status);
    }

    /**
     * Whether an order is eligible to send — the guard checks only, no side
     * effects. Shared by the real send path (send_conversion_event_for_order)
     * and the backfill preview (which must answer this without acquiring the
     * lock or touching the network).
     *
     * @return string One of: sent (eligible), already_sent, no_consent, no_email.
     */
    private function classify_order($order) {
        if ($order->get_meta(self::SENT_META_KEY)) {
            return 'already_sent';
        }

        $options = Dava_Tracking_Settings::get_options();
        if (!empty($options['require_consent']) && $order->get_meta(self::CONSENT_META_KEY) !== '1') {
            return 'no_consent';
        }

        if (empty($order->get_billing_email())) {
            return 'no_email';
        }

        return 'sent';
    }

    /**
     * Shared by the live status-change hook and backfill_orders() — same
     * lock, same guard, same consent check, same event shape either way.
     * Returns a short status string instead of a bool so the backfill tool
     * can report a real breakdown (not just a total) of why orders were or
     * weren't sent.
     *
     * @return string One of: sent, already_sent, no_consent, no_email, failed, locked.
     */
    private function send_conversion_event_for_order($order, $order_id, $to_status) {
        $lock_key = 'dava_tracking_lock_order_' . $order_id;
        if (!add_option($lock_key, '1', '', 'no')) {
            return 'locked';
        }

        try {
            $classification = $this->classify_order($order);
            if ($classification !== 'sent') {
                return $classification;
            }

            $email = $order->get_billing_email();

            $event = [
                'event_type' => 'conversion',
                'event_name' => 'woocommerce_order_paid',
                'transaction_id' => (string) $order_id,
                'hashed_email' => 'sha256:' . hash('sha256', strtolower(trim($email))),
                'value' => (float) $order->get_total(),
                'currency' => $order->get_currency(),
                'properties' => [
                    'order_id' => $order_id,
                    'status' => $to_status,
                ],
            ];

            // Re-validated here as well: an order stored by an earlier plugin
            // version may hold a value /v1/events would reject.
            $session_id = $this->normalize_session_id($order->get_meta(self::SESSION_META_KEY));
            if ($session_id !== '') {
                $event['dava_session_id'] = $session_id;
            }

            $result = Dava_Tracking_Event_Sender::instance()->send_event($event);

            if (is_wp_error($result)) {
                return 'failed';
            }

            $order->update_meta_data(self::SENT_META_KEY, '1');
            $order->save();
            return 'sent';
        } finally {
            delete_option($lock_key);
        }
    }

    /**
     * Resends conversion events for existing orders that never got one —
     * for a site that enables tracking after already having orders, or that
     * fixed a config issue and wants to recover what was missed while it was
     * broken. require_consent is still enforced: an order placed before this
     * plugin existed has no _dava_consent meta, so it's correctly skipped
     * (no_consent) rather than assumed consented.
     *
     * $dry_run classifies each order without sending anything or acquiring
     * the per-order lock (nothing to protect against — no send happens) —
     * lets a site see the breakdown before committing to a real backfill.
     * Its "would_send" key stands in for the real run's "sent"/"failed" keys,
     * since a preview can't know whether the actual API call would succeed.
     *
     * @return array Counts keyed by classify_order()/send_conversion_event_for_order()
     *               status strings ("would_send" instead of "sent"/"failed" in dry-run mode).
     */
    public function backfill_orders($days = self::BACKFILL_DAYS, $limit = self::BACKFILL_LIMIT, $dry_run = false) {
        $orders = wc_get_orders([
            'status' => self::CONVERSION_STATUSES,
            'date_created' => '>' . (time() - $days * DAY_IN_SECONDS),
            'limit' => $limit,
            'return' => 'objects',
        ]);

        $counts = $dry_run
            ? ['would_send' => 0, 'already_sent' => 0, 'no_consent' => 0, 'no_email' => 0]
            : ['sent' => 0, 'already_sent' => 0, 'no_consent' => 0, 'no_email' => 0, 'failed' => 0, 'locked' => 0];

        foreach ($orders as $order) {
            if ($dry_run) {
                $status = $this->classify_order($order);
                $status = $status === 'sent' ? 'would_send' : $status;
            } else {
                $status = $this->send_conversion_event_for_order($order, $order->get_id(), $order->get_status());
            }
            $counts[$status]++;
        }

        return $counts;
    }

    public function handle_backfill_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'dava-tracking'), '', ['response' => 403]);
        }
        check_admin_referer(self::BACKFILL_NONCE_ACTION);

        $dry_run = !empty($_POST['dry_run']);
        $counts = $this->backfill_orders(self::BACKFILL_DAYS, self::BACKFILL_LIMIT, $dry_run);
        $sent_count = $dry_run ? $counts['would_send'] : $counts['sent'];

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'dava-tracking',
                'tab' => 'diagnostics',
                'dava_backfill_mode' => $dry_run ? 'preview' : 'sent',
                'dava_backfill_sent' => $sent_count,
                'dava_backfill_skipped' => array_sum($counts) - $sent_count,
            ],
            admin_url('options-general.php')
        ));
        exit;
    }
}
