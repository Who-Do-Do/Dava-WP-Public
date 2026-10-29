<?php
/**
 * Runs only when the plugin is deleted from the Plugins page (not on
 * deactivation). Removes everything this plugin stored in the database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('dava_tracking_options');
delete_option('dava_tracking_activity_log');
delete_option('dava_tracking_retry_queue');
delete_option('dava_tracking_connection_status');

wp_clear_scheduled_hook('dava_tracking_process_retry_queue');
wp_clear_scheduled_hook('dava_tracking_send_deferred');
