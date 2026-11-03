<?php
/**
 * Plugin Name: Gift Access Gaming
 * Description: WooCommerce gaming top-up automation powered by the Gift Access partner API.
 * Version: 1.1.11
 * Author: Gift Access
 * Author URI: https://gift-access.com
 * Text Domain: gift-access-topup-bridge
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GIFTACCESS_TOPUP_VERSION', '1.1.11');
define('GIFTACCESS_TOPUP_FILE', __FILE__);
define('GIFTACCESS_TOPUP_PATH', plugin_dir_path(__FILE__));
define('GIFTACCESS_TOPUP_URL', plugin_dir_url(__FILE__));

require_once GIFTACCESS_TOPUP_PATH . 'includes/class-giftaccess-topup-plugin.php';
require_once GIFTACCESS_TOPUP_PATH . 'includes/class-giftaccess-topup-logger.php';
require_once GIFTACCESS_TOPUP_PATH . 'includes/class-giftaccess-topup-api.php';
require_once GIFTACCESS_TOPUP_PATH . 'includes/class-giftaccess-topup-admin.php';
require_once GIFTACCESS_TOPUP_PATH . 'includes/class-giftaccess-topup-woocommerce.php';
require_once GIFTACCESS_TOPUP_PATH . 'includes/class-giftaccess-topup-webhook.php';

function giftaccess_topup_bridge_activate()
{
    $settings = get_option('giftaccess_topup_settings', array());
    $is_new_install = !is_array($settings) || empty($settings);
    if (!is_array($settings)) {
        $settings = array();
    }

    if (
        empty($settings['language'])
        || !in_array((string) $settings['language'], array('fr', 'en', 'es'), true)
        || (!empty($settings['setup_required']) && $settings['setup_required'] === 'yes')
    ) {
        $settings['language'] = 'en';
    }

    if ($is_new_install || !isset($settings['setup_required'])) {
        $settings['setup_required'] = 'yes';
    }

    update_option('giftaccess_topup_settings', $settings, false);
}

function giftaccess_topup_bridge()
{
    return GiftAccess_Topup_Plugin::instance();
}

register_activation_hook(__FILE__, 'giftaccess_topup_bridge_activate');
add_action('plugins_loaded', 'giftaccess_topup_bridge');
