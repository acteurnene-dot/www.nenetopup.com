<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              rulxphilomealexis.com
 * @since             1.0.0
 * @package           Mon_Cash_Pay
 *
 * @wordpress-plugin
 * Plugin Name:       Mon Cash Payment Gateway
 * Plugin URI:        mymoncashpay.com
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           1.0.0
 * Author:            Rulx Philome ALEXIS
 * Author URI:        rulxphilomealexis.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       mon-cash-pay
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'MON_CASH_PAY_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-mon-cash-pay-activator.php
 */
function activate_mon_cash_pay() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mon-cash-pay-activator.php';
	Mon_Cash_Pay_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-mon-cash-pay-deactivator.php
 */
function deactivate_mon_cash_pay() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mon-cash-pay-deactivator.php';
	Mon_Cash_Pay_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_mon_cash_pay' );
register_deactivation_hook( __FILE__, 'deactivate_mon_cash_pay' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-mon-cash-pay.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_mon_cash_pay() {

	$plugin = new Mon_Cash_Pay();
	$plugin->run();

}
run_mon_cash_pay();
