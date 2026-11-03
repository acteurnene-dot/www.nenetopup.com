<?php
/**
 * Logger class.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Logger {

	/**
	 * Option key for logs.
	 */
	const LOG_OPTION = 'giftaccess_woo_logs';

	/**
	 * Option key for transactions.
	 */
	const TX_OPTION = 'giftaccess_woo_transactions';

	/**
	 * Log source for WC logger.
	 */
	const SOURCE = 'giftaccess-for-woo';

	/**
	 * WC logger instance.
	 *
	 * @var WC_Logger|null
	 */
	private $wc_logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->wc_logger = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
	}

	/**
	 * Generic log method.
	 *
	 * @param string $level Log level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function log( $level, $message, $context = array() ) {
		$entry = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'level'   => sanitize_text_field( $level ),
			'message' => sanitize_text_field( $message ),
			'context' => $this->sanitize_context( $context ),
		);

		$this->append_to_option( self::LOG_OPTION, $entry );

		if ( $this->wc_logger ) {
			$method = method_exists( $this->wc_logger, $level ) ? $level : 'info';
			$this->wc_logger->{$method}( $message, array( 'source' => self::SOURCE, 'context' => $entry['context'] ) );
		}
	}

	/**
	 * Info log.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function info( $message, $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Error log.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function error( $message, $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Warning log.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public function warning( $message, $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Store transaction.
	 *
	 * @param array $transaction Transaction data.
	 * @return void
	 */
	public function add_transaction( $transaction ) {
		$entry = array(
			'time'        => gmdate( 'Y-m-d H:i:s' ),
			'order_id'    => isset( $transaction['order_id'] ) ? absint( $transaction['order_id'] ) : 0,
			'item_id'     => isset( $transaction['item_id'] ) ? absint( $transaction['item_id'] ) : 0,
			'service'     => isset( $transaction['service'] ) ? sanitize_text_field( $transaction['service'] ) : '',
			'status'      => isset( $transaction['status'] ) ? sanitize_text_field( $transaction['status'] ) : '',
			'reference'   => isset( $transaction['reference'] ) ? sanitize_text_field( $transaction['reference'] ) : '',
			'amount'      => isset( $transaction['amount'] ) ? wc_format_decimal( $transaction['amount'] ) : '',
			'currency'    => isset( $transaction['currency'] ) ? sanitize_text_field( $transaction['currency'] ) : '',
			'raw_response'=> isset( $transaction['raw_response'] ) ? wp_json_encode( $transaction['raw_response'] ) : '',
		);

		$this->append_to_option( self::TX_OPTION, $entry );
	}

	/**
	 * Get logs.
	 *
	 * @param int $limit Limit rows.
	 * @return array
	 */
	public function get_logs( $limit = 200 ) {
		return array_values( $this->get_option_items( self::LOG_OPTION, $limit, false ) );
	}

	/**
	 * Get logs while preserving storage keys.
	 *
	 * @param int $limit Limit rows.
	 * @return array
	 */
	public function get_logs_with_keys( $limit = 0 ) {
		return $this->get_option_items( self::LOG_OPTION, $limit, true );
	}

	/**
	 * Get transactions.
	 *
	 * @param int $limit Limit rows.
	 * @return array
	 */
	public function get_transactions( $limit = 200 ) {
		return array_values( $this->get_option_items( self::TX_OPTION, $limit, false ) );
	}

	/**
	 * Get transactions while preserving storage keys.
	 *
	 * @param int $limit Limit rows.
	 * @return array
	 */
	public function get_transactions_with_keys( $limit = 0 ) {
		return $this->get_option_items( self::TX_OPTION, $limit, true );
	}

	/**
	 * Delete selected log entries.
	 *
	 * @param array $keys Stored entry keys.
	 * @return int
	 */
	public function delete_logs( $keys ) {
		return $this->delete_option_entries( self::LOG_OPTION, $keys );
	}

	/**
	 * Clear all stored logs.
	 *
	 * @return void
	 */
	public function clear_logs() {
		update_option( self::LOG_OPTION, array(), false );
	}

	/**
	 * Delete selected transaction entries.
	 *
	 * @param array $keys Stored entry keys.
	 * @return int
	 */
	public function delete_transactions( $keys ) {
		return $this->delete_option_entries( self::TX_OPTION, $keys );
	}

	/**
	 * Sanitize context recursively.
	 *
	 * @param mixed $value Any value.
	 * @return mixed
	 */
	private function sanitize_context( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $child ) {
				$sanitized[ sanitize_key( (string) $key ) ] = $this->sanitize_context( $child );
			}
			return $sanitized;
		}

		if ( is_object( $value ) ) {
			return $this->sanitize_context( (array) $value );
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Append entry in option storage.
	 *
	 * @param string $option_key Option key.
	 * @param array  $entry Entry.
	 * @param int    $max_rows Max rows.
	 * @return void
	 */
	private function append_to_option( $option_key, $entry, $max_rows = 500 ) {
		$items = get_option( $option_key, array() );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$items[] = $entry;

		if ( count( $items ) > $max_rows ) {
			$items = array_slice( $items, -1 * $max_rows );
		}

		update_option( $option_key, $items, false );
	}

	/**
	 * Read option-backed entries in reverse chronological order.
	 *
	 * @param string $option_key Option key.
	 * @param int    $limit Limit rows.
	 * @param bool   $preserve_keys Preserve original keys.
	 * @return array
	 */
	private function get_option_items( $option_key, $limit = 0, $preserve_keys = false ) {
		$items = get_option( $option_key, array() );

		if ( ! is_array( $items ) ) {
			return array();
		}

		$items = array_reverse( $items, $preserve_keys );
		$limit = absint( $limit );

		if ( $limit > 0 ) {
			$items = array_slice( $items, 0, $limit, $preserve_keys );
		}

		return $items;
	}

	/**
	 * Delete stored entries by their original array keys.
	 *
	 * @param string $option_key Option key.
	 * @param array  $keys Stored entry keys.
	 * @return int
	 */
	private function delete_option_entries( $option_key, $keys ) {
		$items = get_option( $option_key, array() );
		if ( ! is_array( $items ) ) {
			return 0;
		}

		$keys = array_unique( array_map( 'absint', (array) $keys ) );
		$deleted = 0;

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $items ) ) {
				unset( $items[ $key ] );
				++$deleted;
			}
		}

		if ( $deleted > 0 ) {
			update_option( $option_key, array_values( $items ), false );
		}

		return $deleted;
	}
}
