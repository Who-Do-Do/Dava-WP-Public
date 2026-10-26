<?php
/**
 * "Test Connection" button on the settings page. Calls GET /v1/verify — a
 * dedicated connectivity-check endpoint (no event, no session created) — so
 * a passing test proves the site_id/api_key/api_base_url work end to end
 * without leaving a phantom entry in the Sessions list.
 *
 * Two entry points share the same check_connection():
 * - handle_ajax(): primary path, used by admin/js/settings.js — no page
 *   navigation, banner + activity log update in place.
 * - handle_post(): admin-post.php fallback for when JS is unavailable —
 *   redirects back with ?dava_test=success|error, same as before AJAX existed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_Test_Connection {

    const ACTION = 'dava_tracking_test_connection';
    const AJAX_ACTION = 'dava_tracking_test_connection_ajax';
    const NONCE_ACTION = 'dava_tracking_test_connection_nonce';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function register_hooks() {
        add_action('admin_post_' . self::ACTION, [$this, 'handle_post']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handle_ajax']);
    }

    private static function check_connection() {
        return Dava_Tracking_Event_Sender::instance()->verify_connection();
    }

    public function handle_post() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'dava-tracking'), '', ['response' => 403]);
        }
        check_admin_referer(self::NONCE_ACTION);

        $result = self::check_connection();

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'dava-tracking',
                'tab' => 'diagnostics',
                'dava_test' => is_wp_error($result) ? 'error' : 'success',
            ],
            admin_url('options-general.php')
        ));
        exit;
    }

    public function handle_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'dava-tracking')], 403);
        }
        // No explicit query_arg: reads the form's existing wp_nonce_field()
        // output (_wpnonce) as-is — the AJAX form is the exact same markup
        // as the no-JS fallback form, just intercepted by settings.js.
        check_ajax_referer(self::NONCE_ACTION);

        $result = self::check_connection();
        $log = Dava_Tracking_Event_Sender::get_log();
        $entry = $log[0] ?? null;

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => sprintf(__('Connection failed: %s', 'dava-tracking'), $result->get_error_message()),
                'entry' => $entry,
            ]);
        }

        wp_send_json_success([
            'message' => __('Connection successful.', 'dava-tracking'),
            'entry' => $entry,
        ]);
    }
}
