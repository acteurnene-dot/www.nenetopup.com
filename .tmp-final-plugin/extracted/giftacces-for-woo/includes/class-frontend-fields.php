<?php
/**
 * Frontend customer amount fields for dynamic Gift Access products.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Frontend_Fields {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_amount_field' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_amount_field' ), 10, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_amount_to_cart_item' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'render_cart_item_amount' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_dynamic_htg_price' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_amount_in_order_item' ), 10, 4 );
	}

	/**
	 * Render customer amount field on product page.
	 *
	 * @return void
	 */
	public function render_amount_field() {
		global $product;

		if ( ! $product || ! $this->is_dynamic_giftcard_product( $product ) ) {
			return;
		}

		$min      = $this->get_min_amount( $product->get_id() );
		$max      = $this->get_max_amount( $product->get_id() );
		$currency = $this->get_amount_currency( $product->get_id() );
		$value    = isset( $_POST['giftaccess_woo_amount'] ) ? wc_clean( wp_unslash( $_POST['giftaccess_woo_amount'] ) ) : '';
		$unit_rate = $this->get_visible_unit_rate( $product );
		$store_currency = get_woocommerce_currency();

		echo '<div class="giftaccess-woo-amount-field-wrapper">';

		woocommerce_form_field(
			'giftaccess_woo_amount',
			array(
				'type'              => 'number',
				'required'          => true,
				'label'             => sprintf(
					/* translators: %s currency code. */
					__( 'Montant de la carte (%s)', 'giftaccess-for-woo' ),
					$currency
				),
				'custom_attributes' => array(
					'min'  => (string) $min,
					'max'  => (string) $max,
					'step' => '1',
				),
				'input_class'       => array( 'input-text', 'qty', 'text' ),
			),
			$value
		);

		if ( $unit_rate > 0 ) {
			$display_amount = is_numeric( $value ) && (float) $value > 0 ? (float) $value : 0.0;
			$display_total  = (float) wc_format_decimal( $display_amount * $unit_rate, wc_get_price_decimals() );

			echo '<p class="form-row form-row-wide giftaccess-woo-amount-calculation" data-giftaccess-woo-calculation="1" data-unit-rate="' . esc_attr( (string) $unit_rate ) . '" data-store-currency="' . esc_attr( $store_currency ) . '" data-decimals="' . esc_attr( (string) wc_get_price_decimals() ) . '">';
			echo '<span class="giftaccess-woo-amount-calculation-label">' . esc_html__( 'Total', 'giftaccess-for-woo' ) . ' =</span> ';
			echo '<span data-giftaccess-woo-calculation-text="1">';
			echo esc_html( $this->format_total_money( $display_total, $store_currency ) );
			echo '</span>';
			echo '</p>';
		}

		echo '<p class="form-row form-row-wide giftaccess-woo-amount-help">';
		echo esc_html(
			sprintf(
				/* translators: 1: min amount 2: max amount 3: currency code. */
				__( 'Entrez un montant entre %1$s et %2$s %3$s.', 'giftaccess-for-woo' ),
				wc_format_decimal( $min, 0 ),
				wc_format_decimal( $max, 0 ),
				$currency
			)
		);
		echo '</p>';

		echo '</div>';
	}

	/**
	 * Validate customer amount on add to cart.
	 *
	 * @param bool $passed Validation flag.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity Quantity.
	 * @return bool
	 */
	public function validate_amount_field( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$product = $this->resolve_runtime_product( $product_id, $variation_id );
		if ( ! $product || ! $this->is_dynamic_giftcard_product( $product ) ) {
			return $passed;
		}

		if ( ! isset( $_POST['giftaccess_woo_amount'] ) ) {
			wc_add_notice( __( 'Veuillez saisir le montant de la carte.', 'giftaccess-for-woo' ), 'error' );
			return false;
		}

		$amount   = (float) wc_format_decimal( wp_unslash( $_POST['giftaccess_woo_amount'] ), 2 );
		$min      = $this->get_min_amount( $product->get_id() );
		$max      = $this->get_max_amount( $product->get_id() );
		$currency = $this->get_amount_currency( $product->get_id() );

		if ( $amount < $min || $amount > $max ) {
			wc_add_notice(
				sprintf(
					/* translators: 1: min amount 2: max amount 3: currency code. */
					__( 'Le montant doit être entre %1$s et %2$s %3$s.', 'giftaccess-for-woo' ),
					wc_format_decimal( $min, 0 ),
					wc_format_decimal( $max, 0 ),
					$currency
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Add customer amount in cart item data.
	 *
	 * @param array $cart_item_data Item data.
	 * @param int   $product_id Product ID.
	 * @return array
	 */
	public function add_amount_to_cart_item( $cart_item_data, $product_id, $variation_id = 0, $quantity = 1 ) {
		$product = $this->resolve_runtime_product( $product_id, $variation_id );
		if ( ! $product || ! $this->is_dynamic_giftcard_product( $product ) || ! isset( $_POST['giftaccess_woo_amount'] ) ) {
			return $cart_item_data;
		}

		$cart_item_data['giftaccess_woo_amount']          = (float) wc_format_decimal( wp_unslash( $_POST['giftaccess_woo_amount'] ), 2 );
		$cart_item_data['giftaccess_woo_amount_currency'] = $this->get_amount_currency( $product->get_id() );
		$cart_item_data['giftaccess_woo_runtime_product'] = $product->get_id();
		$cart_item_data['giftaccess_woo_unit_rate']       = $this->get_visible_unit_rate( $product );
		$cart_item_data['giftaccess_woo_unique_key']      = md5( $product->get_id() . '|' . $cart_item_data['giftaccess_woo_amount'] . '|' . microtime() );

		return $cart_item_data;
	}

	/**
	 * Render custom amount line in cart and checkout.
	 *
	 * @param array $item_data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function render_cart_item_amount( $item_data, $cart_item ) {
		if ( empty( $cart_item['giftaccess_woo_amount'] ) ) {
			return $item_data;
		}

		$currency = ! empty( $cart_item['giftaccess_woo_amount_currency'] ) ? sanitize_text_field( $cart_item['giftaccess_woo_amount_currency'] ) : 'USD';
		$item_data[] = array(
			'key'   => __( 'Montant carte', 'giftaccess-for-woo' ),
			'value' => wc_format_decimal( $cart_item['giftaccess_woo_amount'], 2 ) . ' ' . $currency,
		);

		return $item_data;
	}

	/**
	 * Apply dynamic price in cart using the current WooCommerce currency context.
	 *
	 * The product price is treated as the rate for 1 unit of the provider amount
	 * in the active WooCommerce/store currency. This keeps compatibility with
	 * multi-currency switchers that filter the current product price.
	 *
	 * @param WC_Cart $cart Cart object.
	 * @return void
	 */
	public function apply_dynamic_htg_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || empty( $cart_item['giftaccess_woo_amount'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $this->is_dynamic_giftcard_product( $product ) ) {
				continue;
			}

			$unit_rate = ! empty( $cart_item['giftaccess_woo_unit_rate'] ) ? (float) $cart_item['giftaccess_woo_unit_rate'] : 0.0;
			if ( $unit_rate <= 0 ) {
				$unit_rate = $this->get_base_unit_rate( $product );
			}

			if ( $unit_rate <= 0 ) {
				continue;
			}

			$amount   = (float) $cart_item['giftaccess_woo_amount'];
			$new_price = (float) wc_format_decimal( $unit_rate * $amount, wc_get_price_decimals() );

			$cart_item['data']->set_price( $new_price );
		}
	}

	/**
	 * Get the visible unit rate when the item is added to cart.
	 *
	 * This captures the price as seen by the customer at add-to-cart time
	 * (including sale price and currency switcher filters) and prevents the
	 * cart recalculation hook from multiplying an already-updated cart price.
	 *
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	private function get_visible_unit_rate( $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return 0.0;
		}

		$unit_rate = (float) $product->get_price();
		if ( $unit_rate <= 0 ) {
			$unit_rate = (float) $product->get_regular_price();
		}

		return $unit_rate > 0 ? (float) wc_format_decimal( $unit_rate, wc_get_price_decimals() ) : 0.0;
	}

	/**
	 * Format a store-currency amount for the product-page calculator.
	 *
	 * @param float  $amount Amount.
	 * @param string $symbol Currency symbol.
	 * @return string
	 */
	private function format_total_money( $amount, $currency ) {
		$decimals = wc_get_price_decimals();
		if ( abs( (float) $amount - round( (float) $amount ) ) < 0.00001 ) {
			$decimals = 0;
		}

		$formatted = number_format_i18n( (float) $amount, $decimals );
		$currency  = strtoupper( sanitize_text_field( (string) $currency ) );

		return trim( $formatted . ( $currency ? ' ' . $currency : '' ) );
	}

	/**
	 * Get a stable fallback unit rate from the raw Woo product data.
	 *
	 * The "edit" context bypasses cart-mutated values and most view filters,
	 * which avoids exponential price growth across repeated
	 * woocommerce_before_calculate_totals passes.
	 *
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	private function get_base_unit_rate( $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return 0.0;
		}

		$unit_rate = (float) $product->get_price( 'edit' );
		if ( $unit_rate <= 0 ) {
			$unit_rate = (float) $product->get_regular_price( 'edit' );
		}

		return $unit_rate > 0 ? (float) wc_format_decimal( $unit_rate, wc_get_price_decimals() ) : 0.0;
	}

	/**
	 * Save amount in order item metadata.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values Cart values.
	 * @param WC_Order              $order Order.
	 * @return void
	 */
	public function save_amount_in_order_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['giftaccess_woo_amount'] ) ) {
			return;
		}

		$currency = ! empty( $values['giftaccess_woo_amount_currency'] ) ? sanitize_text_field( $values['giftaccess_woo_amount_currency'] ) : 'USD';
		$amount   = (float) wc_format_decimal( $values['giftaccess_woo_amount'], 2 );

		$item->add_meta_data( '_giftaccess_woo_customer_amount', $amount, true );
		$item->add_meta_data( '_giftaccess_woo_customer_amount_currency', $currency, true );
		if ( ! empty( $values['variation_id'] ) ) {
			$item->add_meta_data( '_giftaccess_woo_source_variation_id', absint( $values['variation_id'] ), true );
		}
		$item->add_meta_data(
			__( 'Montant carte demandé', 'giftaccess-for-woo' ),
			wc_format_decimal( $amount, 2 ) . ' ' . $currency,
			true
		);
	}

	/**
	 * Check if product is a dynamic Gift Access card product.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function is_dynamic_giftcard_product( $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		$is_giftaccess  = 'yes' === $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_enabled', 'no', false );
		$service_type = $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_service_type', 'giftcard' );
		$amount_type  = $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_amount_type', 'fixed' );

		return $is_giftaccess && 'giftcard' === $service_type && 'dynamic' === $amount_type;
	}

	/**
	 * Get minimum allowed card amount.
	 *
	 * @param int $product_id Product ID.
	 * @return float
	 */
	private function get_min_amount( $product_id ) {
		$min = (float) $this->get_product_meta_with_parent_fallback( $product_id, '_giftaccess_woo_min_amount', '' );
		return $min > 0 ? $min : 2.0;
	}

	/**
	 * Get maximum allowed card amount.
	 *
	 * @param int $product_id Product ID.
	 * @return float
	 */
	private function get_max_amount( $product_id ) {
		$max = (float) $this->get_product_meta_with_parent_fallback( $product_id, '_giftaccess_woo_max_amount', '' );
		return $max > 0 ? $max : 500.0;
	}

	/**
	 * Get card amount currency (ex: USD).
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function get_amount_currency( $product_id ) {
		$currency = strtoupper( sanitize_text_field( (string) $this->get_product_meta_with_parent_fallback( $product_id, '_giftaccess_woo_amount_currency', '' ) ) );
		return $currency ? $currency : 'USD';
	}

	/**
	 * Resolve the effective Woo product for the current add-to-cart request.
	 *
	 * Variable products are submitted with both parent product ID and selected
	 * variation ID. The plugin should automatically prefer the variation when
	 * available, while keeping simple products unchanged.
	 *
	 * @param int $product_id   Parent/simple product ID.
	 * @param int $variation_id Selected variation ID.
	 * @return WC_Product|false
	 */
	private function resolve_runtime_product( $product_id, $variation_id = 0 ) {
		$variation_id = absint( $variation_id );
		if ( $variation_id > 0 ) {
			$variation_product = wc_get_product( $variation_id );
			if ( $variation_product ) {
				return $variation_product;
			}
		}

		return wc_get_product( $product_id );
	}

	/**
	 * Read product meta with variation-to-parent fallback.
	 *
	 * @param WC_Product|int $product           Product object or ID.
	 * @param string         $meta_key          Meta key.
	 * @param mixed          $default           Default value.
	 * @param bool           $fallback_on_empty Whether empty variation values inherit.
	 * @return mixed
	 */
	private function get_product_meta_with_parent_fallback( $product, $meta_key, $default = '', $fallback_on_empty = true ) {
		$product_obj = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( $product );
		if ( ! $product_obj ) {
			return $default;
		}

		$product_id = $product_obj->get_id();
		if ( metadata_exists( 'post', $product_id, $meta_key ) ) {
			$value = get_post_meta( $product_id, $meta_key, true );
			if ( 'inherit' !== $value ) {
				$has_meaningful_value = true;
				if ( $fallback_on_empty ) {
					$has_meaningful_value = ! ( is_scalar( $value ) && '' === trim( (string) $value ) );
				}

				if ( $has_meaningful_value || '0' === (string) $value ) {
					return $value;
				}
			}
		}

		$parent_id = $product_obj->get_parent_id();
		if ( $parent_id && metadata_exists( 'post', $parent_id, $meta_key ) ) {
			return get_post_meta( $parent_id, $meta_key, true );
		}

		return $default;
	}
}
