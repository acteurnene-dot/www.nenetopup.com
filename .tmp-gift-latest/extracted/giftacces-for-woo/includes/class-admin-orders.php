<?php
/**
 * Admin order integration and manual retry tools.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GiftAccess_Woo_Admin_Orders {

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
	 * Order processor.
	 *
	 * @var GiftAccess_Woo_Order_Processor
	 */
	private $order_processor;

	/**
	 * Constructor.
	 *
	 * @param GiftAccess_Woo_API             $api API.
	 * @param GiftAccess_Woo_Logger          $logger Logger.
	 * @param GiftAccess_Woo_Order_Processor $order_processor Processor.
	 */
	public function __construct( $api, $logger, $order_processor ) {
		$this->api             = $api;
		$this->logger          = $logger;
		$this->order_processor = $order_processor;

		add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ) );
		add_action( 'admin_post_giftaccess_woo_retry_delivery', array( $this, 'handle_retry_delivery' ) );
		add_action( 'admin_post_giftaccess_woo_bulk_delete', array( $this, 'handle_bulk_delete' ) );
		add_action( 'admin_post_giftaccess_woo_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_retry_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_bulk_delete_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_clear_logs_notice' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );

		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_customer_item_meta' ), 10, 4 );
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'render_delivered_products_block' ), 20 );
		add_action( 'woocommerce_email_order_meta', array( $this, 'render_email_meta' ), 20, 4 );
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_front_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'giftaccess-for-woo-frontend',
			GIFTACCESS_WOO_URL . 'assets/css/frontend.css',
			array(),
			GIFTACCESS_WOO_VERSION
		);

		if ( class_exists( 'GiftAccess_Woo_Settings' ) && method_exists( 'GiftAccess_Woo_Settings', 'get_design_tokens_css' ) ) {
			wp_add_inline_style( 'giftaccess-for-woo-frontend', GiftAccess_Woo_Settings::get_design_tokens_css() );
		}

		wp_enqueue_script(
			'giftaccess-for-woo-frontend',
			GIFTACCESS_WOO_URL . 'assets/js/frontend.js',
			array(),
			GIFTACCESS_WOO_VERSION,
			true
		);

		wp_localize_script(
			'giftaccess-for-woo-frontend',
			'giftAccessWooFront',
			array(
				'copied'              => __( 'Copié', 'giftaccess-for-woo' ),
				'copy'                => __( 'Copier', 'giftaccess-for-woo' ),
				'copyFailed'          => __( 'Impossible de copier.', 'giftaccess-for-woo' ),
				'refreshingIn'        => __( 'Rafraîchissement automatique dans %s s.', 'giftaccess-for-woo' ),
				'refreshLimitReached' => __( 'Rafraîchissement automatique arrêté. Rechargez la page si nécessaire.', 'giftaccess-for-woo' ),
			)
		);
	}

	/**
	 * Add order meta box for Gift Access data.
	 *
	 * @return void
	 */
	public function add_order_meta_box() {
		$screen = 'shop_order';

		if (
			function_exists( 'wc_get_container' ) &&
			function_exists( 'wc_get_page_screen_id' ) &&
			class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
		) {
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
			if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
				$screen = wc_get_page_screen_id( 'shop-order' );
			}
		}

		add_meta_box(
			'giftaccess-for-woo-order-box',
			__( 'GiftAccess for Woo', 'giftaccess-for-woo' ),
			array( $this, 'render_order_meta_box' ),
			$screen,
			'normal',
			'default'
		);
	}

	/**
	 * Render order meta box.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post/order object.
	 * @return void
	 */
	public function render_order_meta_box( $post_or_order ) {
		$order = is_a( $post_or_order, 'WC_Order' ) ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Commande introuvable.', 'giftaccess-for-woo' ) . '</p>';
			return;
		}

		$this->maybe_refresh_pending_codes_for_order( $order );

		$rows = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			if ( 'yes' !== $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_enabled', 'no', false ) ) {
				continue;
			}

			$rows[] = array(
				'item_id'    => $item_id,
				'name'       => $item->get_name(),
				'service'    => (string) $item->get_meta( '_giftaccess_woo_service', true ),
				'status'     => (string) $item->get_meta( '_giftaccess_woo_status', true ),
				'reference'  => (string) $item->get_meta( '_giftaccess_woo_reference', true ),
				'code'       => (string) $item->get_meta( '_giftaccess_woo_code', true ),
				'pin'        => (string) $item->get_meta( '_giftaccess_woo_pin', true ),
				'amount'     => $this->get_requested_amount_label(
					$item,
					array(
						'amount'   => (string) $item->get_meta( '_giftaccess_woo_amount', true ),
						'currency' => (string) $item->get_meta( '_giftaccess_woo_currency', true ),
					)
				),
				'currency'   => '',
				'last_error' => (string) $item->get_meta( '_giftaccess_woo_last_error', true ),
			);
		}

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Aucun produit Gift Access dans cette commande.', 'giftaccess-for-woo' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped giftaccess-woo-order-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Produit', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Service', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Statut', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Référence', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Code / PIN', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Montant', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Erreur', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'giftaccess-for-woo' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['service'] ? ucfirst( $row['service'] ) : '-' ) . '</td>';
			echo '<td><span class="giftaccess-woo-status giftaccess-woo-status-' . esc_attr( $row['status'] ? $row['status'] : 'unknown' ) . '">' . esc_html( $row['status'] ? $row['status'] : '-' ) . '</span></td>';
			echo '<td>' . esc_html( $row['reference'] ? $row['reference'] : '-' ) . '</td>';
			echo '<td>' . esc_html( trim( $row['code'] . ( $row['pin'] ? ' / ' . $row['pin'] : '' ) ) ?: '-' ) . '</td>';
			echo '<td>' . esc_html( trim( $row['amount'] . ' ' . $row['currency'] ) ?: '-' ) . '</td>';
			echo '<td>' . esc_html( $row['last_error'] ? $row['last_error'] : '-' ) . '</td>';

			$retry_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'giftaccess_woo_retry_delivery',
						'order_id' => $order->get_id(),
						'item_id'  => $row['item_id'],
					),
					admin_url( 'admin-post.php' )
				),
				'giftaccess_woo_retry_' . $order->get_id() . '_' . $row['item_id']
			);

			echo '<td><a class="button button-small" href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Relancer la livraison', 'giftaccess-for-woo' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Handle manual retry action.
	 *
	 * @return void
	 */
	public function handle_retry_delivery() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'giftaccess-for-woo' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$item_id  = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;

		if ( ! $order_id || ! $item_id ) {
			wp_die( esc_html__( 'Paramètres invalides.', 'giftaccess-for-woo' ) );
		}

		check_admin_referer( 'giftaccess_woo_retry_' . $order_id . '_' . $item_id );

		$result = $this->order_processor->process_single_item( $order_id, $item_id, true );
		$order  = wc_get_order( $order_id );

		$redirect = $order ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'giftaccess_woo_retry'         => 'error',
					'giftaccess_woo_retry_message' => rawurlencode( $result->get_error_message() ),
				),
				$redirect
			);
		} else {
			$redirect = add_query_arg(
				array(
					'giftaccess_woo_retry' => 'success',
				),
				$redirect
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show admin notice after retry.
	 *
	 * @return void
	 */
	public function maybe_show_retry_notice() {
		if ( empty( $_GET['giftaccess_woo_retry'] ) ) {
			return;
		}

		$state = sanitize_text_field( wp_unslash( $_GET['giftaccess_woo_retry'] ) );

		if ( 'success' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Relance de livraison effectuée.', 'giftaccess-for-woo' ) . '</p></div>';
			return;
		}

		$message = ! empty( $_GET['giftaccess_woo_retry_message'] ) ? sanitize_text_field( wp_unslash( rawurldecode( $_GET['giftaccess_woo_retry_message'] ) ) ) : __( 'Une erreur est survenue.', 'giftaccess-for-woo' );
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Opportunistically refresh pending Gift Access deliveries when the order is viewed.
	 *
	 * This avoids relying only on WP-Cron for code retrieval on low-traffic sites.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True when a refresh was attempted.
	 */
	private function maybe_refresh_pending_codes_for_order( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		if ( ! $this->order_has_giftaccess_items( $order ) ) {
			return false;
		}

		$throttle_key = 'giftaccess_woo_refresh_pending_' . $order->get_id();
		if ( get_transient( $throttle_key ) ) {
			return false;
		}

		set_transient( $throttle_key, 1, 5 );
		$this->order_processor->refresh_pending_items_for_order( $order->get_id() );

		return true;
	}

	/**
	 * Add plugin admin page as a top-level menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'GiftAccess for Woo', 'giftaccess-for-woo' ),
			__( 'GiftAccess for Woo', 'giftaccess-for-woo' ),
			'manage_woocommerce',
			'giftaccess-for-woo',
			array( $this, 'render_admin_page' ),
			'dashicons-store',
			57
		);
	}

	/**
	 * Render plugin admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'giftaccess-for-woo' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'logs';
		if ( ! in_array( $tab, array( 'logs', 'transactions', 'tools' ), true ) ) {
			$tab = 'logs';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'GiftAccess for Woo', 'giftaccess-for-woo' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a class="nav-tab ' . ( 'logs' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=giftaccess-for-woo&tab=logs' ) ) . '">' . esc_html__( 'Logs API', 'giftaccess-for-woo' ) . '</a>';
		echo '<a class="nav-tab ' . ( 'transactions' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=giftaccess-for-woo&tab=transactions' ) ) . '">' . esc_html__( 'Transactions', 'giftaccess-for-woo' ) . '</a>';
		echo '<a class="nav-tab ' . ( 'tools' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=giftaccess-for-woo&tab=tools' ) ) . '">' . esc_html__( 'Outils', 'giftaccess-for-woo' ) . '</a>';
		echo '<a class="nav-tab" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=giftaccess_woo' ) ) . '">' . esc_html__( 'Réglages', 'giftaccess-for-woo' ) . '</a>';
		echo '</h2>';

		if ( 'logs' === $tab ) {
			$this->render_logs_tab();
		} elseif ( 'transactions' === $tab ) {
			$this->render_transactions_tab();
		} else {
			$this->render_tools_tab();
		}

		echo '</div>';
	}

	/**
	 * Handle bulk deletion from admin tables.
	 *
	 * @return void
	 */
	public function handle_bulk_delete() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'giftaccess-for-woo' ) );
		}

		$tab = isset( $_POST['bulk_context'] ) ? sanitize_key( wp_unslash( $_POST['bulk_context'] ) ) : 'logs';
		if ( ! in_array( $tab, array( 'logs', 'transactions' ), true ) ) {
			$tab = 'logs';
		}

		check_admin_referer( 'giftaccess_woo_bulk_delete_' . $tab );

		$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$paged       = isset( $_POST['paged'] ) ? max( 1, absint( wp_unslash( $_POST['paged'] ) ) ) : 1;
		$entry_ids   = isset( $_POST['entry_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['entry_ids'] ) ) : array();

		if ( 'delete' !== $bulk_action ) {
			wp_safe_redirect(
				$this->get_admin_page_url(
					$tab,
					array(
						'paged'                    => $paged,
						'giftaccess_woo_bulk_delete' => 'no_action',
					)
				)
			);
			exit;
		}

		if ( empty( $entry_ids ) ) {
			wp_safe_redirect(
				$this->get_admin_page_url(
					$tab,
					array(
						'paged'                    => $paged,
						'giftaccess_woo_bulk_delete' => 'empty',
					)
				)
			);
			exit;
		}

		if ( 'transactions' === $tab ) {
			$deleted        = $this->logger->delete_transactions( $entry_ids );
			$remaining_rows = count( $this->logger->get_transactions_with_keys() );
		} else {
			$deleted        = $this->logger->delete_logs( $entry_ids );
			$remaining_rows = count( $this->logger->get_logs_with_keys() );
		}

		$target_page = min( $paged, max( 1, (int) ceil( $remaining_rows / $this->get_admin_items_per_page() ) ) );

		wp_safe_redirect(
			$this->get_admin_page_url(
				$tab,
				array(
					'paged'                    => $target_page,
					'giftaccess_woo_bulk_delete' => $deleted > 0 ? 'success' : 'empty',
					'deleted_count'            => $deleted,
				)
			)
		);
		exit;
	}

	/**
	 * Clear all stored API logs.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'giftaccess-for-woo' ) );
		}

		check_admin_referer( 'giftaccess_woo_clear_logs' );

		$this->logger->clear_logs();

		wp_safe_redirect(
			$this->get_admin_page_url(
				'logs',
				array(
					'giftaccess_woo_clear_logs' => 'success',
				)
			)
		);
		exit;
	}

	/**
	 * Show notice after bulk deletion.
	 *
	 * @return void
	 */
	public function maybe_show_bulk_delete_notice() {
		if ( empty( $_GET['page'] ) || 'giftaccess-for-woo' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( empty( $_GET['giftaccess_woo_bulk_delete'] ) ) {
			return;
		}

		$state = sanitize_key( wp_unslash( $_GET['giftaccess_woo_bulk_delete'] ) );

		if ( 'success' === $state ) {
			$count = max( 1, absint( isset( $_GET['deleted_count'] ) ? wp_unslash( $_GET['deleted_count'] ) : 0 ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s number of deleted rows. */
					_n( '%s ligne supprimée.', '%s lignes supprimées.', $count, 'giftaccess-for-woo' ),
					number_format_i18n( $count )
				)
			) . '</p></div>';
			return;
		}

		if ( 'no_action' === $state ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Choisissez une action groupée avant de valider.', 'giftaccess-for-woo' ) . '</p></div>';
			return;
		}

		if ( 'empty' === $state ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Sélectionnez au moins une ligne à supprimer.', 'giftaccess-for-woo' ) . '</p></div>';
		}
	}

	/**
	 * Show notice after clearing all logs.
	 *
	 * @return void
	 */
	public function maybe_show_clear_logs_notice() {
		if ( empty( $_GET['page'] ) || 'giftaccess-for-woo' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( empty( $_GET['giftaccess_woo_clear_logs'] ) ) {
			return;
		}

		$state = sanitize_key( wp_unslash( $_GET['giftaccess_woo_clear_logs'] ) );
		if ( 'success' === $state ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tous les logs API ont été supprimés.', 'giftaccess-for-woo' ) . '</p></div>';
		}
	}

	/**
	 * Build the clear-logs admin URL with nonce.
	 *
	 * @return string
	 */
	private function get_clear_logs_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'giftaccess_woo_clear_logs',
				),
				admin_url( 'admin-post.php' )
			),
			'giftaccess_woo_clear_logs'
		);
	}

	/**
	 * Get rows per admin page.
	 *
	 * @return int
	 */
	private function get_admin_items_per_page() {
		return 20;
	}

	/**
	 * Get current paged value for plugin admin tables.
	 *
	 * @return int
	 */
	private function get_admin_current_page() {
		return isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	}

	/**
	 * Build plugin admin page URL.
	 *
	 * @param string $tab Current tab.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	private function get_admin_page_url( $tab, $args = array() ) {
		$query_args = array_merge(
			array(
				'page' => 'giftaccess-for-woo',
				'tab'  => $tab,
			),
			$args
		);

		if ( isset( $query_args['paged'] ) && (int) $query_args['paged'] <= 1 ) {
			unset( $query_args['paged'] );
		}

		return add_query_arg( $query_args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render bulk action controls and pagination.
	 *
	 * @param string $tab Current tab.
	 * @param int    $current_page Current page.
	 * @param int    $total_items Total item count.
	 * @param int    $per_page Rows per page.
	 * @param bool   $show_actions Show bulk action controls.
	 * @return void
	 */
	private function render_admin_table_nav( $tab, $current_page, $total_items, $per_page, $show_actions = true, $extra_actions_html = '' ) {
		$total_items = max( 0, absint( $total_items ) );
		$total_pages = max( 1, (int) ceil( $total_items / max( 1, $per_page ) ) );

		echo '<div class="tablenav top giftaccess-woo-tablenav">';

		if ( $show_actions ) {
			echo '<div class="alignleft actions bulkactions">';
			echo '<label class="screen-reader-text" for="bulk-action-selector-top">' . esc_html__( 'Sélectionnez une action groupée', 'giftaccess-for-woo' ) . '</label>';
			echo '<select name="bulk_action" id="bulk-action-selector-top">';
			echo '<option value="">' . esc_html__( 'Actions groupées', 'giftaccess-for-woo' ) . '</option>';
			echo '<option value="delete">' . esc_html__( 'Supprimer la sélection', 'giftaccess-for-woo' ) . '</option>';
			echo '</select>';
			echo '<button type="submit" class="button action">' . esc_html__( 'Appliquer', 'giftaccess-for-woo' ) . '</button>';
			echo '</div>';
		}

		if ( '' !== trim( (string) $extra_actions_html ) ) {
			echo '<div class="alignright actions giftaccess-woo-extra-actions">' . wp_kses_post( $extra_actions_html ) . '</div>';
		}

		echo '<div class="tablenav-pages">';
		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %s total rows. */
				_n( '%s élément', '%s éléments', $total_items, 'giftaccess-for-woo' ),
				number_format_i18n( $total_items )
			)
		) . '</span>';

		if ( $total_pages > 1 ) {
			$pagination = paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', $this->get_admin_page_url( $tab ) ),
					'format'    => '',
					'current'   => min( $current_page, $total_pages ),
					'total'     => $total_pages,
					'prev_text' => '&lsaquo;',
					'next_text' => '&rsaquo;',
					'type'      => 'plain',
				)
			);

			if ( ! empty( $pagination ) ) {
				echo '<span class="pagination-links">' . wp_kses_post( $pagination ) . '</span>';
			}
		}

		echo '</div>';
		echo '<br class="clear" />';
		echo '</div>';
	}

	/**
	 * Render logs tab.
	 *
	 * @return void
	 */
	private function render_logs_tab() {
		$per_page     = $this->get_admin_items_per_page();
		$current_page = $this->get_admin_current_page();
		$logs         = $this->logger->get_logs_with_keys();
		$total_items  = count( $logs );

		if ( 0 === $total_items ) {
			echo '<p>' . esc_html__( 'Aucun log pour le moment.', 'giftaccess-for-woo' ) . '</p>';
			return;
		}

		$current_page = min( $current_page, max( 1, (int) ceil( $total_items / $per_page ) ) );
		$logs = array_slice( $logs, ( $current_page - 1 ) * $per_page, $per_page, true );

		$clear_logs_button = '<a class="button button-secondary" href="' . esc_url( $this->get_clear_logs_url() ) . '" data-giftaccess-confirm="' . esc_attr__( 'Supprimer tous les logs API ? Cette action est irréversible.', 'giftaccess-for-woo' ) . '">' . esc_html__( 'Effacer tous les logs', 'giftaccess-for-woo' ) . '</a>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="giftaccess-woo-bulk-form">';
		echo '<input type="hidden" name="action" value="giftaccess_woo_bulk_delete" />';
		echo '<input type="hidden" name="bulk_context" value="logs" />';
		echo '<input type="hidden" name="paged" value="' . esc_attr( (string) $current_page ) . '" />';
		wp_nonce_field( 'giftaccess_woo_bulk_delete_logs' );

		$this->render_admin_table_nav( 'logs', $current_page, $total_items, $per_page, true, $clear_logs_button );

		echo '<table class="widefat striped giftaccess-woo-admin-table" data-giftaccess-bulk-table="1">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" data-giftaccess-select-all="1" aria-label="' . esc_attr__( 'Tout sélectionner', 'giftaccess-for-woo' ) . '" /></td>';
		echo '<th>' . esc_html__( 'Date UTC', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Niveau', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Contexte', 'giftaccess-for-woo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log_id => $log ) {
			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="' . esc_attr( (string) $log_id ) . '" data-giftaccess-row-checkbox="1" /></th>';
			echo '<td>' . esc_html( isset( $log['time'] ) ? $log['time'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $log['level'] ) ? strtoupper( $log['level'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $log['message'] ) ? $log['message'] : '' ) . '</td>';
			echo '<td><code class="giftaccess-woo-json-cell">' . esc_html( isset( $log['context'] ) ? wp_json_encode( $log['context'] ) : '' ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->render_admin_table_nav( 'logs', $current_page, $total_items, $per_page, false );
		echo '</form>';
	}

	/**
	 * Render transactions tab.
	 *
	 * @return void
	 */
	private function render_transactions_tab() {
		$per_page     = $this->get_admin_items_per_page();
		$current_page = $this->get_admin_current_page();
		$transactions = $this->logger->get_transactions_with_keys();
		$total_items  = count( $transactions );

		if ( 0 === $total_items ) {
			echo '<p>' . esc_html__( 'Aucune transaction enregistrée.', 'giftaccess-for-woo' ) . '</p>';
			return;
		}

		$current_page = min( $current_page, max( 1, (int) ceil( $total_items / $per_page ) ) );
		$transactions = array_slice( $transactions, ( $current_page - 1 ) * $per_page, $per_page, true );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="giftaccess-woo-bulk-form">';
		echo '<input type="hidden" name="action" value="giftaccess_woo_bulk_delete" />';
		echo '<input type="hidden" name="bulk_context" value="transactions" />';
		echo '<input type="hidden" name="paged" value="' . esc_attr( (string) $current_page ) . '" />';
		wp_nonce_field( 'giftaccess_woo_bulk_delete_transactions' );

		$this->render_admin_table_nav( 'transactions', $current_page, $total_items, $per_page, true );

		echo '<table class="widefat striped giftaccess-woo-admin-table" data-giftaccess-bulk-table="1">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" data-giftaccess-select-all="1" aria-label="' . esc_attr__( 'Tout sélectionner', 'giftaccess-for-woo' ) . '" /></td>';
		echo '<th>' . esc_html__( 'Date UTC', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Commande', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Ligne', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Service', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Statut', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Référence', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Montant', 'giftaccess-for-woo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $transactions as $tx_id => $tx ) {
			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="entry_ids[]" value="' . esc_attr( (string) $tx_id ) . '" data-giftaccess-row-checkbox="1" /></th>';
			echo '<td>' . esc_html( isset( $tx['time'] ) ? $tx['time'] : '' ) . '</td>';
			echo '<td>#' . esc_html( isset( $tx['order_id'] ) ? $tx['order_id'] : 0 ) . '</td>';
			echo '<td>' . esc_html( isset( $tx['item_id'] ) ? $tx['item_id'] : 0 ) . '</td>';
			echo '<td>' . esc_html( isset( $tx['service'] ) ? $tx['service'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $tx['status'] ) ? $tx['status'] : '' ) . '</td>';
			echo '<td>' . esc_html( isset( $tx['reference'] ) ? $tx['reference'] : '' ) . '</td>';
			echo '<td>' . esc_html( trim( ( isset( $tx['amount'] ) ? $tx['amount'] : '' ) . ' ' . ( isset( $tx['currency'] ) ? $tx['currency'] : '' ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->render_admin_table_nav( 'transactions', $current_page, $total_items, $per_page, false );
		echo '</form>';
	}

	/**
	 * Render tools tab.
	 *
	 * @return void
	 */
	private function render_tools_tab() {
		$states = $this->api->get_token_state();

		echo '<h3>' . esc_html__( 'État des accès API', 'giftaccess-for-woo' ) . '</h3>';
		echo '<ul>';
		foreach ( $states as $service => $state ) {
			echo '<li><strong>' . esc_html( ucfirst( $service ) ) . ':</strong> ';
			if ( ! $state['exists'] ) {
				echo esc_html__( 'Aucun accès configuré', 'giftaccess-for-woo' );
			} else {
				echo esc_html( $state['valid'] ? __( 'Valide', 'giftaccess-for-woo' ) : __( 'Expiré', 'giftaccess-for-woo' ) );
				echo ' (' . esc_html( gmdate( 'Y-m-d H:i:s', (int) $state['expires_at'] ) ) . ' UTC)';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<h3>' . esc_html__( 'Test de connexion API', 'giftaccess-for-woo' ) . '</h3>';
		echo '<p><button type="button" id="giftaccess-woo-test-api" class="button button-secondary">' . esc_html__( 'Tester API', 'giftaccess-for-woo' ) . '</button> <span id="giftaccess-woo-test-api-result" class="giftaccess-woo-inline-status"></span></p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=giftaccess_woo' ) ) . '">' . esc_html__( 'Ouvrir les réglages', 'giftaccess-for-woo' ) . '</a></p>';
	}

	/**
	 * Render Gift Access data on customer order pages.
	 *
	 * @param int                   $item_id Item ID.
	 * @param WC_Order_Item_Product $item Item.
	 * @param WC_Order              $order Order.
	 * @param bool                  $plain_text Plain text.
	 * @return void
	 */
	public function render_customer_item_meta( $item_id, $item, $order, $plain_text ) {
		if ( is_admin() || did_action( 'woocommerce_email_header' ) ) {
			return;
		}
	}

	/**
	 * Render delivered Gift Access products as dedicated customer block.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	public function render_delivered_products_block( $order ) {
		if ( is_admin() ) {
			return;
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$refreshed = $this->maybe_refresh_pending_codes_for_order( $order );
		if ( $refreshed ) {
			$order = wc_get_order( $order->get_id() );
			if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
				return;
			}
		}

		$rows               = array();
		$pending_rows       = array();
		$customer_brand_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === trim( $customer_brand_name ) ) {
			$customer_brand_name = __( 'notre site', 'giftaccess-for-woo' );
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $this->is_giftaccess_order_item( $item ) ) {
				continue;
			}

			$data = $this->get_item_giftaccess_data( $item );
			if ( empty( $data ) ) {
				continue;
			}

			$status = strtolower( (string) $data['status'] );

			if ( 'success' === $status ) {
				$display_code = $this->get_customer_visible_code( $data );

				if ( '' === $display_code ) {
					continue;
				}

				$rows[] = array(
					'product_name' => $item->get_name(),
					'display_code' => $display_code,
					'amount'       => $this->get_requested_amount_label( $item, $data ),
				);
				continue;
			}

			if ( in_array( $status, array( 'processing', 'awaiting_codes' ), true ) ) {
				$pending_rows[] = array(
					'product_name' => $item->get_name(),
					'amount'       => $this->get_requested_amount_label( $item, $data ),
					'status'       => $status,
					'message'      => 'awaiting_codes' === $status
						? sprintf(
							/* translators: %s: site title. */
							__( 'Achat confirmé. Le code est en cours de récupération sur %s.', 'giftaccess-for-woo' ),
							$customer_brand_name
						)
						: sprintf(
							/* translators: %s: site title. */
							__( 'Traitement de la commande en cours sur %s.', 'giftaccess-for-woo' ),
							$customer_brand_name
						),
				);
			}
		}

		if ( empty( $rows ) && empty( $pending_rows ) ) {
			return;
		}

		$order_id             = $order->get_id();
		$pending_refresh_secs = 3;
		$pending_refresh_max  = 20;

		if ( ! empty( $pending_rows ) && ! headers_sent() ) {
			nocache_headers();
		}

		$template = GIFTACCESS_WOO_PATH . 'templates/order/giftaccess-delivered-block.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Render Gift Access details in customer order emails.
	 *
	 * @param WC_Order  $order Order.
	 * @param bool      $sent_to_admin Sent to admin.
	 * @param bool      $plain_text Plain text.
	 * @param WC_Email  $email Email.
	 * @return void
	 */
	public function render_email_meta( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin ) {
			return;
		}

		$email_id = ( is_object( $email ) && isset( $email->id ) ) ? (string) $email->id : '';
		if ( 'customer_completed_order' !== $email_id ) {
			return;
		}

		$this->maybe_refresh_pending_codes_for_order( $order );

		$rows = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $this->is_giftaccess_order_item( $item ) ) {
				continue;
			}

			$data = $this->get_item_giftaccess_data( $item );
			if ( empty( $data ) ) {
				continue;
			}
			$rows[] = array(
				'name'         => $item->get_name(),
				'status'       => $data['status'],
				'reference'    => $data['reference'],
				'display_code' => $this->get_customer_visible_code( $data ),
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		if ( $plain_text ) {
			echo PHP_EOL . '=== Livraison digitale ===' . PHP_EOL;
			foreach ( $rows as $row ) {
				echo sprintf( '%1$s | %2$s | Ref: %3$s | Code: %4$s', $row['name'], $row['status'], $row['reference'], $row['display_code'] ) . PHP_EOL;
			}
			return;
		}

		echo '<h2>' . esc_html__( 'Informations de livraison digitale', 'giftaccess-for-woo' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #eee;" border="1">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Produit', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Statut', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Référence', 'giftaccess-for-woo' ) . '</th>';
		echo '<th>' . esc_html__( 'Code', 'giftaccess-for-woo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . esc_html( $row['reference'] ) . '</td>';
			echo '<td>' . esc_html( $row['display_code'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Build requested amount label.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @param array                 $data Item giftaccess data.
	 * @return string
	 */
	private function get_requested_amount_label( $item, $data ) {
		$customer_amount = (string) $item->get_meta( '_giftaccess_woo_customer_amount', true );
		$customer_ccy    = (string) $item->get_meta( '_giftaccess_woo_customer_amount_currency', true );

		if ( '' !== trim( $customer_amount ) ) {
			$currency = '' !== trim( $customer_ccy ) ? strtoupper( sanitize_text_field( $customer_ccy ) ) : 'USD';
			return wc_format_decimal( $customer_amount, 2 ) . ' ' . $currency;
		}

		$currency = isset( $data['currency'] ) ? strtoupper( sanitize_text_field( (string) $data['currency'] ) ) : '';
		$payload  = $item->get_meta( '_giftaccess_woo_payload', true );

		if ( is_array( $payload ) ) {
			$payload_amount = $this->get_requested_amount_from_payload( $payload );
			if ( $payload_amount > 0 ) {
				return wc_format_decimal( $payload_amount, 2 ) . ( $currency ? ' ' . $currency : '' );
			}
		}

		$amount = isset( $data['amount'] ) ? (string) $data['amount'] : '';

		if ( '' !== trim( $amount ) ) {
			return wc_format_decimal( $amount, 2 ) . ( $currency ? ' ' . $currency : '' );
		}

		return '-';
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
	 * Resolve the customer-facing redeem value.
	 *
	 * Customer screens and emails should show the code only. If Gift Access returns
	 * no code but provides a pin-like value, use it as a fallback so delivery
	 * does not appear empty.
	 *
	 * @param array $data Item provider data.
	 * @return string
	 */
	private function get_customer_visible_code( $data ) {
		$code = isset( $data['code'] ) ? trim( (string) $data['code'] ) : '';
		if ( '' !== $code ) {
			return $code;
		}

		$pin = isset( $data['pin'] ) ? trim( (string) $data['pin'] ) : '';
		return $pin;
	}



	/**
	 * Get normalized item Gift Access data.
	 *
	 * @param WC_Order_Item_Product $item Item.
	 * @return array
	 */
	private function get_item_giftaccess_data( $item ) {
		if ( ! $this->is_giftaccess_order_item( $item ) ) {
			return array();
		}

		$status = (string) $item->get_meta( '_giftaccess_woo_status', true );
		if ( empty( $status ) ) {
			return array();
		}

		return array(
			'service'   => (string) $item->get_meta( '_giftaccess_woo_service', true ),
			'status'    => $status,
			'reference' => (string) $item->get_meta( '_giftaccess_woo_reference', true ),
			'code'      => (string) $item->get_meta( '_giftaccess_woo_code', true ),
			'pin'       => (string) $item->get_meta( '_giftaccess_woo_pin', true ),
			'amount'    => (string) $item->get_meta( '_giftaccess_woo_amount', true ),
			'currency'  => (string) $item->get_meta( '_giftaccess_woo_currency', true ),
			'date'      => (string) $item->get_meta( '_giftaccess_woo_date', true ),
			'error'     => (string) $item->get_meta( '_giftaccess_woo_last_error', true ),
		);
	}

	/**
	 * Check whether an order contains at least one item managed by Gift Access.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function order_has_giftaccess_items( $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $this->is_giftaccess_order_item( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an order item belongs to a product managed by Gift Access.
	 *
	 * @param mixed $item Order item.
	 * @return bool
	 */
	private function is_giftaccess_order_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return false;
		}

		return 'yes' === $this->get_product_meta_with_parent_fallback( $product, '_giftaccess_woo_enabled', 'no', false );
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
