<?php

/**
 * Fired during plugin deactivation
 *
 * @link       rulxphilomealexis.com
 * @since      1.0.0
 *
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/includes
 * @author     Rulx Philome ALEXIS <rulxphilome.alexis@gmail.com>
 */
class Mon_Cash_Pay_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		global $wpdb;

		$tableName = $wpdb->prefix."mc_button_config";

		$sql = "DROP TABLE IF EXISTS ".$tableName;

		$wpdb->query($sql);

		

	}

}
