<?php

/**
 * Define the internationalization functionality
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @link       rulxphilomealexis.com
 * @since      1.0.0
 *
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/includes
 */

/**
 * Define the internationalization functionality.
 *
 * Loads and defines the internationalization files for this plugin
 * so that it is ready for translation.
 *
 * @since      1.0.0
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/includes
 * @author     Rulx Philome ALEXIS <rulxphilome.alexis@gmail.com>
 */
class Mon_Cash_Pay_i18n {


	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'mon-cash-pay',
			false,
			dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
		);

	}



}
