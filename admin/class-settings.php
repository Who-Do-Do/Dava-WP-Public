<?php
/**
 * Registers the "Dáva Tracking" settings page (Settings > Dáva Tracking) and
 * exposes the stored options to the rest of the plugin via get_options().
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_Settings {

    const OPTION_KEY = 'dava_tracking_options';
    const PAGE_SLUG = 'dava-tracking';
    const RESET_ACTION = 'dava_tracking_reset_defaults';
    const RESET_NONCE_ACTION = 'dava_tracking_reset_defaults_nonce';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function register_hooks() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_settings_assets']);
        add_action('admin_notices', [$this, 'maybe_render_setup_notice']);
        add_action('admin_post_' . self::RESET_ACTION, [$this, 'handle_reset_request']);
    }

    /**
     * Reverts every setting EXCEPT enabled/site_id/api_key back to the
     * documented defaults — a quick recovery for a site that hand-edited the
     * API base URL / listener URL / etc. and got tracking into a broken
     * state, without needing to re-enter the Site ID and API key too.
     * Relies on get_options()'s wp_parse_args() to fill in the rest: storing
     * an option array with only these 3 keys leaves everything else unset,
     * so the next get_options() call fills it back in from $defaults.
     */
    public function handle_reset_request() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'dava-tracking'), '', ['response' => 403]);
        }
        check_admin_referer(self::RESET_NONCE_ACTION);

        $existing = self::get_options();
        update_option(self::OPTION_KEY, [
            'enabled' => $existing['enabled'],
            'site_id' => $existing['site_id'],
            'api_key' => $existing['api_key'],
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => 'connect', 'dava_reset' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Enabled with an incomplete Site ID/API key means the site owner turned
     * tracking on but never finished setup — silently, since nothing else in
     * wp-admin would tell them no events are actually being sent. Shown on
     * every admin screen except the plugin's own settings page (which
     * already shows the connection state in its header).
     */
    public function maybe_render_setup_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if ($screen && strpos($screen->id, self::PAGE_SLUG) !== false) {
            return;
        }

        $options = self::get_options();
        if (empty($options['enabled']) || ($options['site_id'] !== '' && $options['api_key'] !== '')) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Dáva Tracking is enabled but missing a Site ID or API key, so no events are being sent.', 'dava-tracking'),
            esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG)),
            esc_html__('Finish setup', 'dava-tracking')
        );
    }

    /**
     * Returns the stored options merged with defaults. Safe to call from
     * anywhere (listener, event sender, future modules) without re-checking
     * whether the option row exists yet.
     */
    public static function get_options() {
        $defaults = [
            'enabled' => false,
            'site_id' => '',
            'api_key' => '',
            // /v1/events lives on the API host — a DIFFERENT host from the
            // listener script in every real environment, not just a
            // local-testing edge case. Verified directly against production
            // (2026-08-01): api.davazmysel.com (the hostname docs/tracking/
            // platforms/wordpress.mdx documents) 404s — "Route POST:/v1/events
            // not found" — that doc is stale. api-v2.davazmysel.com correctly
            // 401s (route exists, auth required), matching .env.prod's
            // API_HOSTNAME. Flagged for the docs to be corrected separately.
            'api_base_url' => 'https://api-v2.davazmysel.com',
            // t.js is served from the tracking CDN, not the API host. Only
            // override this if you run a first-party proxy (e.g. a
            // Cloudflare Worker on your own subdomain — see "Install the
            // tracking snippet" docs) instead of Dáva's shared CDN.
            'listener_base_url' => 'https://cdn.davazmysel.com',
            // Gates the snippet's own tracking behind a `dava-consent` cookie
            // (apps/snippet/src/index.ts) — the site's cookie-consent banner
            // must set that cookie and call window.dava.checkConsent() when
            // the visitor accepts. Off by default, matching the dashboard's
            // AccountSnippetSection default.
            'require_consent' => false,
            // Off by default — writes full request/response payloads to PHP's
            // error log for every send. Only turn on while actively
            // troubleshooting; the activity log's short message is enough day
            // to day and the request body includes a hashed_email.
            'debug_mode' => false,
            // Comma-separated text matched (case-insensitively, substring)
            // against a form field's name/label/id before falling back to
            // type==="email" auto-detection — for a form with more than one
            // email-type field (e.g. "your email" vs "referrer's email"), or
            // a plain text field being used to collect an email address.
            'email_field_hint' => '',
            // Comma-separated "plugin:id" pairs (e.g. "contact-form-7:5,
            // wpforms:12") — form submissions this plugin skips entirely,
            // for a specific form the site owner doesn't want tracked (a
            // newsletter signup with no conversion value, an internal-only
            // form) without disabling tracking for every form on the site.
            // Scoped by plugin, not just a bare ID, since two different form
            // plugins active on the same site could reuse the same numeric
            // form ID for unrelated forms.
            'excluded_forms' => '',
        ];

        return wp_parse_args(get_option(self::OPTION_KEY, []), $defaults);
    }

    /**
     * Whether a specific form (identified by its plugin slug + form ID, the
     * same values each Contact Form integration puts in properties.form_plugin/
     * properties.form_id) is on the excluded_forms list.
     */
    public static function is_form_excluded($plugin_slug, $form_id) {
        $excluded_setting = self::get_options()['excluded_forms'];
        if ($excluded_setting === '' || $form_id === null || $form_id === '') {
            return false;
        }

        $target = strtolower($plugin_slug . ':' . $form_id);
        foreach (array_map('trim', explode(',', $excluded_setting)) as $entry) {
            if (strtolower($entry) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current visitor has consented to tracking, for callers that
     * already checked get_options()['require_consent'] is on.
     *
     * Prefers the standardized WP Consent API (wp_has_consent(), provided by
     * consent-management plugins like Complianz, CookieYes, Real Cookie
     * Banner) when available — a site running one of those doesn't need to
     * build a custom integration with Dáva's own dava-consent cookie just for
     * this plugin. "statistics" matches how those plugins categorize
     * analytics/attribution tracking; falls back to the cookie only when no
     * WP Consent API provider is active.
     */
    public static function has_visitor_consented() {
        if (function_exists('wp_has_consent')) {
            return wp_has_consent('statistics');
        }

        return isset($_COOKIE['dava-consent']) && $_COOKIE['dava-consent'] === 'true';
    }

    /**
     * Whether a form field's name/label/id matches the configured
     * "email_field_hint" — checked by each Contact Form integration BEFORE
     * its normal type==="email" detection, so a site can pick a specific
     * field among several, or one that isn't typed as "email" at all.
     * Multiple comma-separated hints are OR'd together; an empty hint
     * (nothing configured) never matches anything, which keeps this a no-op
     * for the common case of a form with exactly one email field.
     */
    public static function field_matches_email_hint($identifier) {
        $hint_setting = self::get_options()['email_field_hint'];
        if ($hint_setting === '' || $identifier === null || $identifier === '') {
            return false;
        }

        foreach (array_filter(array_map('trim', explode(',', $hint_setting))) as $hint) {
            if (stripos((string) $identifier, $hint) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which feature modules currently have something to track on this site.
     * Mirrors exactly the class_exists()/function_exists() gates each
     * module's own register_hooks() uses — this list is a report of that
     * same detection, not a separate/parallel check that could drift from it.
     */
    public static function get_tracked_plugins() {
        $form_description = __('Sends confirmed form submissions.', 'dava-tracking');

        return [
            [
                'label' => __('WooCommerce', 'dava-tracking'),
                'description' => __('Sends a conversion for each paid order.', 'dava-tracking'),
                'type' => 'store',
                'detected' => class_exists('WooCommerce'),
            ],
            [
                'label' => __('Contact Form 7', 'dava-tracking'),
                'description' => $form_description,
                'type' => 'form',
                'detected' => class_exists('WPCF7_ContactForm'),
            ],
            [
                'label' => __('WPForms', 'dava-tracking'),
                'description' => $form_description,
                'type' => 'form',
                'detected' => function_exists('wpforms'),
            ],
            [
                'label' => __('Gravity Forms', 'dava-tracking'),
                'description' => $form_description,
                'type' => 'form',
                'detected' => class_exists('GFForms'),
            ],
            [
                'label' => __('Elementor Pro Forms', 'dava-tracking'),
                'description' => $form_description,
                'type' => 'form',
                'detected' => defined('ELEMENTOR_PRO_VERSION'),
            ],
        ];
    }

    public function add_settings_page() {
        add_options_page(
            __('Dáva Tracking', 'dava-tracking'),
            __('Dáva Tracking', 'dava-tracking'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('dava_tracking', self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize_options'],
        ]);
    }

    /**
     * Layout is hand-rolled (see render_settings_page) rather than via
     * add_settings_section/add_settings_field, so the CSS only needs to load
     * on our own screen — never on other wp-admin pages.
     */
    public function enqueue_settings_assets($hook_suffix) {
        if ($hook_suffix !== 'settings_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'dava-tracking-settings',
            DAVA_TRACKING_PLUGIN_URL . 'admin/css/settings.css',
            [],
            DAVA_TRACKING_VERSION
        );

        wp_enqueue_script(
            'dava-tracking-settings',
            DAVA_TRACKING_PLUGIN_URL . 'admin/js/settings.js',
            [],
            DAVA_TRACKING_VERSION,
            true
        );

        wp_localize_script('dava-tracking-settings', 'DavaTrackingSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ajaxAction' => Dava_Tracking_Test_Connection::AJAX_ACTION,
            'sendingLabel' => __('Testing…', 'dava-tracking'),
            'justNowLabel' => __('just now', 'dava-tracking'),
            'networkErrorLabel' => __('Could not reach the server. Please try again.', 'dava-tracking'),
            'successLabel' => __('Success', 'dava-tracking'),
            'errorLabel' => __('Error', 'dava-tracking'),
            'connectedLabel' => __('Connected', 'dava-tracking'),
            'connectedTestLabel' => __('Connected (test key)', 'dava-tracking'),
            'failedLabel' => __('Connection failed', 'dava-tracking'),
            'failedLineLabel' => __('Last check failed just now', 'dava-tracking'),
            'environmentLabel' => __('environment', 'dava-tracking'),
            'unknownLabel' => __('unknown', 'dava-tracking'),
            'verifiedJustNowLabel' => __('verified just now', 'dava-tracking'),
        ]);
    }

    /**
     * The API key field is rendered blank (masked placeholder only) so the
     * real key never round-trips through the browser. A submit with that
     * field left blank must therefore keep the previously stored key
     * instead of overwriting it with an empty string.
     */
    public function sanitize_options($input) {
        $existing = self::get_options();

        return [
            'enabled' => !empty($input['enabled']),
            'site_id' => isset($input['site_id']) ? sanitize_text_field($input['site_id']) : '',
            'api_key' => !empty($input['api_key'])
                ? sanitize_text_field($input['api_key'])
                : $existing['api_key'],
            'api_base_url' => isset($input['api_base_url']) && $input['api_base_url'] !== ''
                ? esc_url_raw(untrailingslashit($input['api_base_url']))
                : $existing['api_base_url'],
            'listener_base_url' => isset($input['listener_base_url']) && $input['listener_base_url'] !== ''
                ? esc_url_raw(untrailingslashit($input['listener_base_url']))
                : $existing['listener_base_url'],
            'require_consent' => !empty($input['require_consent']),
            'debug_mode' => !empty($input['debug_mode']),
            'email_field_hint' => isset($input['email_field_hint']) ? sanitize_text_field($input['email_field_hint']) : '',
            'excluded_forms' => isset($input['excluded_forms']) ? sanitize_text_field($input['excluded_forms']) : '',
        ];
    }

    /**
     * Where each tab slug from before the redesign lives now, so a bookmarked
     * or redirected ?tab=connection|advanced|diagnostics still lands somewhere.
     */
    private static function normalize_tab($requested) {
        $legacy = [
            'connection' => 'connect',
            'advanced' => 'connect',
            'diagnostics' => 'activity',
        ];
        $tab = isset($legacy[$requested]) ? $legacy[$requested] : $requested;

        return in_array($tab, ['connect', 'integrations', 'activity'], true) ? $tab : 'connect';
    }

    /**
     * One state for the header badge:
     * - not_configured: no Site ID or API key saved
     * - failed: the newest connection check failed after the last good one
     * - verified: a connection check passed
     * - unverified: credentials saved, never tested
     *
     * @return array{state: string, since: int}
     */
    private static function get_connection_state($options, $connection_status, $activity_log) {
        if ($options['site_id'] === '' || $options['api_key'] === '') {
            return ['state' => 'not_configured', 'since' => 0];
        }

        $verified_at = $connection_status ? (int) $connection_status['verified_at'] : 0;
        foreach ($activity_log as $entry) {
            if (($entry['event_name'] ?? '') !== 'connection_check') {
                continue;
            }
            if ($entry['status'] === 'error' && (int) $entry['time'] > $verified_at) {
                return ['state' => 'failed', 'since' => (int) $entry['time']];
            }
            break;
        }

        return ['state' => $connection_status ? 'verified' : 'unverified', 'since' => $verified_at];
    }

    /**
     * What the activity tab badge counts: failures from the last 24 hours. An
     * old failure that was fixed since must not keep a red badge on forever.
     */
    private static function count_recent_errors($activity_log) {
        $since = time() - DAY_IN_SECONDS;
        $count = 0;
        foreach ($activity_log as $entry) {
            if ($entry['status'] === 'error' && (int) $entry['time'] >= $since) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A next step for the two failures a site owner can fix themselves. Matched
     * on the API's own message text, which is all the log keeps.
     */
    private static function get_log_hint($message) {
        if (stripos($message, 'subdomain is not registered') !== false) {
            return __('Add this site\'s domain under Domains in Dáva, then send the event again.', 'dava-tracking');
        }
        if (stripos($message, 'invalid or revoked api key') !== false) {
            return __('Create a new API key in Dáva and replace it on the Connect tab.', 'dava-tracking');
        }

        return '';
    }

    /**
     * Everything the page reads from the request, the options and the stored
     * log in one place, so the render methods below only print.
     */
    private function build_view_context() {
        $options = self::get_options();
        $activity_log = Dava_Tracking_Event_Sender::get_log();
        $connection_status = Dava_Tracking_Event_Sender::get_connection_status();
        $tracked_plugins = self::get_tracked_plugins();

        $forms_detected = false;
        foreach ($tracked_plugins as $plugin) {
            if ($plugin['type'] === 'form' && $plugin['detected']) {
                $forms_detected = true;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only controls which tab panel renders
        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connect';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only controls which banner text renders
        $test_result = isset($_GET['dava_test']) ? sanitize_key(wp_unslash($_GET['dava_test'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
        $backfill_mode = isset($_GET['dava_backfill_mode']) ? sanitize_key(wp_unslash($_GET['dava_backfill_mode'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
        $raw_counts = isset($_GET['dava_backfill_counts']) && is_array($_GET['dava_backfill_counts'])
            ? wp_unslash($_GET['dava_backfill_counts'])
            : null;

        return [
            'options' => $options,
            'activity_log' => $activity_log,
            'connection_status' => $connection_status,
            'connection' => self::get_connection_state($options, $connection_status, $activity_log),
            'active_tab' => self::normalize_tab($requested_tab),
            'tracked_plugins' => $tracked_plugins,
            'forms_detected' => $forms_detected,
            'woocommerce_active' => class_exists('WooCommerce'),
            'test_result' => $test_result,
            'backfill_mode' => $backfill_mode,
            'backfill_counts' => $raw_counts === null ? null : array_map('absint', $raw_counts),
            'retry_queue' => get_option(Dava_Tracking_Event_Sender::RETRY_OPTION_KEY, []),
            'retry_next_run' => wp_next_scheduled(Dava_Tracking_Event_Sender::RETRY_HOOK),
            'error_count' => self::count_recent_errors($activity_log),
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
            'reset_done' => isset($_GET['dava_reset']),
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, options.php already verified the nonce before redirecting here
            'settings_saved' => isset($_GET['settings-updated']),
        ];
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $ctx = $this->build_view_context();
        ?>
        <div class="wrap dava-tracking-wrap">
            <?php $this->render_header($ctx); ?>
            <hr class="wp-header-end" />
            <?php $this->render_tabs($ctx); ?>

            <?php
            // Connect and Integrations share ONE <form>: options.php replaces
            // the whole option on every save, so each save must carry every
            // field, whichever tab the user was on. Only visibility changes
            // per tab, never the markup. The buttons that post elsewhere (test
            // connection, resend orders) sit inside it but name their own form
            // through the form="" attribute, since forms cannot nest.
            ?>
            <form action="options.php" method="post" id="dava-tracking-settings-form">
                <?php settings_fields('dava_tracking'); ?>
                <?php $this->render_connect_panel($ctx); ?>
                <?php $this->render_integrations_panel($ctx); ?>
                <?php $this->render_savebar($ctx); ?>
            </form>

            <?php $this->render_activity_panel($ctx); ?>
            <?php $this->render_action_forms($ctx); ?>
        </div>
        <?php
    }

    private function render_header($ctx) {
        $state = $ctx['connection']['state'];
        $status = $ctx['connection_status'];
        $since = $ctx['connection']['since'];

        $chip_class = '';
        $chip_label = __('Not connected', 'dava-tracking');
        $line = __('Add your Site ID and API key', 'dava-tracking');

        if ($state === 'unverified') {
            $chip_label = __('Not verified', 'dava-tracking');
            $line = __('Run Test connection to check the key', 'dava-tracking');
        } elseif ($state === 'failed') {
            $chip_class = 'is-err';
            $chip_label = __('Connection failed', 'dava-tracking');
            /* translators: %s: human-readable time since the failed check */
            $line = sprintf(__('Last check failed %s ago', 'dava-tracking'), human_time_diff($since));
        } elseif ($state === 'verified') {
            $is_test = $status['environment'] === 'test';
            $chip_class = $is_test ? 'is-warn' : 'is-ok';
            $chip_label = $is_test ? __('Connected (test key)', 'dava-tracking') : __('Connected', 'dava-tracking');
            $line = sprintf(
                /* translators: 1: Dáva API key name, 2: environment (e.g. "test" or "live"), 3: human-readable time since last verified */
                __('%1$s · %2$s environment · verified %3$s ago', 'dava-tracking'),
                $status['key_name'],
                $status['environment'] ?: __('unknown', 'dava-tracking'),
                human_time_diff($status['verified_at'])
            );
        }
        ?>
        <header class="dava-tracking-header">
            <div>
                <h1 class="dava-tracking-brand">
                    <img
                        class="dava-tracking-logo"
                        src="<?php echo esc_url(DAVA_TRACKING_PLUGIN_URL . 'admin/img/logo.svg'); ?>"
                        alt="<?php echo esc_attr__('Dáva Zmysel', 'dava-tracking'); ?>"
                        width="238"
                        height="33"
                    />
                    <span class="screen-reader-text"><?php echo esc_html__('Dáva Tracking', 'dava-tracking'); ?></span>
                </h1>
                <p class="dava-tracking-subtitle"><?php echo esc_html__('Browser listener and confirmed server-side events.', 'dava-tracking'); ?></p>
            </div>
            <div class="dava-tracking-header-right">
                <div class="dava-tracking-status" aria-live="polite">
                    <span id="dava-tracking-status-chip" class="dava-tracking-chip <?php echo esc_attr($chip_class); ?>"><?php echo esc_html($chip_label); ?></span>
                    <span id="dava-tracking-status-line" class="dava-tracking-status-line"><?php echo esc_html($line); ?></span>
                </div>
                <a href="<?php echo esc_url(DAVA_TRACKING_DASHBOARD_URL); ?>" class="dava-tracking-btn" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html__('Open Dáva dashboard', 'dava-tracking'); ?> &#8599;
                </a>
            </div>
        </header>
        <?php
    }

    private function render_tabs($ctx) {
        $tabs = [
            'connect' => __('Connect', 'dava-tracking'),
            'integrations' => __('Integrations', 'dava-tracking'),
            'activity' => __('Activity', 'dava-tracking'),
        ];
        ?>
        <nav class="dava-tracking-tabs" aria-label="<?php echo esc_attr__('Settings sections', 'dava-tracking'); ?>">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a
                    href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $slug], admin_url('options-general.php'))); ?>"
                    class="dava-tracking-tab<?php echo $ctx['active_tab'] === $slug ? ' is-active' : ''; ?>"
                    data-tab="<?php echo esc_attr($slug); ?>"
                    <?php echo $ctx['active_tab'] === $slug ? 'aria-current="page"' : ''; ?>
                >
                    <?php echo esc_html($label); ?>
                    <?php if ($slug === 'activity' && $ctx['error_count'] > 0) : ?>
                        <span class="dava-tracking-count" aria-label="<?php echo esc_attr(sprintf(
                            /* translators: %d: number of failed events in the last 24 hours */
                            _n('%d failed event in the last 24 hours', '%d failed events in the last 24 hours', $ctx['error_count'], 'dava-tracking'),
                            $ctx['error_count']
                        )); ?>"><?php echo esc_html((string) $ctx['error_count']); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * The text next to the Test connection button before any click: the
     * outcome of the last check, or what to do first.
     *
     * @return array{0: string, 1: string} CSS modifier and message.
     */
    private function get_test_result_text($ctx) {
        // No-JS fallback only: admin-post.php redirects back with ?dava_test.
        // With JS the page never reloads, so this never appears.
        $last_error = '';
        foreach ($ctx['activity_log'] as $entry) {
            if (($entry['event_name'] ?? '') === 'connection_check' && $entry['status'] === 'error') {
                $last_error = $entry['message'];
                break;
            }
        }

        if ($ctx['test_result'] === 'success') {
            return ['is-ok', __('Connection successful.', 'dava-tracking')];
        }
        if ($ctx['test_result'] === 'error' || $ctx['connection']['state'] === 'failed') {
            return ['is-err', $last_error !== ''
                ? sprintf(__('Connection failed: %s', 'dava-tracking'), $last_error)
                : __('Connection failed. See Recent activity on the Activity tab.', 'dava-tracking')];
        }
        if ($ctx['connection']['state'] === 'verified') {
            return ['is-ok', sprintf(
                /* translators: 1: Dáva API key name, 2: environment, 3: human-readable time since last verified */
                __('Connected to %1$s (%2$s). Verified %3$s ago.', 'dava-tracking'),
                $ctx['connection_status']['key_name'],
                $ctx['connection_status']['environment'] ?: __('unknown', 'dava-tracking'),
                human_time_diff($ctx['connection_status']['verified_at'])
            )];
        }
        if ($ctx['connection']['state'] === 'unverified') {
            return ['', __('Not tested yet.', 'dava-tracking')];
        }

        return ['', __('Save your Site ID and API key, then test the connection.', 'dava-tracking')];
    }

    private function render_connect_panel($ctx) {
        $options = $ctx['options'];
        $has_credentials = $ctx['connection']['state'] !== 'not_configured';
        $key_help = $options['api_key'] !== ''
            ? sprintf(
                /* translators: %s: the last 4 characters of the saved API key */
                __('The saved key ends in %s. Leave this blank to keep it.', 'dava-tracking'),
                substr($options['api_key'], -4)
            )
            : __('Paste an API key from your Dáva workspace.', 'dava-tracking');
        $key_placeholder = $options['api_key'] !== ''
            ? str_repeat('•', 8) . substr($options['api_key'], -4)
            : __('dava_sk_...', 'dava-tracking');
        list($result_class, $result_text) = $this->get_test_result_text($ctx);
        ?>
        <section id="dava-tracking-panel-connect" class="dava-tracking-panel<?php echo $ctx['active_tab'] === 'connect' ? ' is-active' : ''; ?>">
            <div class="dava-tracking-card">
                <div class="dava-tracking-card-head">
                    <h2 class="dava-tracking-card-title"><?php echo esc_html__('Workspace connection', 'dava-tracking'); ?></h2>
                    <p class="dava-tracking-card-desc"><?php echo esc_html__('Copy the Site ID and an API key from your Dáva workspace. The plugin uses them to load the browser listener and to send confirmed events.', 'dava-tracking'); ?></p>
                </div>
                <div class="dava-tracking-card-body">
                    <div class="dava-tracking-grid">
                        <div class="dava-tracking-field">
                            <label for="dava-site-id"><?php echo esc_html__('Site ID', 'dava-tracking'); ?></label>
                            <input
                                id="dava-site-id"
                                class="is-mono"
                                type="text"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[site_id]"
                                value="<?php echo esc_attr($options['site_id']); ?>"
                                placeholder="acct_..."
                                autocomplete="off"
                            />
                        </div>
                        <div class="dava-tracking-field">
                            <label for="dava-api-key"><?php echo esc_html__('API key', 'dava-tracking'); ?></label>
                            <?php
                            // Blank on purpose: the real key never round-trips
                            // through the browser, and sanitize_options() keeps
                            // the stored key when this arrives empty.
                            ?>
                            <input
                                id="dava-api-key"
                                class="is-mono"
                                type="password"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]"
                                value=""
                                placeholder="<?php echo esc_attr($key_placeholder); ?>"
                                autocomplete="off"
                            />
                            <p class="dava-tracking-help"><?php echo esc_html($key_help); ?></p>
                        </div>
                        <div class="dava-tracking-field is-full">
                            <label for="dava-api-base-url"><?php echo esc_html__('API base URL', 'dava-tracking'); ?></label>
                            <input
                                id="dava-api-base-url"
                                type="url"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_base_url]"
                                value="<?php echo esc_attr($options['api_base_url']); ?>"
                            />
                            <p class="dava-tracking-help"><?php echo esc_html__('Where confirmed events are sent. This is not the host the browser listener loads from (see Advanced below).', 'dava-tracking'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="dava-tracking-card-foot">
                    <button
                        type="submit"
                        form="dava-tracking-test-form"
                        id="dava-tracking-test-button"
                        class="dava-tracking-btn"
                        <?php echo $has_credentials ? '' : 'disabled'; ?>
                    ><?php echo esc_html__('Test connection', 'dava-tracking'); ?></button>
                    <span id="dava-tracking-test-result" class="dava-tracking-result <?php echo esc_attr($result_class); ?>" aria-live="polite"><?php echo esc_html($result_text); ?></span>
                    <span id="dava-tracking-test-note" class="dava-tracking-result" hidden><?php echo esc_html__('Save your changes first. The test uses the saved Site ID, API key and API base URL.', 'dava-tracking'); ?></span>
                </div>
            </div>

            <div class="dava-tracking-card">
                <div class="dava-tracking-card-head">
                    <h2 class="dava-tracking-card-title"><?php echo esc_html__('Tracking', 'dava-tracking'); ?></h2>
                </div>
                <div class="dava-tracking-card-body">
                    <div class="dava-tracking-switch-row">
                        <div>
                            <div class="dava-tracking-switch-title" id="dava-label-enabled"><?php echo esc_html__('Load the browser listener on every page', 'dava-tracking'); ?></div>
                            <p class="dava-tracking-switch-desc"><?php echo esc_html__('Adds the Dáva script to the head of every page. Needed for sessions and journeys.', 'dava-tracking'); ?></p>
                        </div>
                        <label class="dava-tracking-switch">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" aria-labelledby="dava-label-enabled" <?php checked($options['enabled'], true); ?> />
                            <span></span>
                        </label>
                    </div>
                    <div class="dava-tracking-switch-row">
                        <div>
                            <div class="dava-tracking-switch-title" id="dava-label-consent"><?php echo esc_html__('Require cookie consent before tracking', 'dava-tracking'); ?></div>
                            <p class="dava-tracking-switch-desc"><?php echo esc_html__('Tracking starts only after the visitor accepts.', 'dava-tracking'); ?></p>
                            <details class="dava-tracking-more">
                                <summary><?php echo esc_html__('How consent works', 'dava-tracking'); ?></summary>
                                <p>
                                    <?php
                                    echo wp_kses(
                                        sprintf(
                                            /* translators: 1: dava-consent as inline code, 2: "true" as inline code, 3: window.dava.checkConsent() as inline code */
                                            __('Tracking waits for a %1$s cookie set to %2$s. Your cookie banner must set that cookie and call %3$s when the visitor accepts. Dáva does not connect to any consent platform automatically.', 'dava-tracking'),
                                            '<code>dava-consent</code>',
                                            '<code>true</code>',
                                            '<code>window.dava.checkConsent()</code>'
                                        ),
                                        ['code' => []]
                                    );
                                    ?>
                                </p>
                            </details>
                        </div>
                        <label class="dava-tracking-switch">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_consent]" value="1" aria-labelledby="dava-label-consent" <?php checked($options['require_consent'], true); ?> />
                            <span></span>
                        </label>
                    </div>
                </div>
            </div>

            <?php $this->render_advanced_card($ctx); ?>
        </section>
        <?php
    }

    private function render_advanced_card($ctx) {
        $options = $ctx['options'];
        ?>
        <details class="dava-tracking-card" id="dava-tracking-advanced" <?php echo $ctx['reset_done'] ? 'open' : ''; ?>>
            <summary>
                <span>
                    <span class="dava-tracking-card-title"><?php echo esc_html__('Advanced', 'dava-tracking'); ?></span>
                    <span class="dava-tracking-card-desc"><?php echo esc_html__('Listener host, debug logging and reset. Most sites never change these.', 'dava-tracking'); ?></span>
                </span>
            </summary>
            <div class="dava-tracking-card-body">
                <div class="dava-tracking-field">
                    <label for="dava-listener-base-url"><?php echo esc_html__('Browser listener URL', 'dava-tracking'); ?></label>
                    <input
                        id="dava-listener-base-url"
                        type="url"
                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[listener_base_url]"
                        value="<?php echo esc_attr($options['listener_base_url']); ?>"
                    />
                    <p class="dava-tracking-help"><?php echo esc_html__('Where the tracking script (t.js) loads from. It is a different host from the API base URL. Change it only if you run a first-party proxy, for example a Cloudflare Worker on your own subdomain.', 'dava-tracking'); ?></p>
                </div>

                <div class="dava-tracking-switch-row dava-tracking-divider">
                    <div>
                        <div class="dava-tracking-switch-title" id="dava-label-debug"><?php echo esc_html__('Debug mode', 'dava-tracking'); ?></div>
                        <p class="dava-tracking-switch-desc"><?php echo esc_html__('Writes the full request and response of every send to the PHP error log. The request contains a hashed email, so turn it on only while troubleshooting.', 'dava-tracking'); ?></p>
                    </div>
                    <label class="dava-tracking-switch">
                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[debug_mode]" value="1" aria-labelledby="dava-label-debug" <?php checked($options['debug_mode'], true); ?> />
                        <span></span>
                    </label>
                </div>

                <div class="dava-tracking-reset">
                    <div>
                        <strong><?php echo esc_html__('Reset advanced settings', 'dava-tracking'); ?></strong>
                        <p>
                            <?php echo esc_html__('Restores the API base URL, listener URL, consent, debug mode, email field hint and excluded forms to their defaults. Site ID and API key are kept.', 'dava-tracking'); ?>
                            <?php if ($ctx['reset_done']) : ?>
                                <strong><?php echo esc_html__('Settings were reset to defaults.', 'dava-tracking'); ?></strong>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a
                        href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::RESET_ACTION), self::RESET_NONCE_ACTION)); ?>"
                        class="dava-tracking-btn is-small"
                        onclick="return confirm('<?php echo esc_js(__('Reset Advanced settings back to defaults? Site ID and API key are kept.', 'dava-tracking')); ?>');"
                    ><?php echo esc_html__('Reset to defaults', 'dava-tracking'); ?></a>
                </div>
            </div>
        </details>
        <?php
    }

    private function render_integrations_panel($ctx) {
        ?>
        <section id="dava-tracking-panel-integrations" class="dava-tracking-panel<?php echo $ctx['active_tab'] === 'integrations' ? ' is-active' : ''; ?>">
            <div class="dava-tracking-card">
                <div class="dava-tracking-card-head">
                    <h2 class="dava-tracking-card-title"><?php echo esc_html__('Detected plugins', 'dava-tracking'); ?></h2>
                    <p class="dava-tracking-card-desc"><?php echo esc_html__('Dáva sends confirmed events automatically for the plugins below when they are active on this site.', 'dava-tracking'); ?></p>
                </div>
                <ul class="dava-tracking-plugin-list">
                    <?php foreach ($ctx['tracked_plugins'] as $plugin) : ?>
                        <li class="<?php echo $plugin['detected'] ? 'is-detected' : ''; ?>">
                            <span class="dava-tracking-plugin-icon" aria-hidden="true"><?php echo esc_html(mb_substr($plugin['label'], 0, 1)); ?></span>
                            <span class="dava-tracking-plugin-text">
                                <span class="dava-tracking-plugin-name"><?php echo esc_html($plugin['label']); ?></span><br />
                                <span class="dava-tracking-plugin-desc"><?php echo esc_html($plugin['description']); ?></span>
                            </span>
                            <span class="dava-tracking-chip <?php echo $plugin['detected'] ? 'is-ok' : ''; ?>">
                                <?php echo $plugin['detected'] ? esc_html__('Detected', 'dava-tracking') : esc_html__('Not installed', 'dava-tracking'); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($ctx['woocommerce_active']) : ?>
                <?php $this->render_woocommerce_card($ctx); ?>
            <?php endif; ?>

            <?php $this->render_forms_card($ctx); ?>
        </section>
        <?php
    }

    private function render_woocommerce_card($ctx) {
        ?>
        <div class="dava-tracking-card">
            <div class="dava-tracking-card-head">
                <h2 class="dava-tracking-card-title"><?php echo esc_html__('WooCommerce orders', 'dava-tracking'); ?></h2>
                <p class="dava-tracking-card-desc"><?php echo esc_html__('How orders reach Dáva.', 'dava-tracking'); ?></p>
            </div>
            <div class="dava-tracking-card-body">
                <ul class="dava-tracking-rules">
                    <li><?php echo esc_html__('An order is sent when it reaches processing, completed or on-hold.', 'dava-tracking'); ?></li>
                    <li><?php echo esc_html__('An order with no billing email, or with a total of 0, is skipped.', 'dava-tracking'); ?></li>
                    <li><?php echo esc_html__('With the browser listener on, the sale is linked to the visitor\'s session.', 'dava-tracking'); ?></li>
                </ul>

                <div class="dava-tracking-divider dava-tracking-backfill">
                    <div>
                        <strong><?php echo esc_html__('Resend past orders', 'dava-tracking'); ?></strong>
                        <p><?php echo esc_html__('Sends paid orders from the last 30 days that never reached Dáva, for example after enabling tracking on a site that already had orders. Orders already sent are skipped. Preview shows what would be sent without sending anything.', 'dava-tracking'); ?></p>
                    </div>
                    <div class="dava-tracking-backfill-actions">
                        <button type="submit" name="dry_run" value="1" form="dava-tracking-backfill-form" class="dava-tracking-btn"><?php echo esc_html__('Preview', 'dava-tracking'); ?></button>
                        <button type="submit" form="dava-tracking-backfill-form" class="dava-tracking-btn"><?php echo esc_html__('Resend last 30 days', 'dava-tracking'); ?></button>
                    </div>
                </div>

                <?php $this->render_backfill_result($ctx); ?>
            </div>
        </div>
        <?php
    }

    private function render_backfill_result($ctx) {
        $counts = $ctx['backfill_counts'];
        if ($counts === null) {
            return;
        }

        $is_preview = $ctx['backfill_mode'] === 'preview';
        // Which classifications to name, in order. The first two always show,
        // the rest only when something landed in them.
        $labels = [
            'would_send' => __('would be sent', 'dava-tracking'),
            'sent' => __('sent', 'dava-tracking'),
            'already_sent' => __('already sent', 'dava-tracking'),
            'no_value' => __('skipped, total is 0', 'dava-tracking'),
            'no_email' => __('skipped, no billing email', 'dava-tracking'),
            'no_consent' => __('skipped, no consent', 'dava-tracking'),
            'failed' => __('failed', 'dava-tracking'),
            'locked' => __('in progress elsewhere', 'dava-tracking'),
        ];
        $always = $is_preview ? 'would_send' : 'sent';
        ?>
        <div class="dava-tracking-preview">
            <span class="dava-tracking-preview-title">
                <?php echo $is_preview
                    ? esc_html__('Preview of the last 30 days. Nothing was sent.', 'dava-tracking')
                    : esc_html__('Result for the last 30 days', 'dava-tracking'); ?>
            </span>
            <?php if (array_sum($counts) === 0) : ?>
                <div class="dava-tracking-preview-nums"><span><?php echo esc_html__('No paid orders in the last 30 days.', 'dava-tracking'); ?></span></div>
            <?php else : ?>
                <div class="dava-tracking-preview-nums">
                    <?php foreach ($labels as $key => $label) : ?>
                        <?php if (isset($counts[$key]) && ($key === $always || $counts[$key] > 0)) : ?>
                            <span><strong><?php echo esc_html((string) $counts[$key]); ?></strong><?php echo esc_html($label); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The form-plugin options only matter when one of those plugins is active,
     * so the card is hidden otherwise. The two values still have to be in the
     * POST: options.php replaces the whole option, and a missing field would
     * wipe what the site had saved. Hidden inputs carry them.
     */
    private function render_forms_card($ctx) {
        $options = $ctx['options'];

        if (!$ctx['forms_detected']) {
            ?>
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_field_hint]" value="<?php echo esc_attr($options['email_field_hint']); ?>" />
            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[excluded_forms]" value="<?php echo esc_attr($options['excluded_forms']); ?>" />
            <?php
            return;
        }
        ?>
        <div class="dava-tracking-card">
            <div class="dava-tracking-card-head">
                <h2 class="dava-tracking-card-title"><?php echo esc_html__('Forms', 'dava-tracking'); ?></h2>
                <p class="dava-tracking-card-desc"><?php echo esc_html__('Applies to Contact Form 7, WPForms, Gravity Forms and Elementor Pro Forms.', 'dava-tracking'); ?></p>
            </div>
            <div class="dava-tracking-card-body">
                <div class="dava-tracking-grid">
                    <div class="dava-tracking-field">
                        <label for="dava-email-field-hint"><?php echo esc_html__('Email field hint', 'dava-tracking'); ?></label>
                        <input
                            id="dava-email-field-hint"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_field_hint]"
                            value="<?php echo esc_attr($options['email_field_hint']); ?>"
                            placeholder="<?php echo esc_attr__('e.g. customer-email, correo', 'dava-tracking'); ?>"
                        />
                        <p class="dava-tracking-help"><?php echo esc_html__('Optional, comma-separated. Use it when a form has more than one email field, or the right one is not typed as email. Text that matches a field\'s name, label or ID is checked first.', 'dava-tracking'); ?></p>
                    </div>
                    <div class="dava-tracking-field">
                        <label for="dava-excluded-forms"><?php echo esc_html__('Excluded forms', 'dava-tracking'); ?></label>
                        <input
                            id="dava-excluded-forms"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[excluded_forms]"
                            value="<?php echo esc_attr($options['excluded_forms']); ?>"
                            placeholder="<?php echo esc_attr__('e.g. contact-form-7:5, wpforms:12', 'dava-tracking'); ?>"
                        />
                        <p class="dava-tracking-help">
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: 1: plugin:id as inline code, 2: the 4 valid plugin slug values, as inline code */
                                    __('Optional %1$s pairs to skip, for example a newsletter signup. Find a form\'s ID in its own plugin. Plugins: %2$s.', 'dava-tracking'),
                                    '<code>plugin:id</code>',
                                    '<code>contact-form-7</code>, <code>wpforms</code>, <code>gravity-forms</code>, <code>elementor-pro</code>'
                                ),
                                ['code' => []]
                            );
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_savebar($ctx) {
        ?>
        <div id="dava-tracking-savebar" class="dava-tracking-savebar" <?php echo $ctx['active_tab'] === 'activity' ? 'hidden' : ''; ?>>
            <span id="dava-tracking-savebar-msg" class="dava-tracking-savebar-msg" data-idle="<?php echo esc_attr__('Changes apply to the whole site once saved.', 'dava-tracking'); ?>" data-dirty="<?php echo esc_attr__('You have unsaved changes.', 'dava-tracking'); ?>">
                <?php echo $ctx['settings_saved']
                    ? esc_html__('Settings saved.', 'dava-tracking')
                    : esc_html__('Changes apply to the whole site once saved.', 'dava-tracking'); ?>
            </span>
            <button type="submit" class="dava-tracking-btn is-primary"><?php echo esc_html__('Save changes', 'dava-tracking'); ?></button>
        </div>
        <?php
    }

    private function render_activity_panel($ctx) {
        $retry_queue = $ctx['retry_queue'];
        $log = $ctx['activity_log'];
        ?>
        <section id="dava-tracking-panel-activity" class="dava-tracking-panel<?php echo $ctx['active_tab'] === 'activity' ? ' is-active' : ''; ?>">
            <div class="dava-tracking-card">
                <div class="dava-tracking-card-head is-row">
                    <div>
                        <h2 class="dava-tracking-card-title"><?php echo esc_html__('Retry queue', 'dava-tracking'); ?></h2>
                        <p class="dava-tracking-card-desc">
                            <?php if (empty($retry_queue)) : ?>
                                <?php echo esc_html__('Events that could not be delivered because of a network or server error wait here and are retried. Nothing is waiting.', 'dava-tracking'); ?>
                            <?php elseif ($ctx['retry_next_run']) : ?>
                                <?php
                                printf(
                                    /* translators: 1: number of events pending retry, 2: human-readable time until the next retry */
                                    esc_html(_n(
                                        '%1$d event pending retry, next attempt in %2$s.',
                                        '%1$d events pending retry, next attempt in %2$s.',
                                        count($retry_queue),
                                        'dava-tracking'
                                    )),
                                    count($retry_queue),
                                    esc_html(human_time_diff($ctx['retry_next_run']))
                                );
                                ?>
                            <?php else : ?>
                                <?php
                                // No scheduled run to name (WP-Cron off or not yet registered).
                                printf(
                                    /* translators: %d: number of events pending retry */
                                    esc_html(_n('%d event pending retry.', '%d events pending retry.', count($retry_queue), 'dava-tracking')),
                                    count($retry_queue)
                                );
                                ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <span class="dava-tracking-chip <?php echo empty($retry_queue) ? 'is-ok' : 'is-warn'; ?>">
                        <?php echo empty($retry_queue) ? esc_html__('Empty', 'dava-tracking') : esc_html(sprintf(
                            /* translators: %d: number of events waiting */
                            _n('%d waiting', '%d waiting', count($retry_queue), 'dava-tracking'),
                            count($retry_queue)
                        )); ?>
                    </span>
                </div>
            </div>

            <div class="dava-tracking-card">
                <div class="dava-tracking-card-head is-row">
                    <h2 class="dava-tracking-card-title"><?php echo esc_html__('Recent activity', 'dava-tracking'); ?></h2>
                    <div class="dava-tracking-seg" id="dava-tracking-log-filter">
                        <button type="button" data-filter="all" aria-pressed="true"><?php echo esc_html__('All', 'dava-tracking'); ?></button>
                        <button type="button" data-filter="error" aria-pressed="false"><?php echo esc_html__('Errors', 'dava-tracking'); ?></button>
                    </div>
                </div>
                <div id="dava-tracking-log-empty" class="dava-tracking-empty" <?php echo empty($log) ? '' : 'hidden'; ?>>
                    <strong><?php echo esc_html__('No activity yet', 'dava-tracking'); ?></strong>
                    <?php echo esc_html__('Events appear here after the first order or form submission, or after you test the connection.', 'dava-tracking'); ?>
                </div>
                <div class="dava-tracking-log-wrap" <?php echo empty($log) ? 'hidden' : ''; ?>>
                    <table class="dava-tracking-log">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Time', 'dava-tracking'); ?></th>
                                <th><?php echo esc_html__('Event', 'dava-tracking'); ?></th>
                                <th><?php echo esc_html__('Status', 'dava-tracking'); ?></th>
                                <th><?php echo esc_html__('Detail', 'dava-tracking'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="dava-tracking-log-body">
                            <?php foreach ($log as $entry) : ?>
                                <?php $hint = $entry['status'] === 'error' ? self::get_log_hint($entry['message']) : ''; ?>
                                <tr data-status="<?php echo esc_attr($entry['status']); ?>">
                                    <td class="dava-tracking-log-time"><?php echo esc_html(human_time_diff($entry['time']) . ' ' . __('ago', 'dava-tracking')); ?></td>
                                    <td><code><?php echo esc_html($entry['event_name']); ?></code></td>
                                    <td>
                                        <span class="dava-tracking-chip <?php echo $entry['status'] === 'error' ? 'is-err' : 'is-ok'; ?>">
                                            <?php echo $entry['status'] === 'error' ? esc_html__('Error', 'dava-tracking') : esc_html__('Success', 'dava-tracking'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo esc_html($entry['message']); ?>
                                        <?php if ($hint !== '') : ?>
                                            <span class="dava-tracking-log-hint"><?php echo esc_html($hint); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * The two forms the buttons in the settings form post to. They live here,
     * outside it, because forms cannot nest.
     */
    private function render_action_forms($ctx) {
        ?>
        <form id="dava-tracking-test-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="<?php echo esc_attr(Dava_Tracking_Test_Connection::ACTION); ?>" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(Dava_Tracking_Test_Connection::NONCE_ACTION)); ?>" />
        </form>
        <?php if ($ctx['woocommerce_active']) : ?>
            <form id="dava-tracking-backfill-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="<?php echo esc_attr(Dava_Tracking_WooCommerce::BACKFILL_ACTION); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce(Dava_Tracking_WooCommerce::BACKFILL_NONCE_ACTION)); ?>" />
            </form>
        <?php endif; ?>
        <?php
    }
}
