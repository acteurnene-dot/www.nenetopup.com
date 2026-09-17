<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       rulxphilomealexis.com
 * @since      1.0.0
 *
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Mon_Cash_Pay
 * @subpackage Mon_Cash_Pay/public
 * @author     Rulx Philome ALEXIS <rulxphilome.alexis@gmail.com>
 */

require_once plugin_dir_path( __FILE__ ).'../includes/config.php';
//require_once plugin_dir_path( __FILE__ ).'../admin/MonCashGateway.php';


class Mon_Cash_Pay_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Mon_Cash_Pay_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Mon_Cash_Pay_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/mon-cash-pay-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Mon_Cash_Pay_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Mon_Cash_Pay_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/mon-cash-pay-public.js', array( 'jquery' ), $this->version, false );

	}

	public function urlsafe_b64encode($string) {
		$data = base64_encode($string);
		$data = str_replace(array('+','/','='),array('-','_',''),$data);
		return $data;
	}

	public function urlsafe_b64decode($string) {
		$data = str_replace(array('-','_'),array('+','/'),$string);
		$mod4 = strlen($data) % 4;
		if ($mod4) {
			$data .= substr('====', $mod4);
		}
		return base64_decode($data);
	}

	public function addingActionButtonToOrders( $actions, $order ) {

		$orderDatas = $order->get_data();
//		var_dump($orderDatas);exit;
		if($orderDatas['payment_method'] == str_replace("-","_",$this->plugin_name) AND $orderDatas['status'] != 'completed'){
			$thePaymentsGWays = WC_Payment_Gateways::instance()->payment_gateways();

            $mcSettingsInfo = $thePaymentsGWays[str_replace("-","_",$this->plugin_name)];

            $api_key = $mcSettingsInfo->settings['secret_key'];
            $business_key = trim($mcSettingsInfo->settings['business_key']);

			$actions['mc_action'] = array(
				'url'  => plugin_dir_url(__FILE__)."transactionVerification.php?orderId=".$orderDatas['id']."&apk=".urlencode($api_key)."&bk=".urlencode($business_key),
				'name' => 'Mon Cash | Verification',
			);
		}elseif ($orderDatas['payment_method'] == str_replace("-","_",$this->plugin_name)){
			$actions['mc_action'] = array(
				'url'  => "#",
				'name' => 'Mon Cash | Payment OK',
			);
		}

                    /*if($orderDatas['payment_method'] == str_replace("-","_",$this->plugin_name)){


            //			var_dump($response);
                        $actions['mc_action'] = array(
                            'url'  => plugin_dir_url(__FILE__)."transactionVerification.php",
                            'name' => $response,
                        );
                    }*/

//		var_dump($orderDatas['payment_method']);
//		var_dump($this->plugin_name);

		return $actions;
	}

}
