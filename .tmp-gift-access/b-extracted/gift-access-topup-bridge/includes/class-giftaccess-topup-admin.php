<?php

if (!defined('ABSPATH')) {
    exit;
}

final class GiftAccess_Topup_Admin
{
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_giftaccess_topup_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_post_giftaccess_topup_refresh_products', array($this, 'handle_refresh_products'));
        add_action('admin_post_giftaccess_topup_import_product', array($this, 'handle_import_product'));
        add_action('admin_post_giftaccess_topup_clear_logs', array($this, 'handle_clear_logs'));
    }

    public function add_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Gift Access Gaming', 'gift-access-topup-bridge'),
            __('Gift Access Gaming', 'gift-access-topup-bridge'),
            'manage_woocommerce',
            'giftaccess-topup',
            array($this, 'render_page')
        );
    }

    public function register_settings()
    {
        register_setting(GiftAccess_Topup_Plugin::OPTION, GiftAccess_Topup_Plugin::OPTION, array($this->plugin, 'sanitize_settings'));
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'woocommerce_page_giftaccess-topup') {
            return;
        }

        wp_enqueue_style('giftaccess-topup-admin', GIFTACCESS_TOPUP_URL . 'assets/admin.css', array(), GIFTACCESS_TOPUP_VERSION);
    }

    public function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Acces refuse.', 'gift-access-topup-bridge'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        if (!in_array($tab, array('settings', 'import', 'logs'), true)) {
            $tab = 'settings';
        }

        echo '<div class="wrap giftaccess-topup-admin">';
        $this->render_page_title($tab);
        $this->render_notices();
        $this->render_tabs($tab);

        echo '<main class="giftaccess-topup-page">';
        if ($tab === 'import') {
            $this->render_import_tab();
        } elseif ($tab === 'logs') {
            $this->render_logs_tab();
        } else {
            $this->render_settings_tab();
        }
        echo '</main>';

        echo '</div>';
    }

    private function render_page_title($tab)
    {
        $titles = array(
            'settings' => array(__('Reglages TopUp', 'gift-access-topup-bridge'), __('Configurez la connexion API, la langue, la livraison et les webhooks.', 'gift-access-topup-bridge')),
            'import' => array(__('Import produits', 'gift-access-topup-bridge'), __('Importez uniquement les produits top-up autorises vers WooCommerce.', 'gift-access-topup-bridge')),
            'logs' => array(__('Logs', 'gift-access-topup-bridge'), __('Suivez les evenements API, webhooks et commandes.', 'gift-access-topup-bridge')),
        );

        $title = isset($titles[$tab]) ? $titles[$tab][0] : __('Gift Access Gaming', 'gift-access-topup-bridge');
        $description = isset($titles[$tab]) ? $titles[$tab][1] : '';

        echo '<div class="giftaccess-topup-titlebar">';
        echo '<div>';
        echo '<h1>' . esc_html($title) . '</h1>';
        if ($description !== '') {
            echo '<p>' . esc_html($description) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_header()
    {
        $settings = $this->plugin->settings();
        $cache = $this->plugin->product_cache();
        $imports = $this->plugin->product_imports();
        $logs = $this->plugin->logger()->logs();
        $has_credentials = trim((string) $settings['api_key']) !== '' && trim((string) $settings['api_secret']) !== '';

        echo '<div class="giftaccess-topup-hero">';
        echo '<div class="giftaccess-topup-hero-copy">';
        echo '<span class="giftaccess-topup-eyebrow">' . esc_html__('WooCommerce gaming', 'gift-access-topup-bridge') . '</span>';
        echo '<h1>' . esc_html__('Gift Access Gaming', 'gift-access-topup-bridge') . '</h1>';
        echo '<p>' . esc_html__('Importez vos produits top-up, verifiez les ID joueurs et suivez les livraisons API depuis une seule interface.', 'gift-access-topup-bridge') . '</p>';
        echo '</div>';
        echo '<div class="giftaccess-topup-hero-actions">';
        $this->action_button('giftaccess_topup_test_connection', __('Tester API', 'gift-access-topup-bridge'), 'primary', 'giftaccess-topup-hero-action');
        echo '<a class="button giftaccess-topup-secondary-action" href="' . esc_url($this->page_url('import')) . '">' . esc_html__('Importer', 'gift-access-topup-bridge') . '</a>';
        echo '</div>';
        echo '</div>';

        echo '<div class="giftaccess-topup-stats">';
        $this->stat_card(
            __('API', 'gift-access-topup-bridge'),
            $has_credentials ? __('Configuree', 'gift-access-topup-bridge') : __('A configurer', 'gift-access-topup-bridge'),
            $has_credentials ? __('Cle et secret presents', 'gift-access-topup-bridge') : __('Ajoutez vos identifiants', 'gift-access-topup-bridge'),
            $has_credentials ? 'good' : 'warn'
        );
        $this->stat_card(__('Produits cache', 'gift-access-topup-bridge'), (string) count($cache['products']), __('Depuis Gift Access API', 'gift-access-topup-bridge'), 'neutral');
        $this->stat_card(__('Imports Woo', 'gift-access-topup-bridge'), (string) count($imports), __('Produits deja lies', 'gift-access-topup-bridge'), 'neutral');
        $this->stat_card(__('Logs', 'gift-access-topup-bridge'), (string) count($logs), __('Derniers evenements', 'gift-access-topup-bridge'), count($logs) ? 'warn' : 'good');
        echo '</div>';
    }

    private function stat_card($label, $value, $hint, $tone)
    {
        echo '<section class="giftaccess-topup-stat giftaccess-topup-stat-' . esc_attr($tone) . '">';
        echo '<span class="giftaccess-topup-stat-label">' . esc_html($label) . '</span>';
        echo '<strong>' . esc_html($value) . '</strong>';
        echo '<span class="giftaccess-topup-stat-hint">' . esc_html($hint) . '</span>';
        echo '</section>';
    }

    private function render_tabs($active)
    {
        $tabs = array(
            'settings' => array(__('Reglages', 'gift-access-topup-bridge'), 'dashicons-admin-settings'),
            'import' => array(__('Import produits', 'gift-access-topup-bridge'), 'dashicons-download'),
            'logs' => array(__('Logs', 'gift-access-topup-bridge'), 'dashicons-list-view'),
        );

        echo '<nav class="giftaccess-topup-tabs" aria-label="' . esc_attr__('Sections Gift Access Gaming', 'gift-access-topup-bridge') . '">';
        foreach ($tabs as $key => $tab) {
            $url = $this->page_url($key);
            echo '<a class="' . esc_attr($active === $key ? 'is-active' : '') . '" href="' . esc_url($url) . '">';
            echo '<span class="dashicons ' . esc_attr($tab[1]) . '"></span>';
            echo '<span>' . esc_html($tab[0]) . '</span>';
            echo '</a>';
        }
        echo '</nav>';
    }

    private function render_settings_tab()
    {
        $settings = $this->plugin->settings();

        echo '<form class="giftaccess-topup-form" method="post" action="options.php">';
        settings_fields(GiftAccess_Topup_Plugin::OPTION);

        $this->panel_open(__('Langue du plugin', 'gift-access-topup-bridge'), __('Choisissez la langue utilisee par les pages admin et les messages client du plugin.', 'gift-access-topup-bridge'), 'dashicons-translation');
        if ($this->plugin->needs_setup()) {
            echo '<div class="giftaccess-topup-setup-note">';
            echo '<strong>' . esc_html__('Premiere configuration', 'gift-access-topup-bridge') . '</strong>';
            echo '<p>' . esc_html__('Selectionnez la langue de preference avant de continuer la configuration du module.', 'gift-access-topup-bridge') . '</p>';
            echo '</div>';
        }
        echo '<table class="giftaccess-topup-form-table" role="presentation"><tbody>';
        $this->select_row(__('Langue preferee', 'gift-access-topup-bridge'), 'language', $settings['language'], array(
            'en' => __('English', 'gift-access-topup-bridge'),
            'fr' => __('Francais', 'gift-access-topup-bridge'),
            'es' => __('Espanol', 'gift-access-topup-bridge'),
        ));
        echo '</tbody></table>';
        $this->panel_close();

        echo '<div class="giftaccess-topup-grid giftaccess-topup-grid-2">';
        $this->panel_open(__('Connexion API', 'gift-access-topup-bridge'), __('Identifiants utilises par WooCommerce pour communiquer avec Gift Access.', 'gift-access-topup-bridge'), 'dashicons-admin-links');
        echo '<table class="giftaccess-topup-form-table" role="presentation"><tbody>';
        $this->text_row(__('Base URL API', 'gift-access-topup-bridge'), 'base_url', $settings['base_url'], 'url', __('Exemple: https://portal.gift-access.com/api/v1', 'gift-access-topup-bridge'));
        $this->text_row(__('Cle API', 'gift-access-topup-bridge'), 'api_key', $settings['api_key']);
        $this->text_row(__('Secret API', 'gift-access-topup-bridge'), 'api_secret', $settings['api_secret'], 'password', __('Laissez vide pour garder le secret actuel.', 'gift-access-topup-bridge'));
        $this->text_row(__('Timeout', 'gift-access-topup-bridge'), 'timeout', $settings['timeout'], 'number', '', array('min' => '5', 'max' => '120'));
        echo '</tbody></table>';
        $this->panel_close();

        $this->panel_open(__('Livraison Woo', 'gift-access-topup-bridge'), __('Regles appliquees quand une commande WooCommerce est payee.', 'gift-access-topup-bridge'), 'dashicons-cart');
        echo '<table class="giftaccess-topup-form-table" role="presentation"><tbody>';
        $this->select_row(__('Statut des imports', 'gift-access-topup-bridge'), 'import_status', $settings['import_status'], array(
            'draft' => __('Brouillon', 'gift-access-topup-bridge'),
            'publish' => __('Publie', 'gift-access-topup-bridge'),
        ));
        $this->checkbox_row(__('Exiger verification ID avant panier', 'gift-access-topup-bridge'), 'validate_player', $settings['validate_player']);
        $this->checkbox_row(__('Completer la commande Woo si livraison complete', 'gift-access-topup-bridge'), 'auto_complete_order', $settings['auto_complete_order']);
        $this->checkbox_row(__('Marquer la commande Woo en echec si API refuse', 'gift-access-topup-bridge'), 'fail_order_on_error', $settings['fail_order_on_error']);
        $this->checkbox_row(__('Rembourser automatiquement si retour en echec', 'gift-access-topup-bridge'), 'refund_failed_orders', $settings['refund_failed_orders']);
        echo '</tbody></table>';
        $this->panel_close();
        echo '</div>';

        echo '<div class="giftaccess-topup-grid giftaccess-topup-grid-2">';
        $this->panel_open(__('Webhook', 'gift-access-topup-bridge'), __('URL a copier dans Gift Access pour recevoir les changements de statut.', 'gift-access-topup-bridge'), 'dashicons-rest-api');
        echo '<table class="giftaccess-topup-form-table" role="presentation"><tbody>';
        $this->text_row(__('Secret webhook', 'gift-access-topup-bridge'), 'webhook_secret', $settings['webhook_secret']);
        echo '</tbody></table>';
        echo '<div class="giftaccess-topup-copyline">';
        echo '<input type="text" readonly value="' . esc_attr($this->plugin->webhook_url()) . '" onclick="this.select();" />';
        echo '<span>' . esc_html__('Webhook URL', 'gift-access-topup-bridge') . '</span>';
        echo '</div>';
        $this->panel_close();

        $this->panel_open(__('Journalisation', 'gift-access-topup-bridge'), __('Gardez les logs actifs pour diagnostiquer les commandes et webhooks.', 'gift-access-topup-bridge'), 'dashicons-clipboard');
        echo '<table class="giftaccess-topup-form-table" role="presentation"><tbody>';
        $this->checkbox_row(__('Activer les logs', 'gift-access-topup-bridge'), 'log_enabled', $settings['log_enabled']);
        $this->checkbox_row(__('Logs debug', 'gift-access-topup-bridge'), 'debug_enabled', $settings['debug_enabled']);
        echo '</tbody></table>';
        echo '<p class="giftaccess-topup-help">' . esc_html__('Le mode debug ajoute les requetes et reponses API dans les logs locaux. A garder desactive en production sauf diagnostic.', 'gift-access-topup-bridge') . '</p>';
        $this->panel_close();
        echo '</div>';

        echo '<div class="giftaccess-topup-submit-bar">';
        submit_button(__('Enregistrer les reglages', 'gift-access-topup-bridge'), 'primary', 'submit', false);
        echo '</div>';
        echo '</form>';
    }

    private function render_import_tab()
    {
        $cache = $this->plugin->product_cache();
        $imports = $this->plugin->product_imports();
        $products = isset($cache['products']) && is_array($cache['products']) ? $this->filter_authorized_topup_products($cache['products']) : array();

        echo '<div class="giftaccess-topup-toolbar">';
        echo '<div>';
        echo '<h2>' . esc_html__('Catalogue Gift Access', 'gift-access-topup-bridge') . '</h2>';
        echo '<p>' . esc_html__('Rafraichissez la liste, puis importez uniquement les produits top-up disponibles via Gift Access.', 'gift-access-topup-bridge') . '</p>';
        if (!empty($cache['fetched_at'])) {
            echo '<span class="giftaccess-topup-muted">' . esc_html(sprintf(__('Derniere mise a jour: %s', 'gift-access-topup-bridge'), $cache['fetched_at'])) . '</span>';
        }
        echo '</div>';
        $this->action_button('giftaccess_topup_refresh_products', __('Rafraichir la liste', 'gift-access-topup-bridge'), 'primary', 'giftaccess-topup-toolbar-action');
        echo '</div>';

        $this->panel_open(__('Importer par ID produit', 'gift-access-topup-bridge'), __('Utile pour importer un produit precis sans parcourir toute la liste.', 'gift-access-topup-bridge'), 'dashicons-search');
        echo '<form class="giftaccess-topup-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="giftaccess_topup_import_product" />';
        wp_nonce_field('giftaccess_topup_import_product');
        echo '<label class="screen-reader-text" for="giftaccess-topup-product-id">' . esc_html__('ID produit', 'gift-access-topup-bridge') . '</label>';
        echo '<input id="giftaccess-topup-product-id" class="regular-text" name="product_id" placeholder="GAME-FREEFIRE-LATAM" />';
        submit_button(__('Importer / mettre a jour', 'gift-access-topup-bridge'), 'primary', 'submit', false);
        echo '</form>';
        $this->panel_close();

        $this->panel_open(__('Produits API disponibles', 'gift-access-topup-bridge'), sprintf(_n('%d produit charge depuis le cache.', '%d produits charges depuis le cache.', count($products), 'gift-access-topup-bridge'), count($products)), 'dashicons-products');

        if (empty($products)) {
            $this->empty_state(
                __('Aucun produit en cache', 'gift-access-topup-bridge'),
                __('Lancez un rafraichissement pour afficher les produits actifs disponibles via votre API.', 'gift-access-topup-bridge'),
                'dashicons-cloud-upload'
            );
        } else {
            echo '<div class="giftaccess-topup-table-wrap">';
            echo '<table class="widefat giftaccess-topup-table"><thead><tr>';
            echo '<th>' . esc_html__('ID', 'gift-access-topup-bridge') . '</th>';
            echo '<th>' . esc_html__('Nom', 'gift-access-topup-bridge') . '</th>';
            echo '<th>' . esc_html__('Type', 'gift-access-topup-bridge') . '</th>';
            echo '<th>' . esc_html__('Prix', 'gift-access-topup-bridge') . '</th>';
            echo '<th>' . esc_html__('Woo', 'gift-access-topup-bridge') . '</th>';
            echo '<th class="giftaccess-topup-actions-col">' . esc_html__('Action', 'gift-access-topup-bridge') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $product_id = (string) (isset($product['id']) ? $product['id'] : '');
                $woo_id = isset($imports[$product_id]['product_id']) ? (int) $imports[$product_id]['product_id'] : 0;
                echo '<tr>';
                echo '<td><code>' . esc_html($product_id) . '</code></td>';
                echo '<td><strong>' . esc_html((string) (isset($product['name']) ? $product['name'] : '')) . '</strong></td>';
                echo '<td><span class="giftaccess-topup-badge">' . esc_html((string) (isset($product['type']) ? $product['type'] : '-')) . '</span></td>';
                echo '<td>' . esc_html($this->product_price_label($product)) . '</td>';
                echo '<td>';
                if ($woo_id > 0) {
                    echo '<span class="giftaccess-topup-status-imported">' . esc_html__('Importe', 'gift-access-topup-bridge') . '</span>';
                    echo '<a class="giftaccess-topup-woolink" href="' . esc_url(get_edit_post_link($woo_id)) . '">#' . esc_html((string) $woo_id) . '</a>';
                } else {
                    echo '<span class="giftaccess-topup-muted">-</span>';
                }
                echo '</td>';
                echo '<td>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="giftaccess_topup_import_product" />';
                echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
                wp_nonce_field('giftaccess_topup_import_product');
                submit_button($woo_id > 0 ? __('Mettre a jour', 'gift-access-topup-bridge') : __('Importer', 'gift-access-topup-bridge'), 'secondary small', 'submit', false);
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        }

        $this->panel_close();
    }

    private function render_logs_tab()
    {
        $logs = $this->plugin->logger()->logs();
        if (isset($_GET['log'])) {
            $this->render_log_detail(absint($_GET['log']), $logs);
            return;
        }

        $counts = $this->log_counts($logs);

        echo '<div class="giftaccess-topup-toolbar">';
        echo '<div>';
        echo '<h2>' . esc_html__('Logs gaming', 'gift-access-topup-bridge') . '</h2>';
        echo '<p>' . esc_html__('Les erreurs API, webhooks et traitements de commandes sont conserves localement pour diagnostic.', 'gift-access-topup-bridge') . '</p>';
        echo '<div class="giftaccess-topup-log-counts">';
        foreach ($counts as $level => $count) {
            if ($count <= 0) {
                continue;
            }
            echo '<span class="giftaccess-topup-level giftaccess-topup-level-' . esc_attr($level) . '">' . esc_html(strtoupper($level) . ': ' . $count) . '</span>';
        }
        echo '</div>';
        echo '</div>';
        if ($logs) {
            $this->action_button('giftaccess_topup_clear_logs', __('Vider les logs', 'gift-access-topup-bridge'), 'delete', 'giftaccess-topup-toolbar-action');
        }
        echo '</div>';

        $this->panel_open(__('Evenements recents', 'gift-access-topup-bridge'), sprintf(_n('%d entree conservee.', '%d entrees conservees.', count($logs), 'gift-access-topup-bridge'), count($logs)), 'dashicons-list-view');

        if (!$logs) {
            $this->empty_state(
                __('Aucun log pour le moment', 'gift-access-topup-bridge'),
                __('Quand une commande, un webhook ou une erreur API arrive, elle apparaitra ici.', 'gift-access-topup-bridge'),
                'dashicons-yes-alt'
            );
            $this->panel_close();
            return;
        }

        echo '<div class="giftaccess-topup-table-wrap">';
        echo '<table class="widefat giftaccess-topup-table giftaccess-topup-log-table"><thead><tr>';
        echo '<th>' . esc_html__('Date', 'gift-access-topup-bridge') . '</th>';
        echo '<th>' . esc_html__('Niveau', 'gift-access-topup-bridge') . '</th>';
        echo '<th>' . esc_html__('Message', 'gift-access-topup-bridge') . '</th>';
        echo '<th class="giftaccess-topup-actions-col">' . esc_html__('Action', 'gift-access-topup-bridge') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($logs as $index => $log) {
            $level = sanitize_key((string) (isset($log['level']) ? $log['level'] : 'info'));
            echo '<tr>';
            echo '<td class="giftaccess-topup-nowrap">' . esc_html((string) (isset($log['time']) ? $log['time'] : '')) . '</td>';
            echo '<td><span class="giftaccess-topup-level giftaccess-topup-level-' . esc_attr($level) . '">' . esc_html(strtoupper($level)) . '</span></td>';
            echo '<td><strong>' . esc_html((string) (isset($log['message']) ? $log['message'] : '')) . '</strong></td>';
            echo '<td><a class="button button-secondary button-small" href="' . esc_url(add_query_arg(array('page' => 'giftaccess-topup', 'tab' => 'logs', 'log' => (int) $index), admin_url('admin.php'))) . '">' . esc_html__('Voir', 'gift-access-topup-bridge') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        $this->panel_close();
    }

    private function render_log_detail($index, array $logs)
    {
        if (!isset($logs[$index]) || !is_array($logs[$index])) {
            $this->empty_state(
                __('Log introuvable', 'gift-access-topup-bridge'),
                __('Cette entree de log nest plus disponible. Les logs recents peuvent deplacer les anciennes lignes.', 'gift-access-topup-bridge'),
                'dashicons-warning'
            );
            echo '<p><a class="button" href="' . esc_url($this->page_url('logs')) . '">' . esc_html__('Retour aux logs', 'gift-access-topup-bridge') . '</a></p>';
            return;
        }

        $log = $logs[$index];
        $level = sanitize_key((string) (isset($log['level']) ? $log['level'] : 'info'));
        $context = isset($log['context']) && is_array($log['context']) ? $log['context'] : array();

        echo '<div class="giftaccess-topup-toolbar">';
        echo '<div>';
        echo '<h2>' . esc_html__('Detail du log', 'gift-access-topup-bridge') . '</h2>';
        echo '<p>' . esc_html__('Contexte complet de levenement selectionne.', 'gift-access-topup-bridge') . '</p>';
        echo '</div>';
        echo '<a class="button button-secondary" href="' . esc_url($this->page_url('logs')) . '">' . esc_html__('Retour aux logs', 'gift-access-topup-bridge') . '</a>';
        echo '</div>';

        $this->panel_open(__('Resume', 'gift-access-topup-bridge'), __('Informations principales de cette entree.', 'gift-access-topup-bridge'), 'dashicons-info');
        echo '<div class="giftaccess-topup-log-detail-grid">';
        $this->detail_item(__('Date', 'gift-access-topup-bridge'), (string) (isset($log['time']) ? $log['time'] : ''));
        $this->detail_item(__('Niveau', 'gift-access-topup-bridge'), strtoupper($level), 'giftaccess-topup-level giftaccess-topup-level-' . $level);
        $this->detail_item(__('Message', 'gift-access-topup-bridge'), (string) (isset($log['message']) ? $log['message'] : ''));
        $this->detail_item(__('Position', 'gift-access-topup-bridge'), '#' . (string) $index);
        echo '</div>';
        $this->panel_close();

        $this->panel_open(__('Contexte JSON', 'gift-access-topup-bridge'), __('Payload redacte conserve par le plugin.', 'gift-access-topup-bridge'), 'dashicons-media-code');
        echo '<pre class="giftaccess-topup-context-view">' . esc_html(wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        $this->panel_close();
    }

    private function detail_item($label, $value, $value_class = '')
    {
        echo '<div class="giftaccess-topup-detail-item">';
        echo '<span>' . esc_html($label) . '</span>';
        if ($value_class !== '') {
            echo '<strong class="' . esc_attr($value_class) . '">' . esc_html($value) . '</strong>';
        } else {
            echo '<strong>' . esc_html($value) . '</strong>';
        }
        echo '</div>';
    }

    public function handle_test_connection()
    {
        $this->verify_action('giftaccess_topup_test_connection');
        $result = $this->plugin->api()->test_connection();

        if (is_wp_error($result)) {
            $this->notice('error', $result->get_error_message());
        } else {
            $this->notice('success', __('Connexion API valide.', 'gift-access-topup-bridge'));
        }

        $this->redirect('settings');
    }

    public function handle_refresh_products()
    {
        $this->verify_action('giftaccess_topup_refresh_products');
        $products = $this->plugin->api()->list_products();

        if (is_wp_error($products)) {
            $this->notice('error', $products->get_error_message());
        } else {
            $filtered = $this->filter_authorized_topup_products(is_array($products) ? $products : array());
            $this->plugin->remember_product_cache($filtered);
            $this->notice('success', sprintf(__('%d produit(s) autorise(s) charges.', 'gift-access-topup-bridge'), count($filtered)));
        }

        $this->redirect('import');
    }

    public function handle_import_product()
    {
        $this->verify_action('giftaccess_topup_import_product');
        $product_id = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : '';
        if ($product_id === '') {
            $this->notice('error', __('ID produit manquant.', 'gift-access-topup-bridge'));
            $this->redirect('import');
        }

        $product = $this->resolve_import_product($product_id);
        if (is_wp_error($product)) {
            $this->notice('error', $product->get_error_message());
            $this->redirect('import');
        }

        if (!$this->is_importable_product($product, $product_id)) {
            $this->notice('error', __('Ce produit ne peut pas etre importe via Gift Access.', 'gift-access-topup-bridge'));
            $this->redirect('import');
        }

        $imports = $this->plugin->product_imports();
        $already_imported_id = isset($imports[$product_id]['product_id']) ? absint($imports[$product_id]['product_id']) : 0;
        $result = $this->plugin->woocommerce()->sync_product_from_api($product);
        if (is_wp_error($result)) {
            $this->notice('error', $result->get_error_message());
            $this->redirect('import');
        }

        $this->refresh_cache_after_import($product);
        $this->notice('success', sprintf(__('Produit Woo #%d synchronise avec %d variation(s).', 'gift-access-topup-bridge'), (int) $result['product_id'], (int) $result['variations']));

        if ($already_imported_id > 0 && !empty($result['product_id'])) {
            $edit_url = get_edit_post_link((int) $result['product_id'], '');
            if ($edit_url) {
                wp_safe_redirect($edit_url);
                exit;
            }
        }

        $this->redirect('import');
    }

    public function handle_clear_logs()
    {
        $this->verify_action('giftaccess_topup_clear_logs');
        $this->plugin->logger()->clear();
        $this->notice('success', __('Logs vides.', 'gift-access-topup-bridge'));
        $this->redirect('logs');
    }

    private function panel_open($title, $description = '', $icon = 'dashicons-admin-generic')
    {
        echo '<section class="giftaccess-topup-panel">';
        echo '<header class="giftaccess-topup-panel-header">';
        echo '<span class="dashicons ' . esc_attr($icon) . '"></span>';
        echo '<div>';
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($description !== '') {
            echo '<p>' . esc_html($description) . '</p>';
        }
        echo '</div>';
        echo '</header>';
        echo '<div class="giftaccess-topup-panel-body">';
    }

    private function panel_close()
    {
        echo '</div></section>';
    }

    private function empty_state($title, $message, $icon)
    {
        echo '<div class="giftaccess-topup-empty">';
        echo '<span class="dashicons ' . esc_attr($icon) . '"></span>';
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<p>' . esc_html($message) . '</p>';
        echo '</div>';
    }

    private function action_button($action, $label, $style = 'primary', $class = '')
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="giftaccess-topup-action ' . esc_attr($class) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        wp_nonce_field($action);
        submit_button($label, $style, 'submit', false);
        echo '</form>';
    }

    private function text_row($label, $key, $value, $type = 'text', $description = '', array $attrs = array())
    {
        $name = GiftAccess_Topup_Plugin::OPTION . '[' . $key . ']';
        echo '<tr class="giftaccess-topup-field-row"><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input class="regular-text giftaccess-topup-input" id="' . esc_attr($key) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"';
        foreach ($attrs as $attr => $attr_value) {
            echo ' ' . esc_attr($attr) . '="' . esc_attr((string) $attr_value) . '"';
        }
        if ($type === 'password') {
            echo ' autocomplete="new-password"';
        }
        echo ' />';
        if ($description !== '') {
            echo '<p class="giftaccess-topup-help">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function select_row($label, $key, $value, array $options)
    {
        $name = GiftAccess_Topup_Plugin::OPTION . '[' . $key . ']';
        echo '<tr class="giftaccess-topup-field-row"><th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        echo '<select class="giftaccess-topup-input" id="' . esc_attr($key) . '" name="' . esc_attr($name) . '">';
        foreach ($options as $option => $option_label) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($value, $option, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select></td></tr>';
    }

    private function checkbox_row($label, $key, $value)
    {
        $name = GiftAccess_Topup_Plugin::OPTION . '[' . $key . ']';
        echo '<tr class="giftaccess-topup-field-row giftaccess-topup-switch-row"><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<label class="giftaccess-topup-switch">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="yes" ' . checked($value, 'yes', false) . ' />';
        echo '<span class="giftaccess-topup-switch-ui" aria-hidden="true"></span>';
        echo '<span class="giftaccess-topup-switch-label">' . esc_html($value === 'yes' ? __('Actif', 'gift-access-topup-bridge') : __('Inactif', 'gift-access-topup-bridge')) . '</span>';
        echo '</label>';
        echo '</td></tr>';
    }

    private function filter_authorized_topup_products(array $products)
    {
        $filtered = array();
        foreach ($products as $product) {
            if (is_array($product) && $this->is_allowed_topup_product($product)) {
                $filtered[] = $product;
            }
        }

        return $filtered;
    }

    private function resolve_import_product($product_id)
    {
        $product_id = (string) $product_id;
        $detail = $this->plugin->api()->get_product($product_id);

        if (!is_wp_error($detail)) {
            $product = $this->extract_product_by_id(is_array($detail) ? $detail : array(), $product_id);
            if ($product) {
                return $product;
            }
        }

        $cached = $this->find_cached_product($product_id);
        if ($cached) {
            return $cached;
        }

        $fresh = $this->find_fresh_catalog_product($product_id);
        if ($fresh) {
            return $fresh;
        }

        if (is_wp_error($detail)) {
            return $detail;
        }

        return new WP_Error(
            'giftaccess_topup_product_not_found',
            __('Produit introuvable via Gift Access.', 'gift-access-topup-bridge')
        );
    }

    private function refresh_cache_after_import(array $imported_product)
    {
        $products = $this->plugin->api()->list_products();
        if (!is_wp_error($products) && is_array($products)) {
            $this->plugin->remember_product_cache($this->with_imported_product($this->filter_authorized_topup_products($products), $imported_product));
            return;
        }

        $cache = $this->plugin->product_cache();
        $cached_products = isset($cache['products']) && is_array($cache['products']) ? $cache['products'] : array();
        $imported_id = (string) (isset($imported_product['id']) ? $imported_product['id'] : '');
        $merged = false;

        foreach ($cached_products as $index => $cached_product) {
            if (!is_array($cached_product)) {
                continue;
            }

            if ((string) (isset($cached_product['id']) ? $cached_product['id'] : '') === $imported_id) {
                $cached_products[$index] = $imported_product;
                $merged = true;
                break;
            }
        }

        if (!$merged && $this->is_allowed_topup_product($imported_product)) {
            array_unshift($cached_products, $imported_product);
        }

        $this->plugin->remember_product_cache($this->filter_authorized_topup_products($cached_products));
    }

    private function with_imported_product(array $products, array $imported_product)
    {
        $imported_id = (string) (isset($imported_product['id']) ? $imported_product['id'] : '');
        if ($imported_id === '' || !$this->is_allowed_topup_product($imported_product)) {
            return $products;
        }

        foreach ($products as $index => $product) {
            if (!is_array($product)) {
                continue;
            }

            if ((string) (isset($product['id']) ? $product['id'] : '') === $imported_id) {
                $products[$index] = $imported_product;
                return $products;
            }
        }

        array_unshift($products, $imported_product);
        return $products;
    }

    private function extract_product_by_id(array $payload, $product_id)
    {
        $product_id = (string) $product_id;
        if ((string) (isset($payload['id']) ? $payload['id'] : '') === $product_id) {
            return $payload;
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            if ((string) (isset($value['id']) ? $value['id'] : '') === $product_id) {
                return $value;
            }

            $nested = $this->extract_product_by_id($value, $product_id);
            if ($nested) {
                return $nested;
            }
        }

        return null;
    }

    private function is_importable_product(array $product, $product_id)
    {
        if ($this->is_allowed_topup_product($product)) {
            return true;
        }

        $cached = $this->find_cached_product($product_id);
        if ($cached && $this->is_allowed_topup_product($cached)) {
            return true;
        }

        $fresh = $this->find_fresh_catalog_product($product_id);
        return $fresh && $this->is_allowed_topup_product($fresh);
    }

    private function is_allowed_topup_product(array $product)
    {
        $values = $this->provider_values($product);
        if ($values) {
            return $this->is_authorized_provider_product($product);
        }

        return $this->looks_like_game_topup($product);
    }

    private function find_cached_product($product_id)
    {
        $product_id = (string) $product_id;
        $cache = $this->plugin->product_cache();
        $products = isset($cache['products']) && is_array($cache['products']) ? $cache['products'] : array();

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            if ((string) (isset($product['id']) ? $product['id'] : '') === $product_id) {
                return $product;
            }
        }

        return null;
    }

    private function find_fresh_catalog_product($product_id)
    {
        $product_id = (string) $product_id;
        $products = $this->plugin->api()->list_products();
        if (is_wp_error($products) || !is_array($products)) {
            return null;
        }

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            if ((string) (isset($product['id']) ? $product['id'] : '') === $product_id) {
                return $product;
            }
        }

        return null;
    }

    private function looks_like_game_topup(array $product)
    {
        $type = $this->normalize_match_text(isset($product['type']) ? $product['type'] : '');
        $category = $this->normalize_match_text(isset($product['category']) ? $product['category'] : '');
        $name = $this->normalize_match_text(isset($product['name']) ? $product['name'] : '');
        $has_variations = isset($product['variations']) && is_array($product['variations']) && !empty($product['variations']);

        if (strpos($type, 'gamingtopup') !== false || strpos($type, 'gametopup') !== false || strpos($type, 'topup') !== false) {
            return true;
        }

        if (strpos($category, 'gamingtopup') !== false || strpos($category, 'gametopup') !== false || strpos($category, 'topup') !== false) {
            return true;
        }

        if ($has_variations && preg_match('/freefire|bloodstrike|pubg|mobilelegends|codm|callofduty|diamonds|diamond|uc/', $name)) {
            return true;
        }

        return false;
    }

    private function normalize_match_text($value)
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', (string) $value));
    }

    private function is_authorized_provider_product(array $product)
    {
        $provider_tokens = array(
            strtolower('g' . '2' . 'bulk'),
            strtolower('g' . 'bulk'),
        );
        $values = $this->provider_values($product);
        foreach ($values as $value) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $value));
            foreach ($provider_tokens as $provider_token) {
                if ($normalized === $provider_token || strpos($normalized, $provider_token) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function provider_values(array $payload)
    {
        $values = array();
        foreach ($payload as $key => $value) {
            $key_string = strtolower((string) $key);
            $is_provider_key = preg_match('/provider|supplier|vendor|source|fulfillment/', $key_string);

            if (is_array($value)) {
                if ($is_provider_key) {
                    $values = array_merge($values, $this->scalar_values($value));
                }
                $values = array_merge($values, $this->provider_values($value));
                continue;
            }

            if ($is_provider_key && is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return array_values(array_unique(array_filter($values, static function ($value) {
            return trim((string) $value) !== '';
        })));
    }

    private function scalar_values(array $payload)
    {
        $values = array();
        foreach ($payload as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->scalar_values($value));
            } elseif (is_scalar($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    private function product_price_label(array $product)
    {
        $currency = (string) (isset($product['currency']) ? $product['currency'] : '');
        $min = isset($product['min_amount']) ? $product['min_amount'] : '';
        $max = isset($product['max_amount']) ? $product['max_amount'] : '';
        if ($min !== '' && $max !== '' && (string) $min !== (string) $max) {
            return trim($currency . ' ' . $min . ' - ' . $max);
        }

        return trim($currency . ' ' . $min);
    }

    private function log_counts(array $logs)
    {
        $counts = array(
            'error' => 0,
            'warning' => 0,
            'info' => 0,
            'debug' => 0,
        );

        foreach ($logs as $log) {
            $level = sanitize_key((string) (isset($log['level']) ? $log['level'] : 'info'));
            if (!isset($counts[$level])) {
                $counts[$level] = 0;
            }
            $counts[$level]++;
        }

        return $counts;
    }

    private function page_url($tab)
    {
        return admin_url('admin.php?page=giftaccess-topup&tab=' . sanitize_key($tab));
    }

    private function verify_action($action)
    {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer($action)) {
            wp_die(esc_html__('Action non autorisee.', 'gift-access-topup-bridge'));
        }
    }

    private function notice($type, $message)
    {
        set_transient('giftaccess_topup_admin_notice', array(
            'type' => $type,
            'message' => $message,
        ), 60);
    }

    private function render_notices()
    {
        $notice = get_transient('giftaccess_topup_admin_notice');
        if (!$notice || !is_array($notice)) {
            return;
        }

        delete_transient('giftaccess_topup_admin_notice');
        $type = isset($notice['type']) && $notice['type'] === 'error' ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }

    private function redirect($tab)
    {
        wp_safe_redirect(admin_url('admin.php?page=giftaccess-topup&tab=' . sanitize_key($tab)));
        exit;
    }
}
