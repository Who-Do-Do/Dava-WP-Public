<?php
/**
 * Injects the Dáva browser listener script tag and the shared session-id
 * helper on every front-end page when tracking is enabled and configured.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_Listener {

    const SCRIPT_HANDLE = 'dava-tracking-listener';
    const SESSION_HELPER_HANDLE = 'dava-tracking-session';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register_hooks();
        }
        return self::$instance;
    }

    private function register_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_filter('script_loader_tag', [$this, 'add_listener_attributes'], 10, 3);
        add_shortcode('dava_session_field', [$this, 'render_session_field_shortcode']);
    }

    /**
     * For forms this plugin doesn't natively integrate with (a custom HTML
     * form, or any form plugin outside CF7/WPForms/Gravity/Elementor Pro):
     * drop [dava_session_field] inside the form and form-session.js fills it
     * in the same way it fills the hidden field the 4 built-in integrations
     * inject automatically. The site still needs its own code (or the
     * dava_tracking_event_before_send / dava_tracking_event_sent hooks) to
     * actually send a confirmed event on submission — this shortcode only
     * gets the session id into the submitted POST body.
     */
    public function render_session_field_shortcode() {
        return '<input type="hidden" name="dava_session_id" value="" />';
    }

    public function enqueue() {
        $options = Dava_Tracking_Settings::get_options();

        // listener_base_url (t.js, the tracking CDN) and api_base_url (/v1/events,
        // the API host) are DIFFERENT hosts in every real Dáva environment — never
        // fall back from one to the other, that would load the script from a host
        // that doesn't serve it.
        if (empty($options['enabled']) || empty($options['site_id']) || empty($options['listener_base_url'])) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            untrailingslashit($options['listener_base_url']) . '/t.js',
            [],
            DAVA_TRACKING_VERSION,
            false // must render in <head>, before the visitor can interact with the page
        );

        wp_enqueue_script(
            self::SESSION_HELPER_HANDLE,
            DAVA_TRACKING_PLUGIN_URL . 'public/js/dava-session.js',
            [self::SCRIPT_HANDLE],
            DAVA_TRACKING_VERSION,
            true
        );
    }

    /**
     * wp_enqueue_script has no built-in way to add data-site/defer attributes,
     * so the tag is patched via the script_loader_tag filter — the only
     * WordPress-core-supported hook for this.
     */
    public function add_listener_attributes($tag, $handle, $src) {
        if ($handle !== self::SCRIPT_HANDLE) {
            return $tag;
        }

        $options = Dava_Tracking_Settings::get_options();
        // data-require-consent is only emitted when the gate is on, matching
        // the dashboard's AccountSnippetSection snippet-copy behavior — sites
        // that don't need consent keep the shorter, unchanged tag.
        $consent_attr = !empty($options['require_consent']) ? ' data-require-consent="true"' : '';
        $attrs = sprintf(' data-site="%s" data-track-cta="true"%s defer', esc_attr($options['site_id']), $consent_attr);

        return str_replace(' src=', $attrs . ' src=', $tag);
    }
}
