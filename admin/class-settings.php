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
            ['page' => self::PAGE_SLUG, 'tab' => 'advanced', 'dava_reset' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Enabled with an incomplete Site ID/API key means the site owner turned
     * tracking on but never finished setup — silently, since nothing else in
     * wp-admin would tell them no events are actually being sent. Shown on
     * every admin screen except the plugin's own settings page (which
     * already communicates connection state via the Diagnostics tab).
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
        return [
            [
                'label' => __('WooCommerce', 'dava-tracking'),
                'detected' => class_exists('WooCommerce'),
            ],
            [
                'label' => __('Contact Form 7', 'dava-tracking'),
                'detected' => class_exists('WPCF7_ContactForm'),
            ],
            [
                'label' => __('WPForms', 'dava-tracking'),
                'detected' => function_exists('wpforms'),
            ],
            [
                'label' => __('Gravity Forms', 'dava-tracking'),
                'detected' => class_exists('GFForms'),
            ],
            [
                'label' => __('Elementor Pro Forms', 'dava-tracking'),
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
            'sendingLabel' => __('Sending…', 'dava-tracking'),
            'justNowLabel' => __('just now', 'dava-tracking'),
            'networkErrorLabel' => __('Could not reach the server. Please try again.', 'dava-tracking'),
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

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = self::get_options();
        $is_connected = !empty($options['enabled']) && $options['site_id'] !== '' && $options['api_key'] !== '';
        $api_key_placeholder = $options['api_key'] !== ''
            ? str_repeat('•', 8) . substr($options['api_key'], -4)
            : __('dava_sk_...', 'dava-tracking');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only controls which banner text renders
        $test_result = isset($_GET['dava_test']) ? sanitize_key(wp_unslash($_GET['dava_test'])) : '';
        $activity_log = Dava_Tracking_Event_Sender::get_log();
        $connection_status = Dava_Tracking_Event_Sender::get_connection_status();
        $retry_queue = get_option(Dava_Tracking_Event_Sender::RETRY_OPTION_KEY, []);
        $retry_next_run = wp_next_scheduled(Dava_Tracking_Event_Sender::RETRY_HOOK);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
        $backfill_sent = isset($_GET['dava_backfill_sent']) ? absint($_GET['dava_backfill_sent']) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
        $backfill_skipped = isset($_GET['dava_backfill_skipped']) ? absint($_GET['dava_backfill_skipped']) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
        $backfill_mode = isset($_GET['dava_backfill_mode']) ? sanitize_key($_GET['dava_backfill_mode']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, admin_post already verified the nonce before redirecting here
        $reset_done = isset($_GET['dava_reset']);

        // No-JS fallback only — the JS path (settings.js) never triggers a
        // page reload, so ?dava_test never appears while JS is active. Lives
        // next to the Test Connection button (Diagnostics tab), not at the
        // top of the page, since that's where the action that produced it is.
        $fallback_banner_class = '';
        $fallback_banner_message = '';
        if ($test_result === 'success') {
            $fallback_banner_class = 'is-success';
            $fallback_banner_message = __('Connection successful.', 'dava-tracking');
        } elseif ($test_result === 'error') {
            $fallback_banner_class = 'is-error';
            $last_error = '';
            foreach ($activity_log as $entry) {
                if ($entry['status'] === 'error') {
                    $last_error = $entry['message'];
                    break;
                }
            }
            $fallback_banner_message = $last_error !== ''
                ? sprintf(__('Connection failed: %s', 'dava-tracking'), $last_error)
                : __('Connection failed. See Recent activity below for details.', 'dava-tracking');
        }

        $tabs = [
            'connection' => __('Connection', 'dava-tracking'),
            'advanced' => __('Advanced', 'dava-tracking'),
            'diagnostics' => __('Diagnostics', 'dava-tracking'),
        ];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, only controls which tab panel renders
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
        if (!isset($tabs[$active_tab])) {
            $active_tab = 'connection';
        }

        $tracked_plugins = self::get_tracked_plugins();
        ?>
        <div class="wrap dava-tracking-wrap">
            <div class="dava-tracking-header">
                <div class="dava-tracking-brand">
                    <img
                        class="dava-tracking-logo"
                        src="<?php echo esc_url(DAVA_TRACKING_PLUGIN_URL . 'admin/img/logo.svg'); ?>"
                        alt="<?php echo esc_attr__('Dáva', 'dava-tracking'); ?>"
                        width="238"
                        height="33"
                    />
                    <p class="dava-tracking-subtitle">
                        <?php echo esc_html__('Browser listener install and confirmed server-side events.', 'dava-tracking'); ?>
                    </p>
                </div>
            </div>

            <h2 class="nav-tab-wrapper dava-tracking-tabs">
                <?php foreach ($tabs as $tab_slug => $tab_label) : ?>
                    <a
                        href="<?php echo esc_url(add_query_arg(['page' => self::PAGE_SLUG, 'tab' => $tab_slug], admin_url('options-general.php'))); ?>"
                        class="nav-tab<?php echo $active_tab === $tab_slug ? ' nav-tab-active' : ''; ?>"
                    ><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </h2>

            <div class="dava-tracking-body">
            <div class="dava-tracking-main">

            <?php
            // Connection and Advanced share ONE <form> (both always submit
            // together) so saving from either tab never wipes the other
            // tab's fields — only visibility is toggled per tab, not markup.
            ?>
            <form action="options.php" method="post">
                <?php settings_fields('dava_tracking'); ?>

                <div class="dava-tracking-tab-panel<?php echo $active_tab === 'connection' ? ' is-active' : ''; ?>">
                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Connection', 'dava-tracking'); ?></h2>

                    <div class="dava-tracking-field">
                        <label class="dava-tracking-toggle">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked($options['enabled'], true); ?> />
                            <span><?php echo esc_html__('Inject the Dáva browser listener on every page', 'dava-tracking'); ?></span>
                        </label>
                    </div>

                    <div class="dava-tracking-field">
                        <label for="dava-site-id"><?php echo esc_html__('Site ID', 'dava-tracking'); ?></label>
                        <input
                            id="dava-site-id"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[site_id]"
                            value="<?php echo esc_attr($options['site_id']); ?>"
                            placeholder="acct_..."
                        />
                    </div>

                    <div class="dava-tracking-field">
                        <label for="dava-api-key"><?php echo esc_html__('API key', 'dava-tracking'); ?></label>
                        <input
                            id="dava-api-key"
                            type="password"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]"
                            value=""
                            placeholder="<?php echo esc_attr($api_key_placeholder); ?>"
                            autocomplete="off"
                        />
                        <p class="description"><?php echo esc_html__('Leave blank to keep the currently saved key.', 'dava-tracking'); ?></p>
                    </div>

                    <div class="dava-tracking-field">
                        <label for="dava-api-base-url"><?php echo esc_html__('API base URL', 'dava-tracking'); ?></label>
                        <input
                            id="dava-api-base-url"
                            type="url"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_base_url]"
                            value="<?php echo esc_attr($options['api_base_url']); ?>"
                        />
                        <p class="description"><?php echo esc_html__('Used for server-side event delivery (POST /v1/events). Not the same host as the browser listener script — see Advanced.', 'dava-tracking'); ?></p>
                    </div>
                </div>
                </div>

                <div class="dava-tracking-tab-panel<?php echo $active_tab === 'connection' ? ' is-active' : ''; ?>">
                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Privacy', 'dava-tracking'); ?></h2>

                    <div class="dava-tracking-field">
                        <label class="dava-tracking-toggle">
                            <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_consent]" value="1" <?php checked($options['require_consent'], true); ?> />
                            <span><?php echo esc_html__('Require cookie consent before tracking', 'dava-tracking'); ?></span>
                        </label>
                        <p class="description">
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: %s: window.dava.checkConsent() as inline code */
                                    __('Gates tracking behind a %s cookie set to "true". Your cookie-consent banner must set that cookie AND call %s when the visitor accepts — Dáva does not integrate with any specific consent platform automatically.', 'dava-tracking'),
                                    '<code>dava-consent</code>',
                                    '<code>window.dava.checkConsent()</code>'
                                ),
                                ['code' => []]
                            );
                            ?>
                        </p>
                    </div>
                </div>
                </div>

                <div class="dava-tracking-tab-panel<?php echo $active_tab === 'advanced' ? ' is-active' : ''; ?>">
                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Advanced', 'dava-tracking'); ?></h2>

                    <div class="dava-tracking-field">
                        <label for="dava-listener-base-url"><?php echo esc_html__('Browser listener URL', 'dava-tracking'); ?></label>
                        <input
                            id="dava-listener-base-url"
                            type="url"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[listener_base_url]"
                            value="<?php echo esc_attr($options['listener_base_url']); ?>"
                        />
                        <p class="description">
                            <?php echo esc_html__('Where the tracking script (t.js) loads from — deliberately a different host from API base URL. Defaults to the shared Dáva CDN. Only change this if you run a first-party proxy (e.g. a Cloudflare Worker on your own subdomain).', 'dava-tracking'); ?>
                        </p>
                    </div>

                    <div class="dava-tracking-field">
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[debug_mode]"
                                value="1"
                                <?php checked($options['debug_mode'], true); ?>
                            />
                            <?php echo esc_html__('Debug mode', 'dava-tracking'); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__('Writes the full request and response for every send attempt to the PHP error log — off by default since the request includes a hashed email. Turn on only while troubleshooting.', 'dava-tracking'); ?>
                        </p>
                    </div>

                    <div class="dava-tracking-field">
                        <label for="dava-email-field-hint"><?php echo esc_html__('Email field hint', 'dava-tracking'); ?></label>
                        <input
                            id="dava-email-field-hint"
                            type="text"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[email_field_hint]"
                            value="<?php echo esc_attr($options['email_field_hint']); ?>"
                            placeholder="<?php echo esc_attr__('e.g. customer-email, correo', 'dava-tracking'); ?>"
                        />
                        <p class="description">
                            <?php echo esc_html__('Optional, comma-separated. If a form has more than one email-type field, or the right field isn\'t typed as "email" at all, list text that matches its name/label/ID here — checked before falling back to auto-detecting the first email-type field.', 'dava-tracking'); ?>
                        </p>
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
                        <p class="description">
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: %s: the 4 valid plugin slug values, as inline code */
                                    __('Optional, comma-separated %s pairs — skip specific forms entirely (e.g. a newsletter signup with no conversion value) without turning off tracking for every form. Find a form\'s ID in its own plugin\'s form list/editor. Valid plugin values: %s.', 'dava-tracking'),
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

                <?php if ($active_tab !== 'diagnostics') : ?>
                    <?php submit_button(); ?>
                <?php endif; ?>
            </form>

            <?php if ($active_tab === 'advanced') : ?>
                <div class="dava-tracking-tab-panel is-active">
                    <div class="dava-tracking-card">
                        <h2><?php echo esc_html__('Reset', 'dava-tracking'); ?></h2>
                        <?php if ($reset_done) : ?>
                            <p class="description"><?php echo esc_html__('Settings reset to defaults.', 'dava-tracking'); ?></p>
                        <?php endif; ?>
                        <p class="description">
                            <?php echo esc_html__('Reverts API base URL, Browser listener URL, Require cookie consent, Debug mode, Email field hint, and Excluded forms back to their defaults. Site ID and API key are not affected.', 'dava-tracking'); ?>
                        </p>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::RESET_ACTION), self::RESET_NONCE_ACTION)); ?>"
                            class="button"
                            onclick="return confirm('<?php echo esc_js(__('Reset Advanced settings back to defaults? Site ID and API key are kept.', 'dava-tracking')); ?>');"
                        ><?php echo esc_html__('Reset to defaults', 'dava-tracking'); ?></a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="dava-tracking-tab-panel<?php echo $active_tab === 'diagnostics' ? ' is-active' : ''; ?>">
                <div
                    id="dava-test-connection-banner"
                    class="dava-tracking-banner<?php echo $fallback_banner_class !== '' ? ' ' . esc_attr($fallback_banner_class) : ''; ?>"
                    <?php echo $fallback_banner_message === '' ? 'hidden' : ''; ?>
                ><?php echo esc_html($fallback_banner_message); ?></div>

                <div class="dava-tracking-card">
                    <div class="dava-tracking-card-header">
                        <h2><?php echo esc_html__('Test connection', 'dava-tracking'); ?></h2>
                        <form id="dava-test-connection-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                            <input type="hidden" name="action" value="<?php echo esc_attr(Dava_Tracking_Test_Connection::ACTION); ?>" />
                            <?php wp_nonce_field(Dava_Tracking_Test_Connection::NONCE_ACTION); ?>
                            <?php submit_button(
                                __('Send test event', 'dava-tracking'),
                                'secondary',
                                'submit',
                                false,
                                $is_connected ? null : ['disabled' => 'disabled']
                            ); ?>
                        </form>
                    </div>
                    <p class="description">
                        <?php echo esc_html__('Calls a dedicated connectivity-check endpoint (GET /v1/verify) — no event or session is created — so a pass proves the site ID, API key and API base URL all actually work together.', 'dava-tracking'); ?>
                    </p>
                </div>

                <?php if (class_exists('WooCommerce')) : ?>
                    <div class="dava-tracking-card">
                        <div class="dava-tracking-card-header">
                            <h2><?php echo esc_html__('Backfill WooCommerce orders', 'dava-tracking'); ?></h2>
                            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                                <input type="hidden" name="action" value="<?php echo esc_attr(Dava_Tracking_WooCommerce::BACKFILL_ACTION); ?>" />
                                <?php wp_nonce_field(Dava_Tracking_WooCommerce::BACKFILL_NONCE_ACTION); ?>
                                <button type="submit" name="dry_run" value="1" class="button"><?php echo esc_html__('Preview', 'dava-tracking'); ?></button>
                                <?php submit_button(__('Resend last 30 days', 'dava-tracking'), 'secondary', 'submit', false); ?>
                            </form>
                        </div>
                        <p class="description">
                            <?php echo esc_html__('Resends conversion events for paid orders from the last 30 days that never got one — for example after enabling tracking on a site that already had orders. Preview classifies each order without sending anything. Orders already sent are skipped automatically; if "Require cookie consent" is on, orders placed before that setting was captured are skipped too.', 'dava-tracking'); ?>
                        </p>
                        <?php if ($backfill_sent !== null) : ?>
                            <p class="description">
                                <?php if ($backfill_mode === 'preview') : ?>
                                    <?php
                                    printf(
                                        /* translators: 1: number of orders that would be sent, 2: number of orders that would be skipped */
                                        esc_html__('Preview — would send for %1$d order(s), would skip %2$d (already sent, no consent, or no email). Nothing was actually sent.', 'dava-tracking'),
                                        $backfill_sent,
                                        $backfill_skipped
                                    );
                                    ?>
                                <?php else : ?>
                                    <?php
                                    printf(
                                        /* translators: 1: number of orders an event was sent for, 2: number of orders skipped */
                                        esc_html__('Done — sent for %1$d order(s), skipped %2$d (already sent, no consent, no email, or the send failed).', 'dava-tracking'),
                                        $backfill_sent,
                                        $backfill_skipped
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Retry queue', 'dava-tracking'); ?></h2>
                    <?php if (empty($retry_queue)) : ?>
                        <p class="description"><?php echo esc_html__('No events pending retry.', 'dava-tracking'); ?></p>
                    <?php else : ?>
                        <p class="description">
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
                                esc_html($retry_next_run ? human_time_diff($retry_next_run) : __('unknown', 'dava-tracking'))
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Recent activity', 'dava-tracking'); ?></h2>
                    <p id="dava-tracking-log-empty" class="description" <?php echo empty($activity_log) ? '' : 'hidden'; ?>>
                        <?php echo esc_html__('No events sent yet.', 'dava-tracking'); ?>
                    </p>
                    <table class="dava-tracking-log" <?php echo empty($activity_log) ? 'hidden' : ''; ?>>
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Time', 'dava-tracking'); ?></th>
                                <th><?php echo esc_html__('Event', 'dava-tracking'); ?></th>
                                <th><?php echo esc_html__('Status', 'dava-tracking'); ?></th>
                                <th><?php echo esc_html__('Detail', 'dava-tracking'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="dava-tracking-log-body">
                            <?php foreach ($activity_log as $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html(human_time_diff($entry['time']) . ' ' . __('ago', 'dava-tracking')); ?></td>
                                    <td><code><?php echo esc_html($entry['event_name']); ?></code></td>
                                    <td>
                                        <span class="dava-tracking-log-status is-<?php echo esc_attr($entry['status']); ?>">
                                            <?php echo esc_html(ucfirst($entry['status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($entry['message']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            </div>

            <aside class="dava-tracking-sidebar">
                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Status', 'dava-tracking'); ?></h2>

                    <p class="description">
                        <span class="dava-tracking-badge <?php echo $is_connected ? 'is-connected' : 'is-disconnected'; ?>">
                            <span class="dava-tracking-dot"></span>
                            <?php echo $is_connected
                                ? esc_html__('Connected', 'dava-tracking')
                                : esc_html__('Not configured', 'dava-tracking'); ?>
                        </span>
                    </p>

                    <?php if ($connection_status) : ?>
                        <p class="description">
                            <span class="dava-tracking-badge <?php echo $connection_status['environment'] === 'test' ? 'is-warning' : 'is-connected'; ?>">
                                <?php
                                printf(
                                    /* translators: 1: Dáva API key name, 2: environment (e.g. "test" or "production"), 3: human-readable time since last verified */
                                    esc_html__('%1$s — %2$s environment — verified %3$s ago', 'dava-tracking'),
                                    esc_html($connection_status['key_name']),
                                    esc_html($connection_status['environment'] ?: __('unknown', 'dava-tracking')),
                                    esc_html(human_time_diff($connection_status['verified_at']))
                                );
                                ?>
                            </span>
                        </p>
                    <?php endif; ?>

                    <p class="description">
                        <?php echo esc_html__('View sessions, journeys, and goals for this site.', 'dava-tracking'); ?>
                    </p>
                    <a href="<?php echo esc_url(DAVA_TRACKING_DASHBOARD_URL); ?>" class="button" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html__('Open Dáva Dashboard', 'dava-tracking'); ?> &#8599;
                    </a>
                </div>

                <div class="dava-tracking-card">
                    <h2><?php echo esc_html__('Tracked plugins', 'dava-tracking'); ?></h2>
                    <p class="description">
                        <?php echo esc_html__('Dáva automatically sends confirmed events for any of these already installed on this site.', 'dava-tracking'); ?>
                    </p>
                    <ul class="dava-tracking-plugin-list">
                        <?php foreach ($tracked_plugins as $plugin) : ?>
                            <li>
                                <span class="dava-tracking-plugin-dot <?php echo $plugin['detected'] ? 'is-detected' : 'is-missing'; ?>"></span>
                                <span><?php echo esc_html($plugin['label']); ?></span>
                                <span class="dava-tracking-plugin-status">
                                    <?php echo $plugin['detected']
                                        ? esc_html__('Detected', 'dava-tracking')
                                        : esc_html__('Not installed', 'dava-tracking'); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>

            </div>
        </div>
        <?php
    }
}
