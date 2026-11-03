<?php
/**
 * Main plugin class.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var GiftAccess_Woo_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var GiftAccess_Woo_Settings
	 */
	private $settings;

	/**
	 * API handler.
	 *
	 * @var GiftAccess_Woo_API
	 */
	private $api;

	/**
	 * Logger handler.
	 *
	 * @var GiftAccess_Woo_Logger
	 */
	private $logger;

	/**
	 * Product fields handler.
	 *
	 * @var GiftAccess_Woo_Product_Fields
	 */
	private $product_fields;

	/**
	 * Frontend fields handler.
	 *
	 * @var GiftAccess_Woo_Frontend_Fields
	 */
	private $frontend_fields;

	/**
	 * Order processor.
	 *
	 * @var GiftAccess_Woo_Order_Processor
	 */
	private $order_processor;

	/**
	 * Admin orders handler.
	 *
	 * @var GiftAccess_Woo_Admin_Orders
	 */
	private $admin_orders;

	/**
	 * Incoming webhook handler.
	 *
	 * @var GiftAccess_Woo_Webhook
	 */
	private $webhook;

	/**
	 * Get singleton instance.
	 *
	 * @return GiftAccess_Woo_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
	}

	/**
	 * Declare HPOS compatibility.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GIFTACCESS_WOO_FILE, true );
		}
	}

	/**
	 * Init plugin.
	 *
	 * @return void
	 */
	public function init_plugin() {
		$this->load_textdomain();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->includes();
		$this->boot_components();
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		load_plugin_textdomain( 'giftaccess-for-woo', false, dirname( plugin_basename( GIFTACCESS_WOO_FILE ) ) . '/languages' );
	}

	/**
	 * Include classes.
	 *
	 * @return void
	 */
	private function includes() {
		require_once GIFTACCESS_WOO_PATH . 'includes/class-logger.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-api.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-webhook.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-settings.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-product-fields.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-frontend-fields.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-order-processor.php';
		require_once GIFTACCESS_WOO_PATH . 'includes/class-admin-orders.php';
	}

	/**
	 * Boot components.
	 *
	 * @return void
	 */
	private function boot_components() {
		$this->logger          = new GiftAccess_Woo_Logger();
		$this->api             = new GiftAccess_Woo_API( $this->logger );
		$this->product_fields  = new GiftAccess_Woo_Product_Fields();
		$this->frontend_fields = new GiftAccess_Woo_Frontend_Fields();
		$this->order_processor = new GiftAccess_Woo_Order_Processor( $this->api, $this->logger );
		$this->admin_orders    = new GiftAccess_Woo_Admin_Orders( $this->api, $this->logger, $this->order_processor );
		$this->webhook         = new GiftAccess_Woo_Webhook( $this->logger, $this->order_processor );

		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_order_item_meta' ) );
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( $this, 'filter_formatted_order_item_meta' ), 10, 2 );

		if ( class_exists( 'GiftAccess_Woo_Settings' ) ) {
			$this->settings = new GiftAccess_Woo_Settings( $this->api, $this->logger );
		} else {
			add_action( 'admin_notices', array( $this, 'settings_class_missing_notice' ) );
		}
	}

	/**
	 * Hide internal Gift Access delivery metadata from WooCommerce order screens.
	 *
	 * @param array $hidden Hidden meta keys.
	 * @return array
	 */
	public function hide_internal_order_item_meta( $hidden ) {
		$hidden = is_array( $hidden ) ? $hidden : array();

		$suffixes = array(
			'amount',
			'amount_currency',
			'amount_type',
			'auto_delivery',
			'client_reference',
			'code',
			'country',
			'currency',
			'customer_amount',
			'customer_amount_currency',
			'date',
			'enabled',
			'error_data',
			'final_recipient_email',
			'final_recipient_name',
			'fixed_amount',
			'last_attempt',
			'last_error',
			'max_amount',
			'min_amount',
			'operator_brand',
			'payload',
			'pin',
			'product_id',
			'reference',
			'response',
			'service',
			'service_type',
			'source_variation_id',
			'status',
			'unit_rate',
			'unique_key',
		);

		foreach ( $suffixes as $suffix ) {
			$hidden[] = '_giftaccess_woo_' . $suffix;
			$hidden[] = '_giftaccess_' . $suffix;
		}

		return array_values( array_unique( $hidden ) );
	}

	/**
	 * Remove internal delivery metadata from formatted WooCommerce item meta.
	 *
	 * @param array $formatted_meta Formatted meta rows.
	 * @return array
	 */
	public function filter_formatted_order_item_meta( $formatted_meta ) {
		if ( ! is_array( $formatted_meta ) ) {
			return $formatted_meta;
		}

		foreach ( $formatted_meta as $index => $meta ) {
			$key = isset( $meta->key ) ? (string) $meta->key : '';

			if ( 0 === strpos( $key, '_giftaccess_woo_' ) || 0 === strpos( $key, '_giftaccess_' ) ) {
				unset( $formatted_meta[ $index ] );
			}
		}

		return $formatted_meta;
	}

	/**
	 * WooCommerce missing notice.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'GiftAccess for Woo nécessite WooCommerce pour fonctionner.', 'giftaccess-for-woo' ) . '</p></div>';
	}

	/**
	 * Settings class missing notice.
	 *
	 * @return void
	 */
	public function settings_class_missing_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'GiftAccess for Woo : la page de réglages WooCommerce est indisponible (WC_Settings_Page introuvable).', 'giftaccess-for-woo' ) . '</p></div>';
	}
}
