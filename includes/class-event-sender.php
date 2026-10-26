<?php
/**
 * Shared helper for posting confirmed server-side events to Dáva's public
 * POST /v1/events API. Feature modules (WooCommerce, Contact Form, ...) call
 * send_event() with their own event_type/event_name/dava_session_id.
 *
 * Transient failures (network errors, 5xx) are queued and retried via
 * WP-Cron — see maybe_enqueue_retry()/process_retry_queue().
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dava_Tracking_Event_Sender {

    const LOG_OPTION_KEY = 'dava_tracking_activity_log';
    const LOG_MAX_ENTRIES = 20;
    const CONNECTION_STATUS_OPTION_KEY = 'dava_tracking_connection_status';

    // Retry only covers transient failures (network errors, 5xx) — a missing
    // API key or a 4xx (bad request, invalid key, unregistered domain) will
    // fail the exact same way on every retry, so those are never queued.
    const RETRY_OPTION_KEY = 'dava_tracking_retry_queue';
    const RETRY_MAX_ATTEMPTS = 3;
    const RETRY_DELAY_SECONDS = 900; // 15 minutes.
    const RETRY_HOOK = 'dava_tracking_process_retry_queue';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            add_action(self::RETRY_HOOK, [self::$instance, 'process_retry_queue']);
        }
        return self::$instance;
    }

    /**
     * @param array $event Must include 'event_type' (one of Dáva's fixed enum
     *                      values — pageview/form_submit/click/conversion/...).
     *                      Should include 'dava_session_id' when available; an
     *                      event posted without it still stores as an
     *                      "unmatched server event" rather than failing.
     * @param int $attempt Internal — retry counter, do not pass when calling
     *                      this directly for a fresh event.
     * @return array|WP_Error Decoded JSON response body, or WP_Error on
     *                         transport failure / non-2xx response.
     */
    public function send_event(array $event, $attempt = 0) {
        $options = Dava_Tracking_Settings::get_options();

        if (empty($options['api_key'])) {
            return $this->fail($event, __('Dáva API key is not configured.', 'dava-tracking'), 'dava_missing_api_key');
        }

        if (empty($event['event_type'])) {
            return $this->fail($event, __('event_type is required.', 'dava-tracking'), 'dava_missing_event_type');
        }

        // subdomain is required for correct domain attribution when calling
        // the Dáva API host directly (docs/tracking/platforms/wordpress.mdx)
        // — harmless to always include even for a custom-domain/proxy setup,
        // where the API resolves domain from the request Host header instead
        // and simply ignores this field.
        if (empty($event['subdomain'])) {
            $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($site_host) {
                $event['subdomain'] = $site_host;
            }
        }

        /**
         * Last chance to adjust an event before it's sent — add custom
         * properties, override a value computed by a built-in module, etc.
         * Runs for every send attempt, including retries.
         *
         * @param array $event
         */
        $event = apply_filters('dava_tracking_event_before_send', $event);

        $url = untrailingslashit($options['api_base_url']) . '/v1/events';
        $this->debug_log('Request', ['url' => $url, 'body' => $event]);

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $options['api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($event),
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            $this->debug_log('Transport error', ['message' => $response->get_error_message()]);
            $this->maybe_enqueue_retry($event, $attempt);
            $result = $this->fail($event, $response->get_error_message(), 'dava_transport_error');
            do_action('dava_tracking_event_sent', $event, $result);
            return $result;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $this->debug_log('Response', ['status' => $status, 'body' => $body]);

        if ($status >= 400) {
            $message = is_array($body) && !empty($body['message'])
                ? $body['message']
                : sprintf(__('Dáva API returned HTTP %d.', 'dava-tracking'), $status);
            if ($status >= 500) {
                $this->maybe_enqueue_retry($event, $attempt);
            }
            $result = $this->fail($event, $message, 'dava_api_error', $body);
            do_action('dava_tracking_event_sent', $event, $result);
            return $result;
        }

        $this->log(
            $event,
            'success',
            !empty($body['event_id'])
                ? sprintf(__('Sent (event_id: %s)', 'dava-tracking'), $body['event_id'])
                : __('Sent.', 'dava-tracking')
        );

        /**
         * Fires after every send attempt, success or failure — for custom
         * logging/integrations. $result is the decoded response body on
         * success, or a WP_Error on failure (check with is_wp_error()).
         *
         * @param array $event
         * @param array|WP_Error $result
         */
        do_action('dava_tracking_event_sent', $event, $body);

        return $body;
    }

    /**
     * Confirms the configured API key + API base URL work by calling
     * GET /v1/verify — a dedicated connectivity-check endpoint that creates
     * NO event and NO session (unlike posting a real event, which always
     * leaves a session_key behind in the Sessions list per Dáva's identity
     * model — there is no event that can carry an identity signal without
     * doing that). Falls back to nothing else; if the account's Dáva
     * environment predates /v1/verify, this will 404.
     *
     * @return array|WP_Error Decoded JSON response body, or WP_Error.
     */
    public function verify_connection() {
        $options = Dava_Tracking_Settings::get_options();
        $context = ['event_name' => 'connection_check'];

        if (empty($options['api_key'])) {
            return $this->fail($context, __('Dáva API key is not configured.', 'dava-tracking'), 'dava_missing_api_key');
        }

        $url = untrailingslashit($options['api_base_url']) . '/v1/verify';
        $this->debug_log('Request', ['url' => $url]);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $options['api_key'],
            ],
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            $this->debug_log('Transport error', ['message' => $response->get_error_message()]);
            return $this->fail($context, $response->get_error_message(), 'dava_transport_error');
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $this->debug_log('Response', ['status' => $status, 'body' => $body]);

        if ($status >= 400) {
            $message = is_array($body) && !empty($body['message'])
                ? $body['message']
                : sprintf(__('Dáva API returned HTTP %d.', 'dava-tracking'), $status);
            return $this->fail($context, $message, 'dava_api_error', $body);
        }

        $this->log(
            $context,
            'success',
            !empty($body['key_name'])
                ? sprintf(__('Connected (key: %s, env: %s)', 'dava-tracking'), $body['key_name'], $body['environment'] ?? '')
                : __('Connected.', 'dava-tracking')
        );

        // Persisted separately from the activity log (which scrolls this
        // out of view within a few send attempts) so the settings page can
        // show, at a glance, which Dáva key/environment is actually wired
        // up — catches the real mistake of a test key left on a live site
        // (or vice versa) without needing to read a log message.
        if (!empty($body['key_name'])) {
            update_option(self::CONNECTION_STATUS_OPTION_KEY, [
                'key_name' => $body['key_name'],
                'environment' => $body['environment'] ?? '',
                'verified_at' => time(),
            ], false);
        }

        return $body;
    }

    /**
     * Last successful verify_connection() result — null if never verified.
     */
    public static function get_connection_status() {
        return get_option(self::CONNECTION_STATUS_OPTION_KEY, null) ?: null;
    }

    /**
     * Last self::LOG_MAX_ENTRIES send attempts (success and failure), newest
     * first — surfaced on the settings page so a non-technical site admin can
     * see WHY tracking isn't showing up in Dáva without reading server logs.
     */
    public static function get_log() {
        return get_option(self::LOG_OPTION_KEY, []);
    }

    /**
     * Runs on the scheduled retry hook — resends every queued event, letting
     * send_event() re-queue (with an incremented attempt count) any that fail
     * again. The queue is cleared up front so an event only reruns once per
     * cron firing even if it fails again immediately.
     */
    public function process_retry_queue() {
        $queue = get_option(self::RETRY_OPTION_KEY, []);
        if (empty($queue)) {
            return;
        }
        update_option(self::RETRY_OPTION_KEY, [], false);

        foreach ($queue as $item) {
            $this->send_event($item['event'], $item['attempt']);
        }
    }

    private function maybe_enqueue_retry(array $event, $attempt) {
        if ($attempt + 1 >= self::RETRY_MAX_ATTEMPTS) {
            return;
        }

        $queue = get_option(self::RETRY_OPTION_KEY, []);
        $queue[] = ['event' => $event, 'attempt' => $attempt + 1];
        update_option(self::RETRY_OPTION_KEY, $queue, false);

        if (!wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_single_event(time() + self::RETRY_DELAY_SECONDS, self::RETRY_HOOK);
        }
    }

    /**
     * Off by default — writes full request/response payloads (not just the
     * short message the activity log shows) to PHP's error log, for tracing
     * a send that fails in a way the activity log message doesn't explain.
     * Requires "Debug mode" enabled on the Advanced tab; a site's normal
     * PHP/WP_DEBUG_LOG error log destination applies as usual.
     */
    private function debug_log($label, array $data) {
        $options = Dava_Tracking_Settings::get_options();
        if (empty($options['debug_mode'])) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- explicit opt-in debug tool
        error_log('[Dava Tracking Debug] ' . $label . ': ' . wp_json_encode($data));
    }

    private function fail(array $event, $message, $code, $data = null) {
        $this->log($event, 'error', $message);
        return new WP_Error($code, $message, $data);
    }

    private function log(array $event, $status, $message) {
        $log = get_option(self::LOG_OPTION_KEY, []);
        array_unshift($log, [
            'time' => time(),
            'event_name' => $event['event_name'] ?? ($event['event_type'] ?? ''),
            'status' => $status,
            'message' => $message,
        ]);
        update_option(self::LOG_OPTION_KEY, array_slice($log, 0, self::LOG_MAX_ENTRIES), false);
    }
}
