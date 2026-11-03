<?php
/**
 * Order processing for automatic Gift Access delivery.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Order_Processor {

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
	 * Constructor.
	 *
	 * @param GiftAccess_Woo_API    $api API.
	 * @param GiftAccess_Woo_Logger $logger Logger.
	 */
	public function __construct( $api, $logger ) {
		$this->api    = $api;
		$this->logger = $logger;

		add_action( 'woocommerce_payment_complete', array( $this, 'handle_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_order' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_order' ), 20 );
		add_action( 'giftaccess_woo_retry_pending_codes', array( $this, 'handle_pending_codes_retry' ), 10, 2 );
	}

	/**
	 * Handle paid order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function handle_paid_order( $order_id ) {
		if ( ! $this->api->is_enabled() ) {
			return;
		}

		$this->process_order( $order_id, false );
	}

	/**
	 * Retry delivery for an existing Gift Access reference that had no code yet.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id  Item ID.
	 * @return void
	 */
	public function handle_pending_codes_retry( $order_id, $item_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}

		$item = $order->get_item( absint( $item_id ) );
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return;
		}

		$status             = (string) $item->get_meta( '_giftaccess_woo_status', true );
		$existing_code      = (string) $item->get_meta( '_giftaccess_woo_code', true );
		$existing_pin       = (string) $item->get_meta( '_giftaccess_woo_pin', true );
		$existing_reference = (string) $item->get_meta( '_giftaccess_woo_reference', true );

		$existing_reference = $this->resolve_saved_order_reference( $item, $existing_reference );

		if ( 'awaiting_codes' !== $status || '' === trim( $existing_reference ) ) {
			return;
		}

		if ( '' !== trim( $existing_code ) || '' !== trim( $existing_pin ) ) {
			$this->clear_pending_codes_retry( $order->get_id(), $item_id );
			return;
		}

		$result = $this->process_single_item( absint( $order_id ), absint( $item_id ), true );
		$this->ensure_pending_codes_retry( $order, absint( $item_id ), $result );
	}

	/**
	 * Process all eligible line items for an order.
	 *
	 * @param int  $order_id Order ID.
	 * @param bool $force Force processing.
	 * @return void
	 */
	public function process_order( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $this->is_giftaccess_item( $item ) ) {
				continue;
			}

			if ( ! $this->is_auto_delivery( $item ) && ! $force ) {
				continue;
			}

			$this->process_line_item( $order, $item_id, $item, $force );
		}

		$this->maybe_auto_complete_order( $order );
		$order->save();
	}

	/**
	 * Process a single line item (manual retry use case).
	 *
	 * @param int  $order_id Order ID.
	 * @param int  $item_id Item ID.
	 * @param bool $force Force processing.
	 * @return true|WP_Error
	 */
	public function process_single_item( $order_id, $item_id, $force = true ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'invalid_order', __( 'Commande introuvable.', 'giftaccess-for-woo' ) );
		}

		$item = $order->get_item( $item_id );
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return new WP_Error( 'invalid_item', __( 'Ligne de commande introuvable.', 'giftaccess-for-woo' ) );
		}

		if ( ! $this->is_giftaccess_item( $item ) ) {
			return new WP_Error( 'not_giftaccess', __( 'Cette ligne n’est pas un produit Gift Access.', 'giftaccess-for-woo' ) );
		}

		$result = $this->process_line_item( $order, $item_id, $item, $force );

		if ( ! is_wp_error( $result ) ) {
			$this->maybe_auto_complete_order( $order );
			$order->save();
		}

		return $result;
	}

	/**
	 * Retry only items waiting for Gift Access delivery on a given order.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function refresh_pending_items_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( ! $this->is_giftaccess_item( $item ) ) {
				continue;
			}

			if ( 'awaiting_codes' !== (string) $item->get_meta( '_giftaccess_woo_status', true ) ) {
				continue;
			}

			$existing_reference = (string) $item->get_meta( '_giftaccess_woo_reference', true );
			if ( '' === trim( $existing_reference ) ) {
				$existing_reference = $this->extract_saved_reference_from_item( $item );
			}

			if ( '' === trim( $existing_reference ) ) {
				continue;
			}

			$result = $this->process_single_item( $order_id, $item_id, true );
			$this->ensure_pending_codes_retry( $order, $item_id, $result );
		}

		$this->maybe_auto_complete_order( $order );
		$order->save();
	}

	/**
	 * Check if line item is a Gift Access product.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	public function is_giftaccess_item( $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			return false;
		}

		return 'yes' === $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_enabled', 'no', false );
	}

	/**
	 * Check auto-delivery setting.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return bool
	 */
	private function is_auto_delivery( $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			return false;
		}

		return 'yes' === $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_auto_delivery', 'no', false );
	}

	/**
	 * Process one item.
	 *
	 * @param WC_Order                 $order Order.
	 * @param int                      $item_id Item ID.
	 * @param WC_Order_Item_Product    $item Item.
	 * @param bool                     $force Force processing.
	 * @return true|WP_Error
	 */
	private function process_line_item( $order, $item_id, $item, $force = false ) {
		if ( ! $this->acquire_processing_lock( $order->get_id(), $item_id ) ) {
			if ( $force ) {
				return new WP_Error( 'delivery_locked', __( 'Une livraison est déjà en cours pour cette ligne.', 'giftaccess-for-woo' ) );
			}

			return true;
		}

		try {
			$current_status = (string) $item->get_meta( '_giftaccess_woo_status', true );
			if ( ! $force && in_array( $current_status, array( 'processing', 'success', 'awaiting_codes' ), true ) ) {
				return true;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				return new WP_Error( 'missing_product', __( 'Produit introuvable.', 'giftaccess-for-woo' ) );
			}

			$meta = $this->get_product_giftaccess_meta( $product );
			if ( empty( $meta['product_id'] ) ) {
				return $this->mark_failure(
					$order,
					$item,
					__( 'ID produit Gift Access manquant.', 'giftaccess-for-woo' ),
					$meta,
					array()
				);
			}

			$service               = $meta['service_type'];
			$existing_reference    = (string) $item->get_meta( '_giftaccess_woo_reference', true );
			$existing_reference    = $this->resolve_saved_order_reference( $item, $existing_reference );
			$existing_code         = (string) $item->get_meta( '_giftaccess_woo_code', true );
			$existing_pin          = (string) $item->get_meta( '_giftaccess_woo_pin', true );
			$final_recipient_email = $this->resolve_recipient_email( $order, $item );
			$final_recipient_name  = $this->resolve_recipient_name( $order, $item );

			if ( 'giftcard' === $service && '' !== trim( $existing_reference ) && '' === trim( $existing_code ) && '' === trim( $existing_pin ) ) {
				return $this->resume_existing_giftcard_delivery(
					$order,
					$item_id,
					$item,
					$meta,
					$existing_reference,
					$final_recipient_email,
					$final_recipient_name
				);
			}

			$payload = $this->build_payload( $order, $item, $meta );

			if ( is_wp_error( $payload ) ) {
				return $this->mark_failure( $order, $item, $payload->get_error_message(), $meta, array() );
			}

			$item->update_meta_data( '_giftaccess_woo_status', 'processing' );
			$item->update_meta_data( '_giftaccess_woo_last_attempt', gmdate( 'Y-m-d H:i:s' ) );
			$item->save();

			if ( 'giftcard' === $service ) {
				$response = $this->api->purchase_giftcard( $payload );
			} else {
				$response = $this->api->send_airtime( $payload );
			}

			if ( is_wp_error( $response ) ) {
				return $this->mark_failure( $order, $item, $response->get_error_message(), $meta, $payload, $response->get_error_data() );
			}

			$cards_data = array();
			if ( 'giftcard' === $service ) {
				$checkout_reference = $this->extract_checkout_reference( $response, $payload );
				if ( '' === $checkout_reference ) {
					return $this->mark_failure(
						$order,
						$item,
						__( 'Réponse de commande Gift Access invalide : référence manquante.', 'giftaccess-for-woo' ),
						$meta,
						$payload,
						array( 'checkout_response' => $response )
					);
				}

				$item->update_meta_data( '_giftaccess_woo_reference', $checkout_reference );
				$item->update_meta_data( '_giftaccess_woo_client_reference', isset( $payload['reference'] ) ? $payload['reference'] : '' );
				$item->update_meta_data( '_giftaccess_woo_payload', $payload );
				$item->update_meta_data( '_giftaccess_woo_response', $response );
				$item->save();

				$cards_data = $this->fetch_redeem_codes( $checkout_reference );
				if ( is_wp_error( $cards_data ) ) {
					if ( 'codes_not_found' === $cards_data->get_error_code() || $this->should_keep_pending_code_fetch( $cards_data ) ) {
						return $this->mark_pending_code_fetch(
							$order,
							$item_id,
							$item,
							$meta,
							$payload,
							$checkout_reference,
							$response,
							$cards_data->get_error_message()
						);
					}

					return $this->mark_failure(
						$order,
						$item,
						$cards_data->get_error_message(),
						$meta,
						$payload,
						array(
							'reference'         => $checkout_reference,
							'checkout_response' => $response,
						)
					);
				}

				if ( ! empty( $cards_data['order'] ) && is_array( $cards_data['order'] ) ) {
					$response = $cards_data['order'];
				}
			}

			$tx             = $this->extract_transaction_data( $service, $response, $item, $order );
			$mismatch_check = $this->check_delivered_amount_mismatch( $service, $payload, $response );

			if ( ! empty( $mismatch_check['mismatch'] ) ) {
				return $this->mark_failure(
					$order,
					$item,
					sprintf(
						/* translators: 1: requested amount 2: delivered amount. */
						__( 'Montant livré inférieur au demandé (demandé: %1$s, livré: %2$s). Vérification manuelle requise.', 'giftaccess-for-woo' ),
						wc_format_decimal( $mismatch_check['requested'], 2 ),
						wc_format_decimal( $mismatch_check['delivered'], 2 )
					),
					$meta,
					$payload,
					array(
						'requested_amount' => $mismatch_check['requested'],
						'delivered_amount' => $mismatch_check['delivered'],
						'reference'        => $tx['reference'],
					)
				);
			}

			return $this->mark_success(
				$order,
				$item_id,
				$item,
				$service,
				$payload,
				$response,
				$cards_data,
				$tx,
				$final_recipient_email,
				$final_recipient_name
			);
		} finally {
			$this->release_processing_lock( $order->get_id(), $item_id );
		}
	}

	/**
	 * Resume delivery from an existing Gift Access reference instead of creating a new order.
	 *
	 * @param WC_Order              $order Order.
	 * @param int                   $item_id Item ID.
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $meta Product meta.
	 * @param string                $reference Existing Gift Access reference.
	 * @param string                $final_recipient_email Final recipient email.
	 * @param string                $final_recipient_name Final recipient name.
	 * @return true|WP_Error
	 */
	private function resume_existing_giftcard_delivery( $order, $item_id, $item, $meta, $reference, $final_recipient_email, $final_recipient_name ) {
		$payload = $item->get_meta( '_giftaccess_woo_payload', true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$cards_data = $this->fetch_redeem_codes( $reference );
		if ( is_wp_error( $cards_data ) ) {
			if ( 'codes_not_found' === $cards_data->get_error_code() || $this->should_keep_pending_code_fetch( $cards_data ) ) {
				return $this->mark_pending_code_fetch(
					$order,
					$item_id,
					$item,
					$meta,
					$payload,
					$reference,
					$item->get_meta( '_giftaccess_woo_response', true ),
					$cards_data->get_error_message()
				);
			}

			return $this->mark_failure(
				$order,
				$item,
				$cards_data->get_error_message(),
				$meta,
				$payload,
				array( 'reference' => $reference )
			);
		}

		$response = ! empty( $cards_data['order'] ) && is_array( $cards_data['order'] ) ? $cards_data['order'] : array();
		$tx       = $this->extract_transaction_data( 'giftcard', $response, $item, $order );
		$mismatch_check = $this->check_delivered_amount_mismatch( 'giftcard', $payload, $response );

		if ( ! empty( $mismatch_check['mismatch'] ) ) {
			return $this->mark_failure(
				$order,
				$item,
				sprintf(
					/* translators: 1: requested amount 2: delivered amount. */
					__( 'Montant livré inférieur au demandé (demandé: %1$s, livré: %2$s). Vérification manuelle requise.', 'giftaccess-for-woo' ),
					wc_format_decimal( $mismatch_check['requested'], 2 ),
					wc_format_decimal( $mismatch_check['delivered'], 2 )
				),
				$meta,
				$payload,
				array(
					'requested_amount' => $mismatch_check['requested'],
					'delivered_amount' => $mismatch_check['delivered'],
					'reference'        => $tx['reference'],
				)
			);
		}

		return $this->mark_success(
			$order,
			$item_id,
			$item,
			'giftcard',
			$payload,
			$response,
			$cards_data,
			$tx,
			$final_recipient_email,
			$final_recipient_name
		);
	}

	/**
	 * Extract a previously saved Gift Access reference from legacy item meta.
	 *
	 * Older failures could store the reference inside `_giftaccess_woo_error_data`
	 * without saving `_giftaccess_woo_reference` yet. Reusing it avoids creating
	 * a second provider order during manual retry after plugin updates.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return string
	 */
	private function extract_saved_reference_from_item( $item ) {
		$response = $item->get_meta( '_giftaccess_woo_response', true );
		$reference = $this->extract_checkout_reference( $response, array() );
		if ( '' !== $reference ) {
			return $reference;
		}

		$error_data = $item->get_meta( '_giftaccess_woo_error_data', true );
		if ( ! is_array( $error_data ) ) {
			return '';
		}

		if ( ! empty( $error_data['checkout_response'] ) && is_array( $error_data['checkout_response'] ) ) {
			$reference = $this->extract_checkout_reference( $error_data['checkout_response'], array() );
			if ( '' !== $reference ) {
				return $reference;
			}
		}

		if ( ! empty( $error_data['reference'] ) ) {
			return sanitize_text_field( (string) $error_data['reference'] );
		}

		if ( ! empty( $error_data['checkout_response'] ) && is_string( $error_data['checkout_response'] ) ) {
			return sanitize_text_field( trim( $error_data['checkout_response'] ) );
		}

		return '';
	}

	/**
	 * Prefer the real Gift Access order id over the Woo reference when available.
	 *
	 * @param WC_Order_Item_Product $item      Order item.
	 * @param string                $reference Stored reference.
	 * @return string
	 */
	private function resolve_saved_order_reference( $item, $reference = '' ) {
		$reference = trim( (string) $reference );
		$saved     = $this->extract_saved_reference_from_item( $item );

		if ( '' !== $saved && ( '' === $reference || 0 === strpos( $reference, 'woo-' ) ) ) {
			return $saved;
		}

		if ( '' !== $reference ) {
			return sanitize_text_field( $reference );
		}

		return $saved;
	}

	/**
	 * Mark a Gift Access order as pending delivery retrieval instead of failed.
	 *
	 * @param WC_Order              $order Order.
	 * @param int                   $item_id Item ID.
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $meta Product meta.
	 * @param array                 $payload Request payload.
	 * @param string                $reference Gift Access order reference.
	 * @param mixed                 $response Checkout or order response.
	 * @param string                $message Pending message.
	 * @return true
	 */
	private function mark_pending_code_fetch( $order, $item_id, $item, $meta, $payload, $reference, $response, $message ) {
		$previous_status    = (string) $item->get_meta( '_giftaccess_woo_status', true );
		$previous_reference = (string) $item->get_meta( '_giftaccess_woo_reference', true );
		$should_add_note    = 'awaiting_codes' !== $previous_status || sanitize_text_field( (string) $reference ) !== sanitize_text_field( $previous_reference );

		$item->update_meta_data( '_giftaccess_woo_service', isset( $meta['service_type'] ) ? $meta['service_type'] : '' );
		$item->update_meta_data( '_giftaccess_woo_status', 'awaiting_codes' );
		$item->update_meta_data( '_giftaccess_woo_reference', sanitize_text_field( (string) $reference ) );
		$item->update_meta_data( '_giftaccess_woo_last_error', $message );
		$item->update_meta_data( '_giftaccess_woo_date', gmdate( 'Y-m-d H:i:s' ) );
		$item->update_meta_data( '_giftaccess_woo_payload', $payload );
		if ( is_array( $response ) ) {
			$item->update_meta_data( '_giftaccess_woo_response', $response );
		}
		$item->save();

		$this->schedule_pending_codes_retry( $order->get_id(), $item_id );

		if ( $should_add_note ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: product name 2: reference. */
					__( 'Commande Gift Access créée pour "%1$s" (référence : %2$s), mais la livraison n’est pas encore disponible. Une nouvelle vérification sera tentée automatiquement.', 'giftaccess-for-woo' ),
					$item->get_name(),
					$reference
				)
			);
		}

		$this->logger->warning(
			'Gift Access order created but delivery is not available yet.',
			array(
				'order_id'  => $order->get_id(),
				'item_id'   => $item_id,
				'service'   => isset( $meta['service_type'] ) ? $meta['service_type'] : '',
				'reference' => $reference,
				'message'   => $message,
			)
		);

		return true;
	}

	/**
	 * Mark a line item as delivered successfully.
	 *
	 * @param WC_Order              $order Order.
	 * @param int                   $item_id Item ID.
	 * @param WC_Order_Item_Product $item Item.
	 * @param string                $service Service type.
	 * @param array                 $payload Payload.
	 * @param array                 $response Provider response.
	 * @param array                 $cards_data Cards data.
	 * @param array                 $tx Transaction data.
	 * @param string                $final_recipient_email Final recipient email.
	 * @param string                $final_recipient_name Final recipient name.
	 * @return true
	 */
	private function mark_success( $order, $item_id, $item, $service, $payload, $response, $cards_data, $tx, $final_recipient_email, $final_recipient_name ) {
		$this->clear_pending_codes_retry( $order->get_id(), $item_id );

		if ( 'giftcard' === $service ) {
			$tx['code'] = isset( $cards_data['code'] ) ? (string) $cards_data['code'] : '';
			$tx['pin']  = isset( $cards_data['pin'] ) ? (string) $cards_data['pin'] : '';
		}

		$requested_amount = $this->get_requested_amount_from_payload( $payload );
		if ( $requested_amount > 0 ) {
			$tx['amount'] = wc_format_decimal( $requested_amount, 2 );
		}

		$item->update_meta_data( '_giftaccess_woo_service', $service );
		$item->update_meta_data( '_giftaccess_woo_status', 'success' );
		$item->update_meta_data( '_giftaccess_woo_reference', $tx['reference'] );
		$item->update_meta_data( '_giftaccess_woo_code', $tx['code'] );
		$item->update_meta_data( '_giftaccess_woo_pin', $tx['pin'] );
		$item->update_meta_data( '_giftaccess_woo_amount', $tx['amount'] );
		$item->update_meta_data( '_giftaccess_woo_currency', $tx['currency'] );
		$item->update_meta_data( '_giftaccess_woo_date', gmdate( 'Y-m-d H:i:s' ) );
		$item->update_meta_data( '_giftaccess_woo_payload', $payload );
		$item->update_meta_data( '_giftaccess_woo_response', $response );
		$item->update_meta_data( '_giftaccess_woo_final_recipient_email', sanitize_email( $final_recipient_email ) );
		$item->update_meta_data( '_giftaccess_woo_final_recipient_name', sanitize_text_field( $final_recipient_name ) );
		$item->delete_meta_data( '_giftaccess_woo_last_error' );
		$item->save();

		if ( 'giftcard' === $service && $this->api->use_site_email_delivery() ) {
			$order->add_order_note(
				__( 'Livraison digitale intégrée à l’email WooCommerce de commande terminée (customer_completed_order).', 'giftaccess-for-woo' )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: product name 2: reference. */
				__( 'Livraison Gift Access réussie pour "%1$s". Référence : %2$s', 'giftaccess-for-woo' ),
				$item->get_name(),
				$tx['reference']
			)
		);

		$this->logger->add_transaction(
			array(
				'order_id'     => $order->get_id(),
				'item_id'      => $item_id,
				'service'      => $service,
				'status'       => 'success',
				'reference'    => $tx['reference'],
				'amount'       => $tx['amount'],
				'currency'     => $tx['currency'],
				'raw_response' => $response,
			)
		);

		$this->logger->info(
			'Gift Access delivery succeeded.',
			array(
				'order_id'   => $order->get_id(),
				'item_id'    => $item_id,
				'service'    => $service,
				'reference'  => $tx['reference'],
			)
		);

		return true;
	}

	/**
	 * Check if delivered amount is lower than requested amount.
	 *
	 * Applied when response contains a comparable Gift Access face value.
	 *
	 * @param string $service Service.
	 * @param array  $payload Request payload.
	 * @param array  $response API response.
	 * @return array
	 */
	private function check_delivered_amount_mismatch( $service, $payload, $response ) {
		$result = array(
			'mismatch'  => false,
			'requested' => 0.0,
			'delivered' => 0.0,
		);

		if ( 'giftcard' !== $service ) {
			return $result;
		}

		$requested = $this->get_requested_amount_from_payload( $payload );

		if ( $requested <= 0 ) {
			return $result;
		}

		$delivered = $this->extract_first_numeric(
			$response,
			array(
				array( 'delivery', 'items', 0, 'productFaceValue' ),
				array( 'delivery', 'items', 0, 'face_value' ),
				array( 'delivery', 'items', 0, 'amount' ),
				array( 'delivery', 'face_value' ),
				array( 'delivery', 'requested_amount' ),
				array( 'items', 0, 'productFaceValue' ),
				array( 'items', 0, 'face_value' ),
				array( 'items', 0, 'amount' ),
				array( 'product', 'unitPrice' ),
				array( 'product', 'totalPrice' ),
				array( 'requested_amount' ),
				array( 'face_value' ),
			)
		);

		if ( $delivered <= 0 ) {
			return $result;
		}

		$result['requested'] = $requested;
		$result['delivered'] = $delivered;
		$result['mismatch']  = $delivered + 0.0001 < $requested;

		return $result;
	}

	/**
	 * Extract first numeric value from nested array paths.
	 *
	 * @param array $source Source array.
	 * @param array $paths  Nested paths.
	 * @return float
	 */
	private function extract_first_numeric( $source, $paths ) {
		if ( ! is_array( $source ) ) {
			return 0.0;
		}

		foreach ( $paths as $path ) {
			$value = $source;
			foreach ( $path as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					$value = null;
					break;
				}

				$value = $value[ $segment ];
			}

			if ( is_numeric( $value ) ) {
				return (float) $value;
			}
		}

		return 0.0;
	}

	/**
	 * Auto complete order when all Gift Access lines are successful.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function maybe_auto_complete_order( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( 'yes' !== get_option( 'giftaccess_woo_auto_complete_order', 'yes' ) ) {
			return;
		}

		if ( $order->has_status( 'completed' ) ) {
			return;
		}

		$has_giftaccess_item      = false;
		$all_giftaccess_success   = true;
		$contains_physical_item = false;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$is_giftaccess = 'yes' === $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_enabled', 'no', false );
			if ( $is_giftaccess ) {
				$has_giftaccess_item = true;
				$status            = (string) $item->get_meta( '_giftaccess_woo_status', true );
				if ( 'success' !== $status ) {
					$all_giftaccess_success = false;
				}
				continue;
			}

			if ( ! $product->is_virtual() && ! $product->is_downloadable() ) {
				$contains_physical_item = true;
			}
		}

		if ( ! $has_giftaccess_item || ! $all_giftaccess_success || $contains_physical_item ) {
			return;
		}

		$current_status = $order->get_status();
		if ( ! in_array( $current_status, array( 'processing', 'on-hold', 'pending' ), true ) ) {
			return;
		}

		$order->update_status(
			'completed',
			__( 'Commande passée automatiquement en terminée : livraison Gift Access réussie.', 'giftaccess-for-woo' )
		);
	}

	/**
	 * Ensure a pending-code line still has a future retry scheduled.
	 *
	 * This protects against lost retries when a scheduled action collides with
	 * another in-flight refresh and exits early because the line is locked.
	 *
	 * @param WC_Order      $order Order.
	 * @param int           $item_id Item ID.
	 * @param true|WP_Error $result Last processing result.
	 * @return void
	 */
	private function ensure_pending_codes_retry( $order, $item_id, $result = true ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order = wc_get_order( $order->get_id() );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$item = $order->get_item( absint( $item_id ) );
		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
			return;
		}

		$status        = (string) $item->get_meta( '_giftaccess_woo_status', true );
		$reference     = (string) $item->get_meta( '_giftaccess_woo_reference', true );
		$existing_code = (string) $item->get_meta( '_giftaccess_woo_code', true );
		$existing_pin  = (string) $item->get_meta( '_giftaccess_woo_pin', true );

		$reference = $this->resolve_saved_order_reference( $item, $reference );

		if ( 'awaiting_codes' !== $status || '' === trim( $reference ) ) {
			return;
		}

		if ( '' !== trim( $existing_code ) || '' !== trim( $existing_pin ) ) {
			$this->clear_pending_codes_retry( $order->get_id(), $item_id );
			return;
		}

		$force_reschedule = is_wp_error( $result ) && 'delivery_locked' === $result->get_error_code();
		$delay            = $force_reschedule ? 45 : 30;

		$this->schedule_pending_codes_retry( $order->get_id(), $item_id, $delay, $force_reschedule );
	}

	/**
	 * Decide whether a code-fetch error should keep the line pending.
	 *
	 * Once Gift Access has accepted the order reference, temporary read errors should
	 * not flip the line to failed because the code may become available shortly.
	 *
	 * @param WP_Error $error Error returned while fetching codes/order details.
	 * @return bool
	 */
	private function should_keep_pending_code_fetch( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$code    = (string) $error->get_error_code();
		$message = strtolower( trim( (string) $error->get_error_message() ) );
		$status  = 0;
		$data    = $error->get_error_data();

		if ( is_array( $data ) && ! empty( $data['status'] ) ) {
			$status = absint( $data['status'] );
		}

		if ( in_array( $code, array( 'delivery_locked', 'http_request_failed', 'invalid_order_response' ), true ) ) {
			return true;
		}

		if ( 'gift_access_api_error' === $code || preg_match( '/_api_error$/', (string) $code ) ) {
			if ( in_array( $status, array( 404, 408, 409, 423, 425, 429 ), true ) || $status >= 500 ) {
				return true;
			}
		}

		$retryable_signals = array(
			'timeout',
			'timed out',
			'too many requests',
			'rate limit',
			'temporar',
			'connection',
			'could not resolve host',
			'name lookup timed out',
			'stream error',
			'curl error',
			'service unavailable',
		);

		foreach ( $retryable_signals as $signal ) {
			if ( false !== strpos( $message, $signal ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Schedule a retry for code retrieval without creating a new provider order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id Item ID.
	 * @param int $delay Delay in seconds before the retry runs.
	 * @param bool $force_reschedule Whether to replace any existing queued retry.
	 * @return void
	 */
	private function schedule_pending_codes_retry( $order_id, $item_id, $delay = 30, $force_reschedule = false ) {
		$order_id = absint( $order_id );
		$item_id  = absint( $item_id );
		if ( $order_id <= 0 || $item_id <= 0 ) {
			return;
		}

		$args = array( $order_id, $item_id );
		$delay  = max( 10, absint( $delay ) );
		$run_at = time() + $delay;

		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( $force_reschedule && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'giftaccess_woo_retry_pending_codes', $args, 'giftaccess-for-woo' );
			} elseif ( as_has_scheduled_action( 'giftaccess_woo_retry_pending_codes', $args, 'giftaccess-for-woo' ) ) {
				return;
			}

			as_schedule_single_action( $run_at, 'giftaccess_woo_retry_pending_codes', $args, 'giftaccess-for-woo' );
			return;
		}

		if ( $force_reschedule ) {
			wp_clear_scheduled_hook( 'giftaccess_woo_retry_pending_codes', $args );
		} elseif ( wp_next_scheduled( 'giftaccess_woo_retry_pending_codes', $args ) ) {
			return;
		}

		wp_schedule_single_event( $run_at, 'giftaccess_woo_retry_pending_codes', $args );
	}

	/**
	 * Clear any queued retry for a line item.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id Item ID.
	 * @return void
	 */
	private function clear_pending_codes_retry( $order_id, $item_id ) {
		$args = array( absint( $order_id ), absint( $item_id ) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'giftaccess_woo_retry_pending_codes', $args, 'giftaccess-for-woo' );
		}

		wp_clear_scheduled_hook( 'giftaccess_woo_retry_pending_codes', $args );
	}

	/**
	 * Build a transient lock key for one order item.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id Item ID.
	 * @return string
	 */
	private function get_processing_lock_key( $order_id, $item_id ) {
		return 'giftaccess_woo_lock_' . absint( $order_id ) . '_' . absint( $item_id );
	}

	/**
	 * Acquire a short processing lock to prevent duplicate provider orders.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id Item ID.
	 * @return bool
	 */
	private function acquire_processing_lock( $order_id, $item_id ) {
		$key = $this->get_processing_lock_key( $order_id, $item_id );
		if ( get_transient( $key ) ) {
			return false;
		}

		return set_transient( $key, 1, 120 );
	}

	/**
	 * Release the processing lock.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id Item ID.
	 * @return void
	 */
	private function release_processing_lock( $order_id, $item_id ) {
		delete_transient( $this->get_processing_lock_key( $order_id, $item_id ) );
	}

	/**
	 * Mark line item as failed.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @param string                $message Error message.
	 * @param array                 $meta Product meta.
	 * @param array                 $payload Request payload.
	 * @param mixed                 $error_data Error details.
	 * @return WP_Error
	 */
	private function mark_failure( $order, $item, $message, $meta, $payload, $error_data = array() ) {
		$this->clear_pending_codes_retry( $order->get_id(), $item->get_id() );

		$item->update_meta_data( '_giftaccess_woo_service', isset( $meta['service_type'] ) ? $meta['service_type'] : '' );
		$item->update_meta_data( '_giftaccess_woo_status', 'failed' );
		$item->update_meta_data( '_giftaccess_woo_last_error', $message );
		$item->update_meta_data( '_giftaccess_woo_date', gmdate( 'Y-m-d H:i:s' ) );
		$item->update_meta_data( '_giftaccess_woo_payload', $payload );
		if ( ! empty( $error_data ) ) {
			$item->update_meta_data( '_giftaccess_woo_error_data', $error_data );
		}
		$item->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: product name 2: error. */
				__( 'Échec livraison Gift Access pour "%1$s" : %2$s', 'giftaccess-for-woo' ),
				$item->get_name(),
				$message
			)
		);

		$this->logger->error(
			'Gift Access delivery failed.',
			array(
				'order_id'   => $order->get_id(),
				'item_id'    => $item->get_id(),
				'service'    => isset( $meta['service_type'] ) ? $meta['service_type'] : '',
				'message'    => $message,
				'payload'    => $payload,
				'error_data' => $error_data,
			)
		);

		$this->logger->add_transaction(
			array(
				'order_id'     => $order->get_id(),
				'item_id'      => $item->get_id(),
				'service'      => isset( $meta['service_type'] ) ? $meta['service_type'] : '',
				'status'       => 'failed',
				'reference'    => '',
				'amount'       => '',
				'currency'     => $order->get_currency(),
				'raw_response' => array( 'error' => $message ),
			)
		);

		return new WP_Error( 'delivery_failed', $message );
	}

	/**
	 * Build payload for Gift Access endpoint.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $meta Product meta.
	 * @return array|WP_Error
	 */
	private function build_payload( $order, $item, $meta ) {
		$quantity = max( 1, min( 100, (int) $item->get_quantity() ) );

		$product_id = sanitize_text_field( (string) $meta['product_id'] );
		if ( '' === trim( $product_id ) ) {
			return new WP_Error( 'invalid_product_id', __( 'ID produit Gift Access invalide.', 'giftaccess-for-woo' ) );
		}

		$reference = sprintf( 'woo-%d-%d', $order->get_id(), $item->get_id() );
		$fields    = $this->collect_delivery_fields( $order, $item, $meta );
		$variation_ref = isset( $meta['variation_ref'] ) ? trim( (string) $meta['variation_ref'] ) : '';

		if ( '' === $variation_ref ) {
			unset( $fields['variation_ref'], $fields['variation'], $fields['variation_sku'] );
		}

		$payload = array(
			'product_id'      => $product_id,
			'quantity'        => $quantity,
			'reference'       => $reference,
			'fields'          => $fields,
		);

		if ( ! $this->should_omit_fixed_amount_for_multicurrency( $order, $item, $meta ) ) {
			$amount = $this->resolve_amount( $order, $item, $meta );

			if ( $amount <= 0 ) {
				return new WP_Error( 'invalid_amount', __( 'Montant invalide pour la commande Gift Access.', 'giftaccess-for-woo' ) );
			}

			$payload['amount'] = (float) wc_format_decimal( $amount, 2 );
		}

		if ( '' !== $variation_ref && $variation_ref !== $product_id ) {
			$payload['variation_ref'] = sanitize_text_field( $variation_ref );
		}

		$payload['idempotency_key'] = $this->build_idempotency_key( $reference, $payload );

		return $payload;
	}

	/**
	 * Build a stable idempotency key for one Woo line and one exact payload.
	 *
	 * This keeps duplicate retries safe while allowing a corrected payload for
	 * the same Woo order item after merchant configuration changes.
	 *
	 * @param string $reference Woo line reference.
	 * @param array  $payload   API payload without idempotency_key.
	 * @return string
	 */
	private function build_idempotency_key( $reference, $payload ) {
		$normalized = $this->normalize_payload_for_hash( $payload );
		$encoded    = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			$encoded = serialize( $normalized );
		}

		return sanitize_key( $reference . '-' . substr( hash( 'sha256', $encoded ), 0, 12 ) );
	}

	/**
	 * Normalize nested payload keys before hashing.
	 *
	 * @param mixed $value Payload value.
	 * @return mixed
	 */
	private function normalize_payload_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->normalize_payload_for_hash( $item );
		}

		return $value;
	}

	/**
	 * Collect checkout delivery fields for Gift Access.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $meta Product meta.
	 * @return array
	 */
	private function collect_delivery_fields( $order, $item, $meta ) {
		$fields = array(
			'recipient_name'  => $this->resolve_recipient_name( $order, $item ),
			'recipient_email' => $this->resolve_recipient_email( $order, $item ),
			'woo_order_id'    => (string) $order->get_id(),
			'woo_item_id'     => (string) $item->get_id(),
		);

		if ( ! empty( $meta['service_type'] ) ) {
			$fields['service_type'] = sanitize_text_field( (string) $meta['service_type'] );
		}

		if ( ! empty( $meta['country'] ) ) {
			$fields['country'] = sanitize_text_field( (string) $meta['country'] );
		}

		if ( ! empty( $meta['operator_brand'] ) ) {
			$fields['operator_brand'] = sanitize_text_field( (string) $meta['operator_brand'] );
		}

		foreach ( $item->get_meta_data() as $meta_data ) {
			$key = isset( $meta_data->key ) ? (string) $meta_data->key : '';
			if ( '' === $key || '_' === substr( $key, 0, 1 ) ) {
				continue;
			}

			$value = isset( $meta_data->value ) ? $meta_data->value : '';
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$value = wc_clean( (string) $value );
			if ( '' === trim( $value ) ) {
				continue;
			}

			$fields[ $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Determine whether to omit a fixed amount in multi-currency mode.
	 *
	 * In a multi-currency WooCommerce setup, using the order line total as a
	 * provider face value is unsafe when the Woo order currency differs from the
	 * Gift Access amount currency. When no explicit fixed amount is configured,
	 * omit amount and let Gift Access resolve fixed/variation pricing by product_id.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $meta Product meta.
	 * @return bool
	 */
	private function should_omit_fixed_amount_for_multicurrency( $order, $item, $meta ) {
		if ( empty( $meta['amount_type'] ) || 'fixed' !== $meta['amount_type'] ) {
			return false;
		}

		if ( ! empty( $meta['fixed_amount'] ) && (float) $meta['fixed_amount'] > 0 ) {
			return false;
		}

		$order_currency = strtoupper( sanitize_text_field( (string) $order->get_currency() ) );
		$amount_currency = ! empty( $meta['amount_currency'] ) ? strtoupper( sanitize_text_field( (string) $meta['amount_currency'] ) ) : '';

		if ( '' === $amount_currency ) {
			return false;
		}

		return $order_currency !== $amount_currency;
	}

	/**
	 * Resolve recipient name from order item custom fields or billing.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @return string
	 */
	private function resolve_recipient_name( $order, $item ) {
		$candidate_keys = array(
			'Nom Complet',
			'Nom complet',
			'Full Name',
			'Recipient Name',
			'recipient_name',
			'recipientName',
			'_recipient_name',
		);

		foreach ( $candidate_keys as $key ) {
			$value = (string) $item->get_meta( $key, true );
			if ( '' !== trim( $value ) ) {
				return sanitize_text_field( $value );
			}
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' !== $name ) {
			return sanitize_text_field( $name );
		}

		return __( 'Customer', 'giftaccess-for-woo' );
	}

	/**
	 * Resolve recipient email from order item custom fields or billing.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @return string
	 */
	private function resolve_recipient_email( $order, $item ) {
		$candidate_keys = array(
			'Adresse mail',
			'Adresse Mail',
			'Email',
			'Recipient Email',
			'recipient_email',
			'recipientEmail',
			'_recipient_email',
		);

		foreach ( $candidate_keys as $key ) {
			$value = sanitize_email( (string) $item->get_meta( $key, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return sanitize_email( $order->get_billing_email() );
	}

	/**
	 * Extract checkout reference from Gift Access checkout response.
	 *
	 * @param mixed $response API checkout response.
	 * @param array $payload Request payload.
	 * @return string
	 */
	private function extract_checkout_reference( $response, $payload ) {
		if ( is_string( $response ) && '' !== trim( $response ) ) {
			return sanitize_text_field( trim( $response, "\" \t\n\r\0\x0B" ) );
		}

		if ( is_array( $response ) ) {
			$reference = $this->pick_first( $response, array( 'order_id', 'order_number', 'requestId', 'id', 'order_reference', 'reference' ), '' );
			if ( '' !== trim( $reference ) ) {
				return sanitize_text_field( $reference );
			}
		}

		if ( ! empty( $payload['reference'] ) ) {
			return sanitize_text_field( (string) $payload['reference'] );
		}

		if ( ! empty( $payload['RequestId'] ) ) {
			return sanitize_text_field( (string) $payload['RequestId'] );
		}

		return '';
	}

	/**
	 * Fetch redeem codes from Gift Access order.
	 *
	 * @param string $transaction_reference Gift Access order reference.
	 * @return array|WP_Error
	 */
	private function fetch_redeem_codes( $transaction_reference ) {
		$transaction_reference = trim( (string) $transaction_reference );
		if ( '' === $transaction_reference ) {
			return new WP_Error( 'missing_reference', __( 'Référence transaction manquante pour récupérer la livraison.', 'giftaccess-for-woo' ) );
		}

		$attempts   = 3;
		$last_order = array();

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$result = $this->api->get_giftcard_order( $transaction_reference );

			if ( is_wp_error( $result ) ) {
				if ( $i < $attempts ) {
					usleep( 600000 );
					continue;
				}

				return $result;
			}

			if ( ! is_array( $result ) ) {
				if ( $i < $attempts ) {
					usleep( 600000 );
					continue;
				}

				return new WP_Error( 'invalid_order_response', __( 'Réponse Gift Access invalide lors de la lecture de commande.', 'giftaccess-for-woo' ) );
			}

			$last_order = $result;
			$status     = strtolower( $this->pick_first( $result, array( 'status', 'state' ), '' ) );

			if ( in_array( $status, array( 'failed', 'rejected', 'declined', 'cancelled', 'canceled', 'échouée', 'echouee' ), true ) ) {
				$message = $this->pick_first( $result, array( 'status_message', 'errorMessage', 'message' ), __( 'Commande Gift Access en échec.', 'giftaccess-for-woo' ) );
				return new WP_Error( 'gift_access_order_failed', $message );
			}

			$parsed          = $this->extract_codes_from_cards_response( $result );
			$parsed['order'] = $result;

			if ( ! empty( $parsed['code'] ) || ! empty( $parsed['pin'] ) ) {
				return $parsed;
			}

			if ( $i < $attempts ) {
				usleep( 600000 );
			}
		}

		$parsed          = $this->extract_codes_from_cards_response( $last_order );
		$parsed['order'] = $last_order;

		if ( ! empty( $parsed['code'] ) || ! empty( $parsed['pin'] ) ) {
			return $parsed;
		}

		return new WP_Error(
			'codes_not_found',
			__( 'Aucun code ou identifiant de livraison trouvé dans Gift Access pour le moment.', 'giftaccess-for-woo' )
		);
	}

	/**
	 * Extract card code / pin from API response.
	 *
	 * Supports Gift Access delivery items and legacy delivery formats.
	 *
	 * @param array $response API response.
	 * @return array
	 */
	private function extract_codes_from_cards_response( $response ) {
		$rows = array();

		if ( ! empty( $response['delivery']['items'] ) && is_array( $response['delivery']['items'] ) ) {
			$rows = $response['delivery']['items'];
		} elseif ( ! empty( $response['delivery_items'] ) && is_array( $response['delivery_items'] ) ) {
			$rows = $response['delivery_items'];
		} elseif ( ! empty( $response['items'] ) && is_array( $response['items'] ) ) {
			foreach ( $response['items'] as $item ) {
				if ( empty( $item['cards'] ) || ! is_array( $item['cards'] ) ) {
					continue;
				}

				foreach ( $item['cards'] as $card ) {
					if ( is_array( $card ) ) {
						$rows[] = $card;
					}
				}
			}
		}

		if ( empty( $rows ) && isset( $response[0] ) && is_array( $response[0] ) ) {
			$rows = $response;
		} elseif ( empty( $rows ) && isset( $response['cards'] ) && is_array( $response['cards'] ) ) {
			$rows = $response['cards'];
		} elseif ( empty( $rows ) && is_array( $response ) ) {
			$rows = array( $response );
		}

		$codes = array();
		$pins  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$type = strtolower( (string) $this->pick_first( $row, array( 'type', 'delivery_type' ), '' ) );

			if ( 'email_pass' === $type ) {
				$code = $this->pick_first( $row, array( 'login_email', 'email', 'username', 'code' ), '' );
				$pin  = $this->pick_first( $row, array( 'login_password', 'password', 'pin' ), '' );
			} elseif ( 'pin' === $type ) {
				$code = $this->pick_first( $row, array( 'pin_code', 'pin', 'code' ), '' );
				$pin  = '';
			} else {
				$code = $this->pick_first( $row, array( 'cardCode', 'cardNumber', 'card_number', 'code', 'number', 'serialNumber', 'serial_number', 'serial', 'voucherCode', 'giftCardCode' ), '' );
				$pin  = $this->pick_first( $row, array( 'pin', 'pinCode', 'pin_code', 'cardPin', 'login_password', 'password' ), '' );
			}

			if ( '' !== trim( $code ) ) {
				$codes[] = sanitize_text_field( (string) $code );
			}

			if ( '' !== trim( $pin ) ) {
				$pins[] = sanitize_text_field( (string) $pin );
			}
		}

		$codes = array_values( array_unique( $codes ) );
		$pins  = array_values( array_unique( $pins ) );

		return array(
			'code' => implode( ' | ', $codes ),
			'pin'  => implode( ' | ', $pins ),
			'raw'  => $response,
		);
	}

	/**
	 * Get Gift Access product settings.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_product_giftaccess_meta( $product ) {
		$service_type = $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_service_type', 'giftcard' );
		if ( ! in_array( $service_type, array( 'giftcard', 'airtime' ), true ) ) {
			$service_type = 'giftcard';
		}

		$country = strtoupper( (string) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_country', '' ) );

		return array(
			'service_type'   => $service_type,
			'product_id'     => (string) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_product_id', '' ),
			'variation_ref'  => sanitize_text_field( (string) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_variation_ref', '' ) ),
			'country'        => sanitize_text_field( $country ),
			'operator_brand' => sanitize_text_field( (string) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_operator_brand', '' ) ),
			'amount_type'    => sanitize_text_field( (string) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_amount_type', 'fixed' ) ),
			'fixed_amount'   => (float) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_fixed_amount', '' ),
			'min_amount'     => (float) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_min_amount', '' ),
			'max_amount'     => (float) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_max_amount', '' ),
			'amount_currency'=> strtoupper( sanitize_text_field( (string) $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_amount_currency', '' ) ) ),
		);
	}

	/**
	 * Read product meta with variation-to-parent fallback.
	 *
	 * Variation meta overrides parent when set. Empty values on variations fall
	 * back to the parent for text/number fields. The special value "inherit"
	 * also triggers fallback, which lets variation select fields use a tri-state
	 * inheritance model.
	 *
	 * @param WC_Product|int $product            Product object or ID.
	 * @param string         $meta_key           Meta key.
	 * @param mixed          $default            Default value.
	 * @param bool           $fallback_on_empty  Whether an empty variation value should fall back.
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

	/**
	 * Resolve amount from product setup and order item.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $meta Product meta.
	 * @return float
	 */
	private function resolve_amount( $order, $item, $meta ) {
		if ( isset( $meta['amount_type'] ) && 'dynamic' === $meta['amount_type'] ) {
			$customer_amount = (float) $item->get_meta( '_giftaccess_woo_customer_amount', true );
			if ( $customer_amount > 0 ) {
				$min = ! empty( $meta['min_amount'] ) ? (float) $meta['min_amount'] : 2.0;
				$max = ! empty( $meta['max_amount'] ) ? (float) $meta['max_amount'] : 500.0;

				if ( $customer_amount < $min || $customer_amount > $max ) {
					return 0;
				}

				return $customer_amount;
			}
		}

		if ( isset( $meta['amount_type'] ) && 'fixed' === $meta['amount_type'] ) {
			if ( ! empty( $meta['fixed_amount'] ) ) {
				return (float) $meta['fixed_amount'];
			}

			$variation_amount = $this->resolve_variation_face_amount( $item );
			if ( $variation_amount > 0 ) {
				return $variation_amount;
			}
		}

		$qty = max( 1, (int) $item->get_quantity() );
		return (float) wc_format_decimal( $item->get_total() / $qty, 2 );
	}

	/**
	 * Resolve fixed face value from a Woo variation before falling back to sale price.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @return float
	 */
	private function resolve_variation_face_amount( $item ) {
		$candidates = array();
		$product    = $item->get_product();

		if ( $product && $product->is_type( 'variation' ) && method_exists( $product, 'get_variation_attributes' ) ) {
			foreach ( $product->get_variation_attributes() as $key => $value ) {
				$key       = (string) $key;
				$value     = (string) $value;
				$label_key = str_replace( 'attribute_', '', $key );
				$label     = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $label_key, $product ) : $label_key;

				$candidates[] = array(
					'key'           => $key . ' ' . $label,
					'value'         => $value,
					'plain_numeric' => $this->is_face_amount_key( $key . ' ' . $label ),
				);
			}
		}

		foreach ( $item->get_meta_data() as $meta_data ) {
			if ( ! is_object( $meta_data ) || ! method_exists( $meta_data, 'get_data' ) ) {
				continue;
			}

			$data = $meta_data->get_data();
			$key  = isset( $data['key'] ) ? (string) $data['key'] : '';

			if ( '' === $key || '_' === substr( $key, 0, 1 ) ) {
				continue;
			}

			$value = isset( $data['value'] ) ? $data['value'] : '';
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$candidates[] = array(
				'key'           => $key,
				'value'         => (string) $value,
				'plain_numeric' => $this->is_face_amount_key( $key ),
			);
		}

		if ( $product ) {
			$candidates[] = array(
				'key'           => 'product_name',
				'value'         => $product->get_name(),
				'plain_numeric' => false,
			);
			$candidates[] = array(
				'key'           => 'sku',
				'value'         => $product->get_sku(),
				'plain_numeric' => false,
			);
		}

		$candidates[] = array(
			'key'           => 'item_name',
			'value'         => $item->get_name(),
			'plain_numeric' => false,
		);

		foreach ( $candidates as $candidate ) {
			$amount = $this->extract_face_amount_from_text( $candidate['value'], (bool) $candidate['plain_numeric'] );
			if ( $amount > 0 ) {
				return $amount;
			}
		}

		return 0.0;
	}

	/**
	 * Detect whether a metadata key is likely to store a card/top-up face value.
	 *
	 * @param string $key Candidate key.
	 * @return bool
	 */
	private function is_face_amount_key( $key ) {
		$key = function_exists( 'remove_accents' ) ? remove_accents( (string) $key ) : (string) $key;
		$key = strtolower( $key );

		return (bool) preg_match( '/amount|montant|value|valeur|denomination|denominacion|face|card|carte/', $key );
	}

	/**
	 * Extract a face value from text such as "20 USD" or a trusted numeric value.
	 *
	 * @param string $text Plain text.
	 * @param bool   $allow_plain_number Allow values like "20".
	 * @return float
	 */
	private function extract_face_amount_from_text( $text, $allow_plain_number = false ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text;
		$text = strtolower( str_replace( array( '_', '-' ), ' ', trim( $text ) ) );
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( '' === $text ) {
			return 0.0;
		}

		$currency = '(?:usd|us\$|\$|eur|€|gbp|£|cad|htg|gdes?|gourdes?)';

		if ( preg_match( '/(?:^|[^0-9])([0-9]+(?:[\.,][0-9]{1,2})?)\s*' . $currency . '(?:\b|$)/i', $text, $matches ) ) {
			return $this->normalize_face_amount( $matches[1] );
		}

		if ( preg_match( '/' . $currency . '\s*([0-9]+(?:[\.,][0-9]{1,2})?)(?:\b|$)/i', $text, $matches ) ) {
			return $this->normalize_face_amount( $matches[1] );
		}

		if ( $allow_plain_number && preg_match( '/^[^0-9]*([0-9]+(?:[\.,][0-9]{1,2})?)[^0-9]*$/', $text, $matches ) ) {
			return $this->normalize_face_amount( $matches[1] );
		}

		return 0.0;
	}

	/**
	 * Normalize a numeric face value.
	 *
	 * @param string $value Raw value.
	 * @return float
	 */
	private function normalize_face_amount( $value ) {
		$amount = (float) str_replace( ',', '.', (string) $value );

		if ( $amount <= 0 ) {
			return 0.0;
		}

		return (float) wc_format_decimal( $amount, 2 );
	}

	/**
	 * Get the amount originally requested in the API payload.
	 *
	 * @param array $payload Request payload.
	 * @return float
	 */
	private function get_requested_amount_from_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return 0.0;
		}

		if ( ! empty( $payload['Products'][0]['Value'] ) && is_numeric( $payload['Products'][0]['Value'] ) ) {
			return (float) $payload['Products'][0]['Value'];
		}

		if ( isset( $payload['amount'] ) && is_numeric( $payload['amount'] ) ) {
			return (float) $payload['amount'];
		}

		if ( isset( $payload['unitPrice'] ) && is_numeric( $payload['unitPrice'] ) ) {
			return (float) $payload['unitPrice'];
		}

		return 0.0;
	}

	/**
	 * Extract transaction details from API response.
	 *
	 * @param string                 $service Service.
	 * @param array                  $response API response.
	 * @param WC_Order_Item_Product  $item Item.
	 * @param WC_Order               $order Order.
	 * @return array
	 */
	private function extract_transaction_data( $service, $response, $item, $order ) {
		$reference = $this->pick_first( $response, array( 'order_id', 'order_number', 'id', 'requestId', 'transactionId', 'transaction_id', 'referenceId', 'reference' ), '' );
		$status    = $this->pick_first( $response, array( 'status', 'transactionStatus' ), 'success' );
		$amount    = $this->pick_first( $response, array( 'amount', 'value', 'unitPrice', 'requestedAmount' ), '' );
		$currency  = $this->pick_first( $response, array( 'currencyCode', 'currency', 'requestedCurrencyCode' ), $order->get_currency() );

		if ( ! empty( $response['items'][0] ) && is_array( $response['items'][0] ) ) {
			$first_item = $response['items'][0];
			if ( '' === (string) $amount && isset( $first_item['productFaceValue'] ) && is_numeric( $first_item['productFaceValue'] ) ) {
				$amount = (string) $first_item['productFaceValue'];
			}

			if ( isset( $first_item['currencyCode'] ) && '' !== trim( (string) $first_item['currencyCode'] ) ) {
				$currency = (string) $first_item['currencyCode'];
			}
		}

		$codes = $this->extract_codes_from_cards_response( $response );
		$code  = isset( $codes['code'] ) ? (string) $codes['code'] : '';
		$pin   = isset( $codes['pin'] ) ? (string) $codes['pin'] : '';

		if ( empty( $reference ) ) {
			$reference = sprintf( '%s-%d-%d', $service, $order->get_id(), $item->get_id() );
		}

		return array(
			'reference' => sanitize_text_field( (string) $reference ),
			'status'    => sanitize_text_field( (string) $status ),
			'amount'    => '' !== (string) $amount ? wc_format_decimal( (string) $amount, 2 ) : '',
			'currency'  => sanitize_text_field( (string) $currency ),
			'code'      => sanitize_text_field( (string) $code ),
			'pin'       => sanitize_text_field( (string) $pin ),
		);
	}

	/**
	 * Pick first existing key from array.
	 *
	 * @param array  $source Source data.
	 * @param array  $keys Keys list.
	 * @param string $default Default.
	 * @return string
	 */
	private function pick_first( $source, $keys, $default = '' ) {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && '' !== $source[ $key ] && null !== $source[ $key ] ) {
				return (string) $source[ $key ];
			}
		}

		return $default;
	}
}
