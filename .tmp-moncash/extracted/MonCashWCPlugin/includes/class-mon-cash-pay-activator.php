<?php

/**
 * Fired during plugin activation
 *
 * @link       rulxphilomealexis.com
 * @since      1.0.0
 *
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/includes
 * @author     Rulx Philome ALEXIS <rulxphilome.alexis@gmail.com>
 */
class Mon_Cash_Pay_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		global $wpdb;

		$tableName = $wpdb->prefix."mc_button_config";

		$sql = "CREATE TABLE ".$tableName."(";
		$sql .="id INT AUTO_INCREMENT PRIMARY KEY,";
		$sql .="secret_key TEXT,";
		$sql .="business_key TEXT);";

//		var_dump(get_option("secret_key"));exit;
		$wpdb->query($sql);

	}

}
