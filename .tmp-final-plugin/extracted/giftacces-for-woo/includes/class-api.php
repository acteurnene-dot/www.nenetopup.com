<?php
/**
 * Gift Access API client.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_API {
	/**
	 * Logger instance.
	 *
	 * @var GiftAccess_Woo_Logger
	 */
	private $logger;

	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	public function is_enabled() {
		return 'yes' === get_option( 'giftaccess_woo_enabled', 'yes' );
	}

	public function get_mode() {
		return sanitize_key( get_option( 'giftaccess_woo_mode', 'production' ) );
	}

	public function get_base_url() {
		$url = trim( (string) get_option( 'giftaccess_woo_base_url', 'https://portal.gift-access.com/api/v1' ) );

		if ( '' === $url ) {
			$url = 'https://portal.gift-access.com/api/v1';
		}

		return rtrim( esc_url_raw( $url ), '/' );
	}

	public function get_client_id() {
		return trim( (string) get_option( 'giftaccess_woo_client_id', '' ) );
	}

	public function get_client_secret() {
		return trim( (string) get_option( 'giftaccess_woo_client_secret', '' ) );
	}

	public function get_timeout() {
		$timeout = absint( get_option( 'giftaccess_woo_timeout', 30 ) );

		return max( 5, min( 120, $timeout ) );
	}

	public function get_giftcard_delivery_mode() {
		return sanitize_key( get_option( 'giftaccess_woo_giftcard_delivery_mode', 'site_email' ) );
	}

	public function use_site_email_delivery() {
		return true;
	}

	public function get_delivery_proxy_email() {
		$email = sanitize_email( get_option( 'giftaccess_woo_proxy_email', '' ) );

		if ( $email ) {
			return $email;
		}

		return get_option( 'admin_email' );
	}

	public function get_remote_account_id( $currency = '' ) {
		return 0;
	}

	public function clear_tokens() {
		delete_transient( 'giftaccess_woo_server_public_ips' );
	}

	public function get_token_state() {
		return array(
			'api_key' => array(
				'exists'     => '' !== $this->get_client_id(),
				'valid'      => '' !== $this->get_client_id(),
				'expires_at' => time() + YEAR_IN_SECONDS,
			),
			'secret'  => array(
				'exists'     => '' !== $this->get_client_secret(),
				'valid'      => '' !== $this->get_client_secret(),
				'expires_at' => time() + YEAR_IN_SECONDS,
			),
		);
	}

	public function get_public_server_ips() {
		$cached = get_transient( 'giftaccess_woo_server_public_ips' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ips       = array();
		$services  = array(
			'https://api.ipify.org?format=json',
			'https://ipv4.icanhazip.com',
		);

		foreach ( $services as $service_url ) {
			$response = wp_remote_get(
				$service_url,
				array(
					'timeout' => 8,
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$body = trim( wp_remote_retrieve_body( $response ) );
			$json = json_decode( $body, true );
			$ip   = is_array( $json ) && ! empty( $json['ip'] ) ? $json['ip'] : $body;

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ips[] = $ip;
			}
		}

		$ips = array_values( array_unique( $ips ) );
		set_transient( 'giftaccess_woo_server_public_ips', $ips, HOUR_IN_SECONDS );

		return $ips;
	}

	public function test_connection() {
		if ( '' === $this->get_client_id() || '' === $this->get_client_secret() ) {
			return array(
				'success' => false,
				'message' => __( 'Ajoutez la clé API et le secret Gift Access avant de tester la connexion.', 'giftaccess-for-woo' ),
			);
		}

		$ping = $this->request( 'GET', '/ping' );

		if ( is_wp_error( $ping ) ) {
			return array(
				'success' => false,
				'message' => $ping->get_error_message(),
			);
		}

		$balance = $this->request( 'GET', '/wallet/balance' );

		if ( is_wp_error( $balance ) ) {
			return array(
				'success' => false,
				'message' => $balance->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connexion Gift Access validée.', 'giftaccess-for-woo' ),
			'data'    => array(
				'ping'    => $ping,
				'balance' => $balance,
			),
		);
	}

	public function purchase_giftcard( $payload ) {
		$idempotency_key = '';

		if ( isset( $payload['idempotency_key'] ) ) {
			$idempotency_key = (string) $payload['idempotency_key'];
			unset( $payload['idempotency_key'] );
		}

		if ( '' === $idempotency_key && ! empty( $payload['reference'] ) ) {
			$idempotency_key = (string) $payload['reference'];
		}

		if ( '' === $idempotency_key && function_exists( 'wp_generate_uuid4' ) ) {
			$idempotency_key = wp_generate_uuid4();
		}

		if ( '' === $idempotency_key ) {
			$idempotency_key = uniqid( 'gift-access-', true );
		}

		$result = $this->request(
			'POST',
			'/orders',
			$payload,
			array(
				'Idempotency-Key' => $idempotency_key,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->extract_order_payload( $result );
	}

	public function send_airtime( $payload ) {
		return $this->purchase_giftcard( $payload );
	}

	public function get_giftcard_order( $order_id ) {
		$result = $this->request( 'GET', '/orders/' . rawurlencode( (string) $order_id ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->extract_order_payload( $result );
	}

	public function get_giftcard_redeem_codes( $order_id ) {
		$order = $this->get_giftcard_order( $order_id );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$status = strtolower( (string) $this->pick_first( $order, array( 'status', 'state' ), '' ) );

		if ( ! in_array( $status, array( 'completed', 'complete', 'delivered', 'livree', 'livrée', 'success' ), true ) ) {
			return new WP_Error(
				'codes_not_found',
				__( 'Livraison Gift Access pas encore disponible.', 'giftaccess-for-woo' ),
				array( 'order' => $order )
			);
		}

		$cards = $this->normalize_delivery_cards( $order );

		if ( empty( $cards ) ) {
			return new WP_Error(
				'codes_not_found',
				__( 'Aucun code de livraison retourné par Gift Access pour le moment.', 'giftaccess-for-woo' ),
				array( 'order' => $order )
			);
		}

		return array(
			'order' => $order,
			'cards' => $cards,
			'code'  => isset( $cards[0]['code'] ) ? $cards[0]['code'] : '',
			'pin'   => isset( $cards[0]['pin'] ) ? $cards[0]['pin'] : '',
		);
	}

	public function get_accounts() {
		return $this->request( 'GET', '/wallet/balance' );
	}

	private function request( $method, $endpoint, $payload = array(), $extra_headers = array() ) {
		$method   = strtoupper( (string) $method );
		$endpoint = '/' . ltrim( (string) $endpoint, '/' );
		$body     = '';

		if ( ! empty( $payload ) ) {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		}

		$timestamp = (string) time();
		$headers   = array_merge(
			array(
				'Accept'          => 'application/json',
				'Content-Type'    => 'application/json',
				'X-API-KEY'       => $this->get_client_id(),
				'X-API-TIMESTAMP' => $timestamp,
				'X-API-SIGNATURE' => $this->create_signature( $method, $endpoint, $body, $timestamp ),
			),
			$extra_headers
		);

		$args = array(
			'method'  => $method,
			'timeout' => $this->get_timeout(),
			'headers' => $headers,
		);

		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$url      = $this->get_base_url() . $endpoint;
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'Gift Access API transport error.',
				array(
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				)
			);

			return $response;
		}

		$status  = absint( wp_remote_retrieve_response_code( $response ) );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 || ( is_array( $decoded ) && isset( $decoded['success'] ) && false === (bool) $decoded['success'] ) ) {
			$message = $this->extract_error_message( $decoded, wp_remote_retrieve_response_message( $response ) );

			$this->logger->error(
				'Gift Access API request failed.',
				array(
					'endpoint' => $endpoint,
					'status'   => $status,
					'message'  => $message,
					'body'     => is_array( $decoded ) ? $decoded : $raw,
				)
			);

			return new WP_Error(
				'gift_access_api_error',
				$message,
				array(
					'status' => $status,
					'body'   => is_array( $decoded ) ? $decoded : $raw,
				)
			);
		}

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		return array(
			'raw' => $raw,
		);
	}

	private function create_signature( $method, $endpoint, $body, $timestamp ) {
		$base_path = (string) parse_url( $this->get_base_url(), PHP_URL_PATH );
		$path      = '/' . trim( $base_path, '/' ) . '/' . ltrim( $endpoint, '/' );
		$path      = preg_replace( '#/+#', '/', $path );
		$payload   = $timestamp . strtoupper( $method ) . $path . $body;

		return hash_hmac( 'sha256', $payload, $this->get_client_secret() );
	}

	private function extract_error_message( $decoded, $fallback ) {
		if ( is_array( $decoded ) ) {
			if ( ! empty( $decoded['error']['message'] ) ) {
				return (string) $decoded['error']['message'];
			}

			if ( ! empty( $decoded['message'] ) ) {
				return (string) $decoded['message'];
			}

			if ( ! empty( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				return $decoded['error'];
			}
		}

		if ( $fallback ) {
			return (string) $fallback;
		}

		return __( 'La requête Gift Access a échoué.', 'giftaccess-for-woo' );
	}

	private function extract_order_payload( $result ) {
		if ( isset( $result['order'] ) && is_array( $result['order'] ) ) {
			return $result['order'];
		}

		if ( isset( $result['data']['order'] ) && is_array( $result['data']['order'] ) ) {
			return $result['data']['order'];
		}

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			return $result['data'];
		}

		return $result;
	}

	private function normalize_delivery_cards( $order ) {
		$items = array();

		if ( isset( $order['delivery']['items'] ) && is_array( $order['delivery']['items'] ) ) {
			$items = $order['delivery']['items'];
		} elseif ( isset( $order['delivery_items'] ) && is_array( $order['delivery_items'] ) ) {
			$items = $order['delivery_items'];
		} elseif ( isset( $order['cards'] ) && is_array( $order['cards'] ) ) {
			$items = $order['cards'];
		}

		$cards = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = strtolower( (string) $this->pick_first( $item, array( 'type', 'delivery_type' ), '' ) );
			$code = '';
			$pin  = '';

			if ( 'email_pass' === $type ) {
				$code = (string) $this->pick_first( $item, array( 'login_email', 'email', 'username' ), '' );
				$pin  = (string) $this->pick_first( $item, array( 'login_password', 'password', 'pin' ), '' );
			} elseif ( 'pin' === $type ) {
				$code = (string) $this->pick_first( $item, array( 'pin_code', 'pin', 'code' ), '' );
			} else {
				$code = (string) $this->pick_first( $item, array( 'code', 'card_code', 'voucher_code', 'serial_number' ), '' );
				$pin  = (string) $this->pick_first( $item, array( 'pin', 'pin_code', 'password' ), '' );
			}

			if ( '' === $code && '' === $pin ) {
				continue;
			}

			$cards[] = array(
				'code'       => $code,
				'pin'        => $pin,
				'serial'     => (string) $this->pick_first( $item, array( 'serial_number', 'serial' ), '' ),
				'expires_at' => (string) $this->pick_first( $item, array( 'expires_at', 'expiration' ), '' ),
			);
		}

		return $cards;
	}

	private function pick_first( $source, $keys, $default = null ) {
		foreach ( $keys as $key ) {
			if ( is_array( $source ) && array_key_exists( $key, $source ) && null !== $source[ $key ] && '' !== $source[ $key ] ) {
				return $source[ $key ];
			}
		}

		return $default;
	}
}
