<?php
/**
 * WooCommerce settings page.
 *
 * @package GiftAccess_Woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Settings_Page' ) && defined( 'WC_ABSPATH' ) ) {
	$wc_settings_page_file = trailingslashit( WC_ABSPATH ) . 'includes/admin/settings/class-wc-settings-page.php';
	if ( file_exists( $wc_settings_page_file ) ) {
		require_once $wc_settings_page_file;
	}
}

if ( ! class_exists( 'WC_Settings_Page' ) ) {
	return;
}

class GiftAccess_Woo_Settings extends WC_Settings_Page {
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

	public function __construct( $api, $logger ) {
		$this->id    = 'giftaccess_woo';
		$this->label = __( 'GiftAccess for Woo', 'giftaccess-for-woo' );

		$this->api    = $api;
		$this->logger = $logger;

		parent::__construct();

		add_action( 'woocommerce_admin_field_giftaccess_woo_api_test', array( $this, 'render_api_test_field' ) );
		add_action( 'woocommerce_admin_field_giftaccess_woo_webhook_url', array( $this, 'render_webhook_url_field' ) );
		add_action( 'woocommerce_admin_field_giftaccess_woo_server_ip', array( $this, 'render_server_ip_field' ) );
		add_action( 'woocommerce_admin_field_giftaccess_woo_token_state', array( $this, 'render_token_state_field' ) );
		add_action( 'wp_ajax_giftaccess_woo_test_api', array( $this, 'ajax_test_api' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		foreach ( array_keys( self::get_color_defaults() ) as $option_name ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $option_name, array( $this, 'sanitize_color_option' ), 10, 3 );
		}
	}

	public function get_settings() {
		return array(
			array(
				'title' => __( 'Configuration Gift Access', 'giftaccess-for-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Connectez WooCommerce au portail Gift Access pour créer les commandes, recevoir les livraisons et suivre les statuts.', 'giftaccess-for-woo' ),
				'id'    => 'giftaccess_woo_settings_title',
			),
			array(
				'title'    => __( 'Activer le plugin', 'giftaccess-for-woo' ),
				'desc'     => __( 'Activer GiftAccess for Woo', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_enabled',
				'default'  => 'yes',
				'type'     => 'checkbox',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Mode', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_mode',
				'type'     => 'select',
				'default'  => 'production',
				'options'  => array(
					'production' => __( 'Production', 'giftaccess-for-woo' ),
					'sandbox'    => __( 'Sandbox', 'giftaccess-for-woo' ),
				),
				'desc_tip' => true,
				'desc'     => __( 'Le mode sert à organiser vos réglages WooCommerce. Les commandes passent par l’URL API renseignée ci-dessous.', 'giftaccess-for-woo' ),
			),
			array(
				'title'    => __( 'URL API Gift Access', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_base_url',
				'type'     => 'text',
				'default'  => 'https://portal.gift-access.com/api/v1',
				'css'      => 'min-width: 520px;',
				'desc'     => __( 'Exemple : https://portal.gift-access.com/api/v1', 'giftaccess-for-woo' ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Clé API Gift Access', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_client_id',
				'type'     => 'text',
				'css'      => 'min-width: 420px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Secret API Gift Access', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_client_secret',
				'type'     => 'password',
				'css'      => 'min-width: 420px;',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Timeout API (secondes)', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_timeout',
				'type'     => 'number',
				'default'  => '30',
				'custom_attributes' => array(
					'min'  => '5',
					'max'  => '120',
					'step' => '1',
				),
			),
			array(
				'title'    => __( 'Auto terminer la commande', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_auto_complete_order',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc'     => __( 'Quand toutes les lignes Gift Access sont livrées avec succès, la commande WooCommerce passe en terminée.', 'giftaccess-for-woo' ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Mode d’envoi des livraisons', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_giftcard_delivery_mode',
				'type'     => 'select',
				'default'  => 'site_email',
				'options'  => array(
					'site_email'     => __( 'Email WooCommerce de commande terminée', 'giftaccess-for-woo' ),
					'gift_access_api' => __( 'Livraison uniquement dans les détails de commande', 'giftaccess-for-woo' ),
				),
				'desc'     => __( 'Le plugin récupère les données de livraison depuis Gift Access et les rattache à la commande WooCommerce.', 'giftaccess-for-woo' ),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Email de livraison par défaut', 'giftaccess-for-woo' ),
				'id'       => 'giftaccess_woo_proxy_email',
				'type'     => 'text',
				'default'  => get_option( 'admin_email' ),
				'desc'     => __( 'Utilisé seulement si une livraison exige un email et que la commande WooCommerce n’en fournit pas.', 'giftaccess-for-woo' ),
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'giftaccess_woo_settings_title',
			),
			array(
				'title' => __( 'Webhook Gift Access', 'giftaccess-for-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Collez cette URL dans le portail Gift Access, puis collez ici le secret webhook généré côté Gift Access.', 'giftaccess-for-woo' ),
				'id'    => 'giftaccess_woo_webhook_title',
			),
			array(
				'title' => __( 'URL webhook WooCommerce', 'giftaccess-for-woo' ),
				'type'  => 'giftaccess_woo_webhook_url',
				'id'    => 'giftaccess_woo_webhook_url',
			),
			array(
				'title'    => __( 'Secret webhook Gift Access', 'giftaccess-for-woo' ),
				'id'       => GiftAccess_Woo_Webhook::SECRET_OPTION,
				'type'     => 'password',
				'css'      => 'min-width: 420px;',
				'desc'     => __( 'Secret affiché une seule fois par le portail Gift Access quand vous générez ou renouvelez le webhook.', 'giftaccess-for-woo' ),
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'giftaccess_woo_webhook_title',
			),
			array(
				'title' => __( 'Apparence Gift Access', 'giftaccess-for-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Personnalisez les couleurs affichées dans les blocs de livraison, les boutons et le champ montant côté client.', 'giftaccess-for-woo' ),
				'id'    => 'giftaccess_woo_appearance_title',
			),
			array(
				'title'   => __( 'Couleur principale', 'giftaccess-for-woo' ),
				'id'      => 'giftaccess_woo_primary_color',
				'type'    => 'color',
				'default' => '#22d3ee',
				'desc'    => __( 'Utilisée pour les bordures, boutons et accents visibles côté client.', 'giftaccess-for-woo' ),
			),
			array(
				'title'   => __( 'Couleur secondaire', 'giftaccess-for-woo' ),
				'id'      => 'giftaccess_woo_accent_color',
				'type'    => 'color',
				'default' => '#3b82f6',
				'desc'    => __( 'Utilisée au survol et pour les petits messages d’action.', 'giftaccess-for-woo' ),
			),
			array(
				'title'   => __( 'Fond sombre', 'giftaccess-for-woo' ),
				'id'      => 'giftaccess_woo_surface_color',
				'type'    => 'color',
				'default' => '#0f172a',
				'desc'    => __( 'Utilisé pour les notifications et surfaces sombres du plugin.', 'giftaccess-for-woo' ),
			),
			array(
				'title'   => __( 'Texte sur fond sombre', 'giftaccess-for-woo' ),
				'id'      => 'giftaccess_woo_text_color',
				'type'    => 'color',
				'default' => '#ffffff',
			),
			array(
				'title'   => __( 'Texte bouton', 'giftaccess-for-woo' ),
				'id'      => 'giftaccess_woo_button_text_color',
				'type'    => 'color',
				'default' => '#07101d',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'giftaccess_woo_appearance_title',
			),
			array(
				'title' => __( 'Diagnostic Gift Access', 'giftaccess-for-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Vérifiez la connexion API et les accès configurés.', 'giftaccess-for-woo' ),
				'id'    => 'giftaccess_woo_diagnostic_title',
			),
			array(
				'title' => __( 'Tester API', 'giftaccess-for-woo' ),
				'type'  => 'giftaccess_woo_api_test',
				'id'    => 'giftaccess_woo_test_api',
			),
			array(
				'title' => __( 'IP serveur sortante (IPv4)', 'giftaccess-for-woo' ),
				'type'  => 'giftaccess_woo_server_ip',
				'id'    => 'giftaccess_woo_server_ip',
			),
			array(
				'title' => __( 'État des accès API', 'giftaccess-for-woo' ),
				'type'  => 'giftaccess_woo_token_state',
				'id'    => 'giftaccess_woo_token_state',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'giftaccess_woo_diagnostic_title',
			),
		);
	}

	public function sanitize_color_option( $value, $option = array(), $raw_value = null ) {
		$option_id = is_array( $option ) && ! empty( $option['id'] ) ? $option['id'] : '';
		$color     = sanitize_hex_color( $value );
		$defaults  = self::get_color_defaults();

		if ( $color ) {
			return $color;
		}

		return isset( $defaults[ $option_id ] ) ? $defaults[ $option_id ] : '#22d3ee';
	}

	public function save() {
		$before = array(
			'mode'          => self::get_option( 'mode', 'production' ),
			'base_url'      => self::get_option( 'base_url', 'https://portal.gift-access.com/api/v1' ),
			'client_id'     => self::get_option( 'client_id', '' ),
			'client_secret' => self::get_option( 'client_secret', '' ),
		);

		parent::save();

		$after = array(
			'mode'          => self::get_option( 'mode', 'production' ),
			'base_url'      => self::get_option( 'base_url', 'https://portal.gift-access.com/api/v1' ),
			'client_id'     => self::get_option( 'client_id', '' ),
			'client_secret' => self::get_option( 'client_secret', '' ),
		);

		if ( $before !== $after ) {
			$this->api->clear_tokens();
			$this->logger->info(
				'Gift Access settings changed.',
				array(
					'event' => 'settings_updated',
				)
			);
		}
	}

	public function render_api_test_field( $value ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html__( 'Test de connexion', 'giftaccess-for-woo' ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button button-secondary" id="giftaccess-woo-test-api">
					<?php echo esc_html__( 'Tester Gift Access', 'giftaccess-for-woo' ); ?>
				</button>
				<span id="giftaccess-woo-test-api-result" class="giftaccess-woo-inline-status"></span>
				<p class="description"><?php echo esc_html__( 'Vérifie la signature HMAC, la clé API et l’accès au wallet Gift Access.', 'giftaccess-for-woo' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render_webhook_url_field( $value ) {
		$url = class_exists( 'GiftAccess_Woo_Webhook' ) ? GiftAccess_Woo_Webhook::get_webhook_url() : '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html__( 'URL webhook WooCommerce', 'giftaccess-for-woo' ); ?></label>
			</th>
			<td class="forminp">
				<input
					type="text"
					readonly="readonly"
					value="<?php echo esc_attr( $url ); ?>"
					onfocus="this.select();"
					style="min-width: 560px;"
				/>
				<p class="description"><?php echo esc_html__( 'À coller dans la section Webhooks du portail Gift Access.', 'giftaccess-for-woo' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render_server_ip_field( $value ) {
		$ips = $this->api->get_public_server_ips();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html__( 'IP publique détectée (IPv4)', 'giftaccess-for-woo' ); ?></label>
			</th>
			<td class="forminp">
				<?php if ( ! empty( $ips ) ) : ?>
					<?php foreach ( $ips as $ip ) : ?>
						<div><code><?php echo esc_html( $ip ); ?></code></div>
					<?php endforeach; ?>
					<p class="description"><?php echo esc_html__( 'Ajoutez ces IPs dans la whitelist de votre clé API Gift Access.', 'giftaccess-for-woo' ); ?></p>
				<?php else : ?>
					<span><?php echo esc_html__( 'Impossible de détecter automatiquement l’IP publique.', 'giftaccess-for-woo' ); ?></span>
					<p class="description"><?php echo esc_html__( 'Renseignez dans Gift Access l’IP sortante de votre hébergement WooCommerce.', 'giftaccess-for-woo' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function render_token_state_field( $value ) {
		$states = $this->api->get_token_state();
		$labels = array(
			'api_key' => __( 'Clé API', 'giftaccess-for-woo' ),
			'secret'  => __( 'Secret API', 'giftaccess-for-woo' ),
		);
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html__( 'Accès configurés', 'giftaccess-for-woo' ); ?></label>
			</th>
			<td class="forminp">
				<ul class="giftaccess-woo-token-list">
					<?php foreach ( $states as $service => $state ) : ?>
						<li>
							<strong><?php echo esc_html( isset( $labels[ $service ] ) ? $labels[ $service ] : ucfirst( $service ) ); ?>:</strong>
							<?php echo esc_html( ! empty( $state['exists'] ) ? __( 'Configuré', 'giftaccess-for-woo' ) : __( 'Non configuré', 'giftaccess-for-woo' ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</td>
		</tr>
		<?php
	}

	public function ajax_test_api() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Permissions insuffisantes.', 'giftaccess-for-woo' ),
				),
				403
			);
		}

		check_ajax_referer( 'giftaccess_woo_test_api_nonce', 'nonce' );

		$result = $this->api->test_connection();

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	public function enqueue_assets() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$allowed_screens = array(
			'product',
			'woocommerce_page_wc-settings',
			'woocommerce_page_giftaccess-for-woo',
			'toplevel_page_giftaccess-for-woo',
		);

		if ( ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		wp_enqueue_script(
			'giftaccess-for-woo-admin',
			GIFTACCESS_WOO_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			GIFTACCESS_WOO_VERSION,
			true
		);

		wp_localize_script(
			'giftaccess-for-woo-admin',
			'izyGiftAccessWoo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'giftaccess_woo_test_api_nonce' ),
				'i18n'    => array(
					'testing' => __( 'Test en cours...', 'giftaccess-for-woo' ),
					'success' => __( 'Connexion API OK', 'giftaccess-for-woo' ),
					'error'   => __( 'Échec de connexion API', 'giftaccess-for-woo' ),
				),
			)
		);

		wp_enqueue_style(
			'giftaccess-for-woo-admin',
			GIFTACCESS_WOO_URL . 'assets/css/admin.css',
			array(),
			GIFTACCESS_WOO_VERSION
		);

		wp_add_inline_style( 'giftaccess-for-woo-admin', self::get_design_tokens_css() );
	}

	public static function get_option( $suffix, $default = '' ) {
		return get_option( 'giftaccess_woo_' . $suffix, $default );
	}

	public static function get_design_tokens_css() {
		$colors = self::get_design_colors();

		return sprintf(
			':root{--giftaccess-woo-primary:%1$s;--giftaccess-woo-primary-rgb:%2$s;--giftaccess-woo-accent:%3$s;--giftaccess-woo-accent-rgb:%4$s;--giftaccess-woo-surface:%5$s;--giftaccess-woo-text:%6$s;--giftaccess-woo-button-text:%7$s;}',
			esc_html( $colors['primary_color'] ),
			esc_html( self::hex_to_rgb_csv( $colors['primary_color'] ) ),
			esc_html( $colors['accent_color'] ),
			esc_html( self::hex_to_rgb_csv( $colors['accent_color'] ) ),
			esc_html( $colors['surface_color'] ),
			esc_html( $colors['text_color'] ),
			esc_html( $colors['button_text_color'] )
		);
	}

	private static function get_design_colors() {
		$colors = array();

		foreach ( self::get_color_defaults() as $option_name => $default ) {
			$stored = sanitize_hex_color( get_option( $option_name, $default ) );
			$suffix = preg_replace( '/^giftaccess_woo_/', '', $option_name );

			$colors[ $suffix ] = $stored ? $stored : $default;
		}

		return $colors;
	}

	private static function get_color_defaults() {
		return array(
			'giftaccess_woo_primary_color'     => '#22d3ee',
			'giftaccess_woo_accent_color'      => '#3b82f6',
			'giftaccess_woo_surface_color'     => '#0f172a',
			'giftaccess_woo_text_color'        => '#ffffff',
			'giftaccess_woo_button_text_color' => '#07101d',
		);
	}

	private static function hex_to_rgb_csv( $hex ) {
		$hex = ltrim( (string) $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '34, 211, 238';
		}

		return hexdec( substr( $hex, 0, 2 ) ) . ', ' . hexdec( substr( $hex, 2, 2 ) ) . ', ' . hexdec( substr( $hex, 4, 2 ) );
	}
}
