<?php
/**
 * Plugin Name: GiftAccess for Woo
 * Plugin URI: https://gift-access.com
 * Description: Connecteur WooCommerce pour vendre et livrer des produits digitaux via l’API Gift Access.
 * Version: 1.1.10
 * Author: Gift Access
 * Author URI: mailto:cuenta@gift-access.com
 * Text Domain: giftaccess-for-woo
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GIFTACCESS_WOO_VERSION', '1.1.10' );
define( 'GIFTACCESS_WOO_FILE', __FILE__ );
define( 'GIFTACCESS_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'GIFTACCESS_WOO_URL', plugin_dir_url( __FILE__ ) );

require_once GIFTACCESS_WOO_PATH . 'includes/class-plugin.php';

/**
 * Bootstrap plugin.
 *
 * @return GiftAccess_Woo_Plugin
 */
function giftaccess_woo() {
	return GiftAccess_Woo_Plugin::instance();
}

giftaccess_woo();
