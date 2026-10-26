<?php
/**
 * Adds a "Dáva Tracking" test to Tools > Site Health > Status, so a site
 * admin (or hosting support agent) can see connection state there without
 * knowing to check the plugin's own Diagnostics tab first.
 *
 * Reads only stored state (options + the most recent activity log entry) —
 * deliberately doesn't call the live API here, since Site Health tests run
 * on every visit to that admin page and a slow/failed HTTP call would make
 * the page itself slow or misleading.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_Site_Health {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function register_hooks() {
        add_filter('site_status_tests', [$this, 'register_test']);
    }

    public function register_test($tests) {
        $tests['direct']['dava_tracking'] = [
            'label' => __('Dáva Tracking', 'dava-tracking'),
            'test' => [$this, 'run_test'],
        ];
        return $tests;
    }

    public function run_test() {
        $options = Dava_Tracking_Settings::get_options();
        $settings_url = admin_url('options-general.php?page=' . Dava_Tracking_Settings::PAGE_SLUG);

        $result = [
            'label' => __('Dáva Tracking is connected', 'dava-tracking'),
            'status' => 'good',
            'badge' => [
                'label' => __('Dáva Tracking', 'dava-tracking'),
                'color' => 'blue',
            ],
            'description' => sprintf('<p>%s</p>', esc_html__('Tracking is enabled and configured.', 'dava-tracking')),
            'actions' => sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url($settings_url),
                esc_html__('Manage Dáva Tracking settings', 'dava-tracking')
            ),
            'test' => 'dava_tracking',
        ];

        if (empty($options['enabled'])) {
            $result['label'] = __('Dáva Tracking is not enabled', 'dava-tracking');
            $result['status'] = 'recommended';
            $result['description'] = sprintf(
                '<p>%s</p>',
                esc_html__('The plugin is installed but tracking is turned off, so no events are being sent to Dáva.', 'dava-tracking')
            );
            return $result;
        }

        if ($options['site_id'] === '' || $options['api_key'] === '') {
            $result['label'] = __('Dáva Tracking is enabled but not fully configured', 'dava-tracking');
            $result['status'] = 'critical';
            $result['description'] = sprintf(
                '<p>%s</p>',
                esc_html__('Tracking is turned on, but the Site ID or API key is missing, so no events can actually be sent.', 'dava-tracking')
            );
            return $result;
        }

        $log = Dava_Tracking_Event_Sender::get_log();
        if (!empty($log) && $log[0]['status'] === 'error') {
            $result['label'] = __("Dáva Tracking's most recent send failed", 'dava-tracking');
            $result['status'] = 'recommended';
            $result['description'] = sprintf(
                '<p>%s</p>',
                sprintf(
                    /* translators: %s: the error message from the failed send */
                    esc_html__('The most recent event send failed: %s', 'dava-tracking'),
                    esc_html($log[0]['message'])
                )
            );
        }

        // WooCommerce older than the Block Checkout capture needs: the classic
        // checkout still works, so this is a recommendation, never critical.
        if (
            $result['status'] === 'good'
            && defined('WC_VERSION')
            && version_compare(WC_VERSION, Dava_Tracking_WooCommerce::MIN_WC_VERSION_BLOCK_CHECKOUT, '<')
        ) {
            $result['label'] = __('Dáva Tracking needs a newer WooCommerce for the Block Checkout', 'dava-tracking');
            $result['status'] = 'recommended';
            $result['description'] = sprintf(
                '<p>%s</p>',
                sprintf(
                    /* translators: 1: minimum WooCommerce version, 2: installed WooCommerce version */
                    esc_html__('Capturing the visitor session on the WooCommerce Block Checkout needs WooCommerce %1$s or newer (this site runs %2$s). The classic checkout is not affected.', 'dava-tracking'),
                    esc_html(Dava_Tracking_WooCommerce::MIN_WC_VERSION_BLOCK_CHECKOUT),
                    esc_html(WC_VERSION)
                )
            );
        }

        return $result;
    }
}
