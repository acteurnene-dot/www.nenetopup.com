<?php
/**
 * Incoming Gift Access webhook endpoint.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Webhook {

	const ROUTE_NAMESPACE = 'giftaccess-for-woo/v1';
	const ROUTE_PATH      = '/webhook/order-updated';
	const SECRET_OPTION   = 'giftaccess_woo_webhook_secret';

	/**
	 * Logger handler.
	 *
	 * @var GiftAccess_Woo_Logger
	 */
	private $logger;

	/**
	 * Order processor.
	 *
	 * @var GiftAccess_Woo_Order_Processor
	 */
	private $order_processor;

	/**
	 * Constructor.
	 *
	 * @param GiftAccess_Woo_Logger          $logger Logger.
	 * @param GiftAccess_Woo_Order_Processor $order_processor Processor.
	 */
	public function __construct( $logger, $order_processor ) {
		$this->logger          = $logger;
		$this->order_processor = $order_processor;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Return the public webhook URL.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE_PATH );
	}

	/**
	 * Return the stored webhook secret.
	 *
	 * @return string
	 */
	public static function get_secret() {
		return trim( (string) get_option( self::SECRET_OPTION, '' ) );
	}

	/**
	 * Register REST endpoints.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_order_updated' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle Gift Access order.updated webhook.
	 *
	 * The payload only wakes up the existing delivery refresh logic. Codes stay
	 * fetched through the signed Gift Access API configured in WooCommerce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_order_updated( $request ) {
		$body      = (string) $request->get_body();
		$signature = trim( (string) $request->get_header( 'x-giftacces-signature' ) );
		$event     = trim( (string) $request->get_header( 'x-giftacces-event' ) );

		if ( ! $this->is_valid_signature( $body, $signature ) ) {
			$this->logger->warning(
				'Gift Access webhook signature rejected.',
				array(
					'event' => $event,
				)
			);

			return new WP_Error(
				'giftaccess_webhook_invalid_signature',
				__( 'Signature webhook Gift Access invalide.', 'giftaccess-for-woo' ),
				array( 'status' => 401 )
			);
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error(
				'giftaccess_webhook_invalid_json',
				__( 'Payload webhook Gift Access invalide.', 'giftaccess-for-woo' ),
				array( 'status' => 400 )
			);
		}

		$payload_event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : $event;
		if ( '' !== $payload_event && 'order.updated' !== $payload_event ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'ignored' => true,
					'event'   => $payload_event,
				)
			);
		}

		$match = $this->find_order_item_from_payload( $payload );
		if ( is_wp_error( $match ) ) {
			$this->logger->warning(
				'Gift Access webhook could not be matched to a WooCommerce line.',
				array(
					'event'     => $payload_event,
					'order_id'  => isset( $payload['order_id'] ) ? $payload['order_id'] : '',
					'reference' => isset( $payload['reference'] ) ? $payload['reference'] : '',
					'error'     => $match->get_error_message(),
				)
			);

			return $match;
		}

		$order_id = absint( $match['order_id'] );
		$item_id  = absint( $match['item_id'] );
		$order    = wc_get_order( $order_id );
		$item     = $order ? $order->get_item( $item_id ) : null;

		if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) || ! $this->order_processor->is_giftaccess_item( $item ) ) {
			$this->logger->warning(
				'Gift Access webhook matched a WooCommerce line that is not managed by Gift Access.',
				array(
					'event'    => $payload_event,
					'order_id' => $order_id,
					'item_id'  => $item_id,
				)
			);

			return rest_ensure_response(
				array(
					'success' => true,
					'ignored' => true,
					'reason'  => 'not_giftaccess_item',
				)
			);
		}

		$status   = $item ? (string) $item->get_meta( '_giftaccess_woo_status', true ) : '';
		$remote_order = isset( $payload['order_id'] ) ? sanitize_text_field( (string) $payload['order_id'] ) : '';
		$remote_state = isset( $payload['status'] ) ? sanitize_text_field( (string) $payload['status'] ) : '';

		if ( $item && 'success' !== $status && '' !== $remote_order && $this->is_completed_remote_status( $remote_state ) ) {
			$item->update_meta_data( '_giftaccess_woo_status', 'awaiting_codes' );
			$item->update_meta_data( '_giftaccess_woo_reference', $remote_order );
			if ( ! empty( $payload['reference'] ) ) {
				$item->update_meta_data( '_giftaccess_woo_client_reference', sanitize_text_field( (string) $payload['reference'] ) );
			}
			$item->save();
			$status = 'awaiting_codes';
		}

		if ( 'awaiting_codes' === $status ) {
			$this->order_processor->refresh_pending_items_for_order( $order_id );
		}

		$this->logger->info(
			'Gift Access webhook received.',
			array(
				'event'        => $payload_event,
				'order_id'     => $order_id,
				'item_id'      => $item_id,
				'line_status'  => $status,
				'remote_order' => $remote_order,
				'remote_state' => $remote_state,
			)
		);

		return rest_ensure_response(
			array(
				'success'     => true,
				'order_id'    => $order_id,
				'item_id'     => $item_id,
				'line_status' => $status,
			)
		);
	}

	/**
	 * Validate webhook signature.
	 *
	 * @param string $body Raw JSON body.
	 * @param string $signature Incoming signature.
	 * @return bool
	 */
	private function is_valid_signature( $body, $signature ) {
		$secret = self::get_secret();
		if ( '' === $secret || '' === $signature || '' === $body ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Check if Gift Access says the order is complete/delivered.
	 *
	 * @param string $status Remote status.
	 * @return bool
	 */
	private function is_completed_remote_status( $status ) {
		return in_array(
			strtolower( trim( (string) $status ) ),
			array( 'completed', 'complete', 'delivered', 'livree', 'livrée', 'success' ),
			true
		);
	}

	/**
	 * Match webhook payload to a WooCommerce order item.
	 *
	 * @param array $payload Webhook payload.
	 * @return array|WP_Error
	 */
	private function find_order_item_from_payload( $payload ) {
		$reference    = isset( $payload['reference'] ) ? sanitize_text_field( (string) $payload['reference'] ) : '';
		$remote_order = isset( $payload['order_id'] ) ? sanitize_text_field( (string) $payload['order_id'] ) : '';

		$parsed = $this->parse_woo_reference( $reference );
		if ( $parsed ) {
			return $parsed;
		}

		if ( '' !== $remote_order ) {
			$found = $this->find_order_item_by_meta( '_giftaccess_woo_reference', $remote_order );
			if ( $found ) {
				return $found;
			}
		}

		if ( '' !== $reference ) {
			$found = $this->find_order_item_by_meta( '_giftaccess_woo_client_reference', $reference );
			if ( $found ) {
				return $found;
			}
		}

		return new WP_Error(
			'giftaccess_webhook_order_not_found',
			__( 'Commande WooCommerce introuvable pour ce webhook Gift Access.', 'giftaccess-for-woo' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Parse plugin references like woo-123-456 or giftaccess-123-456.
	 *
	 * @param string $reference Reference.
	 * @return array|null
	 */
	private function parse_woo_reference( $reference ) {
		if ( preg_match( '/^(?:woo|giftaccess)-(\d+)-(\d+)$/', trim( (string) $reference ), $matches ) ) {
			return array(
				'order_id' => absint( $matches[1] ),
				'item_id'  => absint( $matches[2] ),
			);
		}

		return null;
	}

	/**
	 * Find an order item by stored item meta.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $value    Meta value.
	 * @return array|null
	 */
	private function find_order_item_by_meta( $meta_key, $value ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT oi.order_id, oi.order_item_id
				FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
				INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
					ON oi.order_item_id = oim.order_item_id
				WHERE oim.meta_key = %s
					AND oim.meta_value = %s
				ORDER BY oi.order_item_id DESC
				LIMIT 1",
				$meta_key,
				$value
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'order_id' => absint( $row['order_id'] ),
			'item_id'  => absint( $row['order_item_id'] ),
		);
	}
}
