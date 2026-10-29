<?php
/**
 * Plugin Name: Dáva Tracking
 * Plugin URI: https://davazmysel.com
 * Description: Installs the Dáva browser listener and sends confirmed server-side events (WooCommerce, forms, ...) to Dáva, joined to the visitor's browser session via dava_session_id.
 * Version: 1.1.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 9.9
 * Author: Dáva
 * Author URI: https://davazmysel.com
 * License: GPL v2 or later
 * Text Domain: dava-tracking
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DAVA_TRACKING_VERSION', '1.1.2');
define('DAVA_TRACKING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DAVA_TRACKING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DAVA_TRACKING_UPDATE_REPO', 'https://github.com/Who-Do-Do/Dava-WP-Public/');
define('DAVA_TRACKING_DASHBOARD_URL', 'https://platform.davazmysel.com');

require_once DAVA_TRACKING_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'admin/class-settings.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'admin/class-test-connection.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'admin/class-site-health.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'includes/class-listener.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'includes/class-event-sender.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'includes/modules/class-contact-form.php';
require_once DAVA_TRACKING_PLUGIN_DIR . 'includes/modules/class-woocommerce.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// WooCommerce feature compatibility. Must run on before_woocommerce_init and
// pass this main plugin file. The plugin writes order meta only through the
// order CRUD object (custom_order_tables) and captures the session on the
// Block Checkout (cart_checkout_blocks).
add_action('before_woocommerce_init', function () {
    if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
});

add_action('plugins_loaded', function () {
    Dava_Tracking_Settings::instance();
    Dava_Tracking_Listener::instance();
    Dava_Tracking_Test_Connection::instance();
    Dava_Tracking_Site_Health::instance();
    // Registers the WP-Cron hooks that send queued events; a cron request
    // never reaches a send_event() call, so nothing else would load them.
    Dava_Tracking_Event_Sender::instance();
    // Registered unconditionally; each module itself no-ops when its target
    // plugin (CF7/WPForms/Gravity/WooCommerce) isn't active on this site.
    Dava_Tracking_Contact_Form::instance();
    Dava_Tracking_WooCommerce::instance();

    // Self-hosted update checks against the plugin's GitHub repo, since this
    // plugin isn't published on wordpress.org. DAVA_TRACKING_UPDATE_REPO is the
    // public repo that holds only this plugin. The version comes straight from
    // the release tag name (e.g. "v1.0.0"), and enableReleaseAssets() makes PUC
    // install the attached zip instead of the auto-generated source archive,
    // because that archive has no top-level "dava-tracking/" folder. Every
    // release therefore needs: bump DAVA_TRACKING_VERSION, tag+push,
    // `gh release create` with a zip asset matching this regex
    // (scripts/zip-plugin.sh builds it).
    $update_checker = PucFactory::buildUpdateChecker(DAVA_TRACKING_UPDATE_REPO, __FILE__, 'dava-tracking');
    $update_checker->setBranch('main');
    $update_checker->getVcsApi()->enableReleaseAssets('/^dava-tracking\.zip$/');
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(Dava_Tracking_Event_Sender::RETRY_HOOK);
    wp_clear_scheduled_hook(Dava_Tracking_Event_Sender::DEFER_HOOK);
});
