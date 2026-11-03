<?php
/**
 * Product fields for Gift Access mapping.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Product_Fields {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_variation_fields' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_fields' ), 20, 2 );
	}

	/**
	 * Add custom product fields.
	 *
	 * @return void
	 */
	public function add_fields() {
		echo '<div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_giftaccess_woo_enabled',
				'label'       => __( 'Produit Gift Access', 'giftaccess-for-woo' ),
				'description' => __( 'Active la livraison digitale automatique via l’API Gift Access pour ce produit.', 'giftaccess-for-woo' ),
			)
		);

		echo '<p class="form-field giftaccess-woo-dependent giftaccess-woo-variable-note" style="display:none;">';
		echo '<label>' . esc_html__( 'Produit variable détecté', 'giftaccess-for-woo' ) . '</label>';
		echo '<span class="description">';
		echo esc_html__( 'Pour un produit variable, définissez l’ID produit Gift Access dans chaque variation. Les champs du parent restent des valeurs par défaut partagées.', 'giftaccess-for-woo' );
		echo '</span>';
		echo '</p>';

		woocommerce_wp_select(
			array(
				'id'            => '_giftaccess_woo_service_type',
				'label'         => __( 'Type de service', 'giftaccess-for-woo' ),
				'options'       => array(
					'giftcard' => __( 'Carte cadeau', 'giftaccess-for-woo' ),
					'airtime'  => __( 'Airtime', 'giftaccess-for-woo' ),
				),
				'wrapper_class' => 'giftaccess-woo-dependent',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_giftaccess_woo_product_id',
				'label'         => __( 'ID produit Gift Access', 'giftaccess-for-woo' ),
				'description'   => __( 'Identifiant API du produit, de la variation ou de l’opérateur dans Gift Access.', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'giftaccess-woo-dependent giftaccess-woo-variable-hide',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_giftaccess_woo_variation_ref',
				'label'         => __( 'Référence variation Gift Access', 'giftaccess-for-woo' ),
				'description'   => __( 'Optionnel sauf si le produit API demande une variation_ref. Utilisez le code variation affiché dans Gift Access, pas le SKU WooCommerce.', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'giftaccess-woo-dependent giftaccess-woo-variable-hide',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_giftaccess_woo_country',
				'label'         => __( 'Pays (ISO2)', 'giftaccess-for-woo' ),
				'placeholder'   => 'US',
				'wrapper_class' => 'giftaccess-woo-dependent',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_giftaccess_woo_operator_brand',
				'label'         => __( 'Opérateur / Marque', 'giftaccess-for-woo' ),
				'placeholder'   => 'apple_us / netflix',
				'description'   => __( 'Optionnel. Exemple : apple_us, netflix ou l’identifiant interne utilisé dans Gift Access.', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'giftaccess-woo-dependent',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => '_giftaccess_woo_amount_type',
				'label'         => __( 'Type de montant', 'giftaccess-for-woo' ),
				'options'       => array(
					'fixed'   => __( 'Fixe', 'giftaccess-for-woo' ),
					'dynamic' => __( 'Dynamique', 'giftaccess-for-woo' ),
				),
				'wrapper_class' => 'giftaccess-woo-dependent',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_giftaccess_woo_fixed_amount',
				'label'             => __( 'Montant fixe', 'giftaccess-for-woo' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'wrapper_class'     => 'giftaccess-woo-dependent giftaccess-woo-amount-fixed',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_giftaccess_woo_min_amount',
				'label'             => __( 'Montant min (client)', 'giftaccess-for-woo' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => get_post_meta( get_the_ID(), '_giftaccess_woo_min_amount', true ) ? get_post_meta( get_the_ID(), '_giftaccess_woo_min_amount', true ) : '2',
				'description'       => __( 'Utilisé en mode dynamique. Exemple Apple US: 2.', 'giftaccess-for-woo' ),
				'desc_tip'          => true,
				'wrapper_class'     => 'giftaccess-woo-dependent giftaccess-woo-amount-dynamic',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_giftaccess_woo_max_amount',
				'label'             => __( 'Montant max (client)', 'giftaccess-for-woo' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => get_post_meta( get_the_ID(), '_giftaccess_woo_max_amount', true ) ? get_post_meta( get_the_ID(), '_giftaccess_woo_max_amount', true ) : '500',
				'description'       => __( 'Utilisé en mode dynamique. Exemple Apple US: 500.', 'giftaccess-for-woo' ),
				'desc_tip'          => true,
				'wrapper_class'     => 'giftaccess-woo-dependent giftaccess-woo-amount-dynamic',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => '_giftaccess_woo_amount_currency',
				'label'         => __( 'Devise du montant client', 'giftaccess-for-woo' ),
				'placeholder'   => 'USD',
				'value'         => get_post_meta( get_the_ID(), '_giftaccess_woo_amount_currency', true ) ? get_post_meta( get_the_ID(), '_giftaccess_woo_amount_currency', true ) : 'USD',
				'description'   => __( 'Devise du produit digital pour le montant saisi par le client (souvent USD).', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'giftaccess-woo-dependent giftaccess-woo-amount-dynamic',
			)
		);

		echo '<p class="form-field giftaccess-woo-dependent giftaccess-woo-amount-dynamic">';
		echo '<label>' . esc_html__( 'Prix WooCommerce (devise boutique)', 'giftaccess-for-woo' ) . '</label>';
		echo '<span class="description">';
		echo esc_html__( 'En mode dynamique : le prix WooCommerce est traité comme tarif par 1 unité du montant client dans la devise active WooCommerce. Exemple : prix produit = 150, client choisit 10 USD, total = 1500 dans la devise affichée au client.', 'giftaccess-for-woo' );
		echo '</span>';
		echo '</p>';

		woocommerce_wp_checkbox(
			array(
				'id'            => '_giftaccess_woo_auto_delivery',
				'label'         => __( 'Livraison automatique après paiement', 'giftaccess-for-woo' ),
				'wrapper_class' => 'giftaccess-woo-dependent',
				'value'         => get_post_meta( get_the_ID(), '_giftaccess_woo_auto_delivery', true ),
			)
		);

		echo '</div>';
	}

	/**
	 * Save product fields.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function save_fields( $product_id ) {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		$enabled = isset( $_POST['_giftaccess_woo_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_giftaccess_woo_enabled', $enabled );

		$service_type = isset( $_POST['_giftaccess_woo_service_type'] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_service_type'] ) ) : 'giftcard';
		if ( ! in_array( $service_type, array( 'giftcard', 'airtime' ), true ) ) {
			$service_type = 'giftcard';
		}
		update_post_meta( $product_id, '_giftaccess_woo_service_type', $service_type );

		$product_giftaccess_id = isset( $_POST['_giftaccess_woo_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_product_id'] ) ) : '';
		update_post_meta( $product_id, '_giftaccess_woo_product_id', $product_giftaccess_id );

		$variation_ref = isset( $_POST['_giftaccess_woo_variation_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_variation_ref'] ) ) : '';
		update_post_meta( $product_id, '_giftaccess_woo_variation_ref', $variation_ref );

		$country = isset( $_POST['_giftaccess_woo_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_country'] ) ) ) : '';
		update_post_meta( $product_id, '_giftaccess_woo_country', $country );

		$operator_brand = isset( $_POST['_giftaccess_woo_operator_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_operator_brand'] ) ) : '';
		update_post_meta( $product_id, '_giftaccess_woo_operator_brand', $operator_brand );

		$amount_type = isset( $_POST['_giftaccess_woo_amount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_amount_type'] ) ) : 'fixed';
		if ( ! in_array( $amount_type, array( 'fixed', 'dynamic' ), true ) ) {
			$amount_type = 'fixed';
		}
		update_post_meta( $product_id, '_giftaccess_woo_amount_type', $amount_type );

		$fixed_amount = isset( $_POST['_giftaccess_woo_fixed_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['_giftaccess_woo_fixed_amount'] ) ) : '';
		update_post_meta( $product_id, '_giftaccess_woo_fixed_amount', $fixed_amount );

		$min_amount = isset( $_POST['_giftaccess_woo_min_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['_giftaccess_woo_min_amount'] ), 2 ) : '2';
		$max_amount = isset( $_POST['_giftaccess_woo_max_amount'] ) ? wc_format_decimal( wp_unslash( $_POST['_giftaccess_woo_max_amount'] ), 2 ) : '500';

		if ( (float) $min_amount <= 0 ) {
			$min_amount = '2';
		}

		if ( (float) $max_amount <= 0 ) {
			$max_amount = '500';
		}

		if ( (float) $max_amount < (float) $min_amount ) {
			$max_amount = $min_amount;
		}

		update_post_meta( $product_id, '_giftaccess_woo_min_amount', $min_amount );
		update_post_meta( $product_id, '_giftaccess_woo_max_amount', $max_amount );

		$amount_currency = isset( $_POST['_giftaccess_woo_amount_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_amount_currency'] ) ) ) : 'USD';
		if ( empty( $amount_currency ) ) {
			$amount_currency = 'USD';
		}
		update_post_meta( $product_id, '_giftaccess_woo_amount_currency', $amount_currency );

		$auto_delivery = isset( $_POST['_giftaccess_woo_auto_delivery'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_giftaccess_woo_auto_delivery', $auto_delivery );
	}

	/**
	 * Add Gift Access override fields on each variation.
	 *
	 * Variations inherit parent Gift Access settings by default. Only the fields
	 * filled here override the parent, which avoids duplicating multiple Woo
	 * products for provider products that already have unique IDs per variation.
	 *
	 * @param int        $loop           Variation index.
	 * @param array      $variation_data Variation data.
	 * @param WP_Post    $variation      Variation post object.
	 * @return void
	 */
	public function add_variation_fields( $loop, $variation_data, $variation ) {
		$variation_id = $variation->ID;

		echo '<div class="form-row form-row-full">';
		echo '<strong>' . esc_html__( 'GiftAccess for Woo', 'giftaccess-for-woo' ) . '</strong>';
		echo '<p class="description">' . esc_html__( 'Laissez vide ou “Hériter” pour utiliser les réglages du produit parent.', 'giftaccess-for-woo' ) . '</p>';
		echo '</div>';

		woocommerce_wp_select(
			array(
				'id'            => "_giftaccess_woo_enabled_variation_{$loop}",
				'name'          => "_giftaccess_woo_enabled_variation[{$loop}]",
				'label'         => __( 'Activer Gift Access', 'giftaccess-for-woo' ),
				'value'         => $this->get_variation_meta_value( $variation_id, '_giftaccess_woo_enabled', 'inherit' ),
				'options'       => $this->get_inheritable_yes_no_options(),
				'wrapper_class' => 'form-row form-row-first',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => "_giftaccess_woo_auto_delivery_variation_{$loop}",
				'name'          => "_giftaccess_woo_auto_delivery_variation[{$loop}]",
				'label'         => __( 'Livraison auto', 'giftaccess-for-woo' ),
				'value'         => $this->get_variation_meta_value( $variation_id, '_giftaccess_woo_auto_delivery', 'inherit' ),
				'options'       => $this->get_inheritable_yes_no_options(),
				'wrapper_class' => 'form-row form-row-last',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => "_giftaccess_woo_product_id_variation_{$loop}",
				'name'          => "_giftaccess_woo_product_id_variation[{$loop}]",
				'label'         => __( 'ID produit Gift Access', 'giftaccess-for-woo' ),
				'value'         => $this->get_variation_meta_value( $variation_id, '_giftaccess_woo_product_id', '' ),
				'description'   => __( 'ID API propre à cette variation. Chaque variation Woo peut pointer vers un produit ou une variation Gift Access différente.', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => "_giftaccess_woo_variation_ref_variation_{$loop}",
				'name'          => "_giftaccess_woo_variation_ref_variation[{$loop}]",
				'label'         => __( 'Référence variation Gift Access', 'giftaccess-for-woo' ),
				'value'         => $this->get_variation_meta_value( $variation_id, '_giftaccess_woo_variation_ref', '' ),
				'description'   => __( 'Code variation_ref attendu par Gift Access pour cette dénomination. Laissez vide pour hériter du parent.', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'form-row form-row-full',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => "_giftaccess_woo_fixed_amount_variation_{$loop}",
				'name'              => "_giftaccess_woo_fixed_amount_variation[{$loop}]",
				'label'             => __( 'Montant fixe Gift Access', 'giftaccess-for-woo' ),
				'value'             => $this->get_variation_meta_value( $variation_id, '_giftaccess_woo_fixed_amount', '' ),
				'description'       => __( 'Optionnel. Utile si chaque variation correspond à une valeur fixe différente.', 'giftaccess-for-woo' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
				'wrapper_class'     => 'form-row form-row-first',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => "_giftaccess_woo_amount_currency_variation_{$loop}",
				'name'          => "_giftaccess_woo_amount_currency_variation[{$loop}]",
				'label'         => __( 'Devise Gift Access', 'giftaccess-for-woo' ),
				'value'         => $this->get_variation_meta_value( $variation_id, '_giftaccess_woo_amount_currency', '' ),
				'placeholder'   => 'USD',
				'description'   => __( 'Optionnel. Laissez vide pour hériter du parent.', 'giftaccess-for-woo' ),
				'desc_tip'      => true,
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	/**
	 * Save Gift Access variation fields.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation index.
	 * @return void
	 */
	public function save_variation_fields( $variation_id, $loop ) {
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		$enabled = isset( $_POST['_giftaccess_woo_enabled_variation'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_enabled_variation'][ $loop ] ) ) : 'inherit';
		update_post_meta( $variation_id, '_giftaccess_woo_enabled', $this->sanitize_inheritable_yes_no( $enabled ) );

		$auto_delivery = isset( $_POST['_giftaccess_woo_auto_delivery_variation'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_auto_delivery_variation'][ $loop ] ) ) : 'inherit';
		update_post_meta( $variation_id, '_giftaccess_woo_auto_delivery', $this->sanitize_inheritable_yes_no( $auto_delivery ) );

		$product_id = isset( $_POST['_giftaccess_woo_product_id_variation'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_product_id_variation'][ $loop ] ) ) : '';
		update_post_meta( $variation_id, '_giftaccess_woo_product_id', $product_id );

		$variation_ref = isset( $_POST['_giftaccess_woo_variation_ref_variation'][ $loop ] ) ? sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_variation_ref_variation'][ $loop ] ) ) : '';
		update_post_meta( $variation_id, '_giftaccess_woo_variation_ref', $variation_ref );

		$fixed_amount = isset( $_POST['_giftaccess_woo_fixed_amount_variation'][ $loop ] ) ? wc_format_decimal( wp_unslash( $_POST['_giftaccess_woo_fixed_amount_variation'][ $loop ] ) ) : '';
		update_post_meta( $variation_id, '_giftaccess_woo_fixed_amount', $fixed_amount );

		$currency = isset( $_POST['_giftaccess_woo_amount_currency_variation'][ $loop ] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['_giftaccess_woo_amount_currency_variation'][ $loop ] ) ) ) : '';
		update_post_meta( $variation_id, '_giftaccess_woo_amount_currency', $currency );
	}

	/**
	 * Get inheritable yes/no select options.
	 *
	 * @return array
	 */
	private function get_inheritable_yes_no_options() {
		return array(
			'inherit' => __( 'Hériter du parent', 'giftaccess-for-woo' ),
			'yes'     => __( 'Oui', 'giftaccess-for-woo' ),
			'no'      => __( 'Non', 'giftaccess-for-woo' ),
		);
	}

	/**
	 * Sanitize inheritable boolean select.
	 *
	 * @param string $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_inheritable_yes_no( $value ) {
		$value = sanitize_text_field( (string) $value );
		return in_array( $value, array( 'inherit', 'yes', 'no' ), true ) ? $value : 'inherit';
	}

	/**
	 * Read variation meta value.
	 *
	 * @param int    $variation_id Variation ID.
	 * @param string $meta_key     Meta key.
	 * @param string $default      Default value.
	 * @return string
	 */
	private function get_variation_meta_value( $variation_id, $meta_key, $default = '' ) {
		if ( metadata_exists( 'post', $variation_id, $meta_key ) ) {
			return (string) get_post_meta( $variation_id, $meta_key, true );
		}

		return $default;
	}
}
