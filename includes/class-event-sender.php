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

    // Events that must not hold up the visitor's request (WooCommerce orders)
    // are queued with queue_event() and sent by this hook right after the
    // request, through the same queue and retry path as a failed send.
    const DEFER_HOOK = 'dava_tracking_send_deferred';

    // Default timeout. Deferred (cron), retried and backfill sends run with no
    // visitor waiting, so they get the longer one.
    const SEND_TIMEOUT_SECONDS = 5;

    // A send inside a visitor's request (a form submit) passes this instead; a
    // slow API must not hold the visitor for long. A timeout is retried by the
    // queue above.
    const INLINE_TIMEOUT_SECONDS = 2;

    // The queue option is read, changed and written back by several requests
    // at once (every checkout appends to it), so each change runs under a
    // database lock. See with_queue_lock().
    const QUEUE_LOCK_WAIT_SECONDS = 2;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            add_action(self::RETRY_HOOK, [self::$instance, 'process_retry_queue']);
            add_action(self::DEFER_HOOK, [self::$instance, 'process_retry_queue']);
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
     * @param int $timeout Seconds to wait for the API. Pass INLINE_TIMEOUT_SECONDS
     *                      when a visitor's request is waiting on this send.
     * @return array|WP_Error Decoded JSON response body, or WP_Error on
     *                         transport failure / non-2xx response.
     */
    public function send_event(array $event, $attempt = 0, $timeout = self::SEND_TIMEOUT_SECONDS) {
        $options = Dava_Tracking_Settings::get_options();

        // These two exits fire the action too, so a listener (the WooCommerce
        // module clears its queued marker there) sees every terminal outcome.
        if (empty($options['api_key'])) {
            $result = $this->fail($event, __('Dáva API key is not configured.', 'dava-tracking'), 'dava_missing_api_key');
            do_action('dava_tracking_event_sent', $event, $result);
            return $result;
        }

        if (empty($event['event_type'])) {
            $result = $this->fail($event, __('event_type is required.', 'dava-tracking'), 'dava_missing_event_type');
            do_action('dava_tracking_event_sent', $event, $result);
            return $result;
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
            'timeout' => (int) $timeout,
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
     * Queues an event to be sent by WP-Cron right after the current request,
     * instead of inside it. Goes through the same queue as a retry, so a
     * failed send is tried 3 times in all, 15 minutes apart. The result reaches the
     * activity log and the dava_tracking_event_sent action like any other send.
     */
    public function queue_event(array $event) {
        $this->with_queue_lock(function () use ($event) {
            $queue = $this->read_queue();
            $queue[] = ['event' => $event, 'attempt' => 0, 'due' => time()];
            update_option(self::RETRY_OPTION_KEY, $queue, false);
        });

        wp_schedule_single_event(time(), self::DEFER_HOOK);
    }

    /**
     * Whether an event with this transaction_id is waiting in the queue
     * (first send or retry).
     */
    public function has_queued_transaction($transaction_id) {
        foreach (get_option(self::RETRY_OPTION_KEY, []) as $item) {
            if (isset($item['event']['transaction_id']) && (string) $item['event']['transaction_id'] === (string) $transaction_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drops every queued event for this transaction_id (the privacy eraser
     * uses it: the queued copy carries the visitor's session id and hashed
     * email). Returns whether anything was dropped.
     */
    public function discard_queued_transaction($transaction_id) {
        $dropped = false;
        $this->with_queue_lock(function () use ($transaction_id, &$dropped) {
            $queue = $this->read_queue();
            $kept = [];
            foreach ($queue as $item) {
                if (isset($item['event']['transaction_id']) && (string) $item['event']['transaction_id'] === (string) $transaction_id) {
                    $dropped = true;
                } else {
                    $kept[] = $item;
                }
            }
            if ($dropped) {
                update_option(self::RETRY_OPTION_KEY, $kept, false);
            }
        });
        return $dropped;
    }

    /**
     * The queue as stored right now. get_option() would answer from this
     * process's object cache, which can predate another request's write; a
     * change made on that copy would overwrite the other request's event.
     */
    private function read_queue() {
        wp_cache_delete(self::RETRY_OPTION_KEY, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[self::RETRY_OPTION_KEY])) {
            unset($notoptions[self::RETRY_OPTION_KEY]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
        $queue = get_option(self::RETRY_OPTION_KEY, []);
        return is_array($queue) ? $queue : [];
    }

    /**
     * Runs $change while holding a named MySQL/MariaDB lock (GET_LOCK), so two
     * requests changing the queue at the same moment cannot overwrite each
     * other. GET_LOCK waits on the database server, is released when the
     * connection ends (a crashed request cannot leave it stuck), and needs
     * nothing but the database every WordPress site has. A row-based mutex in
     * wp_options was tried first and deadlocked under contention (InnoDB gap
     * locks on the duplicate-key insert). If the lock cannot be taken within
     * QUEUE_LOCK_WAIT_SECONDS (or the database has no GET_LOCK), the change
     * runs anyway: holding a checkout longer is worse than the small chance of
     * a lost update.
     */
    private function with_queue_lock(callable $change) {
        global $wpdb;

        $name = 'dava_queue_' . substr(md5($wpdb->prefix . DB_NAME), 0, 16);
        $acquired = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, self::QUEUE_LOCK_WAIT_SECONDS)) === 1;

        try {
            $change();
        } finally {
            if ($acquired) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            }
        }
    }

    /**
     * Runs on the retry and deferred hooks — resends every queued event that
     * is due, letting send_event() re-queue (with an incremented attempt
     * count) any that fail again. Due items are taken out of the queue up
     * front so an event only reruns once per cron firing even if it fails
     * again immediately; items not due yet (a retry waiting out its 15
     * minutes) stay and get their own run scheduled.
     */
    public function process_retry_queue() {
        $due = [];
        $this->with_queue_lock(function () use (&$due) {
            $queue = $this->read_queue();
            if (empty($queue)) {
                return;
            }

            $now = time();
            $waiting = [];
            foreach ($queue as $item) {
                if (($item['due'] ?? 0) <= $now) {
                    $due[] = $item;
                } else {
                    $waiting[] = $item;
                }
            }

            if (!empty($due)) {
                update_option(self::RETRY_OPTION_KEY, $waiting, false);
            }
        });

        // Sent outside the lock: a send can take seconds, and a failed one
        // re-queues itself through maybe_enqueue_retry().
        foreach ($due as $item) {
            $this->send_event($item['event'], $item['attempt']);
        }

        $this->schedule_next_retry();
    }

    private function schedule_next_retry() {
        $next = null;
        foreach (get_option(self::RETRY_OPTION_KEY, []) as $item) {
            $due = (int) ($item['due'] ?? 0);
            $next = $next === null ? $due : min($next, $due);
        }

        if ($next !== null && !wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_single_event(max($next, time()), self::RETRY_HOOK);
        }
    }

    private function maybe_enqueue_retry(array $event, $attempt) {
        if ($attempt + 1 >= self::RETRY_MAX_ATTEMPTS) {
            return;
        }

        $this->with_queue_lock(function () use ($event, $attempt) {
            $queue = $this->read_queue();
            $queue[] = ['event' => $event, 'attempt' => $attempt + 1, 'due' => time() + self::RETRY_DELAY_SECONDS];
            update_option(self::RETRY_OPTION_KEY, $queue, false);
        });

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
