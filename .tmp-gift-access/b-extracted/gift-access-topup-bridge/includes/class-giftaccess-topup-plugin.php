<?php

if (!defined('ABSPATH')) {
    exit;
}

final class GiftAccess_Topup_Plugin
{
    const OPTION = 'giftaccess_topup_settings';
    const PRODUCT_CACHE_OPTION = 'giftaccess_topup_product_cache';
    const PRODUCT_IMPORTS_OPTION = 'giftaccess_topup_product_imports';
    const MAP_OPTION = 'giftaccess_topup_order_map';
    const LOG_OPTION = 'giftaccess_topup_logs';

    private static $instance = null;

    private $logger;
    private $api;
    private $admin;
    private $woocommerce;
    private $webhook;
    private $settings_cache = null;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_filter('gettext', array($this, 'translate_text'), 20, 3);
        add_filter('ngettext', array($this, 'translate_plural'), 20, 5);

        $this->logger = new GiftAccess_Topup_Logger($this);
        $this->api = new GiftAccess_Topup_API($this);

        if (is_admin()) {
            $this->admin = new GiftAccess_Topup_Admin($this);
            add_action('admin_init', array($this, 'ensure_settings_seeded'), 20);
            add_action('admin_notices', array($this, 'setup_notice'));
        }

        if (class_exists('WooCommerce')) {
            $this->woocommerce = new GiftAccess_Topup_WooCommerce($this);
        } else {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }

        $this->webhook = new GiftAccess_Topup_Webhook($this);
    }

    public function api()
    {
        return $this->api;
    }

    public function logger()
    {
        return $this->logger;
    }

    public function woocommerce()
    {
        return $this->woocommerce;
    }

    public function woocommerce_missing_notice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>Gift Access Gaming:</strong> WooCommerce doit etre actif pour utiliser ce plugin.</p></div>';
    }

    public function defaults()
    {
        return array(
            'base_url' => 'https://portal.gift-access.com/api/v1',
            'api_key' => '',
            'api_secret' => '',
            'timeout' => '30',
            'import_status' => 'draft',
            'validate_player' => 'yes',
            'auto_complete_order' => 'no',
            'fail_order_on_error' => 'yes',
            'refund_failed_orders' => 'no',
            'webhook_secret' => '',
            'log_enabled' => 'yes',
            'debug_enabled' => 'no',
            'language' => 'en',
            'setup_required' => 'no',
        );
    }

    public function settings()
    {
        if (is_array($this->settings_cache)) {
            return $this->settings_cache;
        }

        $settings = get_option(self::OPTION, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        $settings = wp_parse_args($settings, $this->defaults());

        if ($settings['webhook_secret'] === '') {
            $settings['webhook_secret'] = wp_generate_password(32, false, false);
        }

        if (!in_array((string) $settings['language'], array('fr', 'en', 'es'), true)) {
            $settings['language'] = 'en';
        }

        $this->settings_cache = $settings;
        return $settings;
    }

    public function update_settings(array $settings)
    {
        $this->settings_cache = null;
        update_option(self::OPTION, wp_parse_args($settings, $this->defaults()), false);
    }

    public function ensure_settings_seeded()
    {
        $settings = get_option(self::OPTION, array());
        if (!is_array($settings)) {
            $settings = array();
        }

        $settings = wp_parse_args($settings, $this->defaults());
        if ($settings['webhook_secret'] === '') {
            $settings['webhook_secret'] = wp_generate_password(32, false, false);
            $this->update_settings($settings);
        }
    }

    public function setting($key, $default = '')
    {
        $settings = $this->settings();
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    public function is_enabled($key)
    {
        return $this->setting($key) === 'yes';
    }

    public function language()
    {
        $language = (string) $this->setting('language', 'en');
        return in_array($language, array('fr', 'en', 'es'), true) ? $language : 'en';
    }

    public function needs_setup()
    {
        return $this->setting('setup_required', 'no') === 'yes';
    }

    public function setup_notice()
    {
        if (!$this->needs_setup() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $url = admin_url('admin.php?page=giftaccess-topup&tab=settings');
        echo '<div class="notice notice-info"><p><strong>Gift Access Gaming:</strong> ' . esc_html__('Choisissez la langue du plugin pour terminer la premiere configuration.', 'gift-access-topup-bridge') . ' <a href="' . esc_url($url) . '">' . esc_html__('Ouvrir les reglages', 'gift-access-topup-bridge') . '</a></p></div>';
    }

    public function sanitize_settings($input)
    {
        $old = get_option(self::OPTION, array());
        if (!is_array($old)) {
            $old = array();
        }

        $old = wp_parse_args($old, $this->defaults());
        $input = is_array($input) ? $input : array();
        $settings = $this->defaults();

        $settings['base_url'] = isset($input['base_url']) && trim((string) $input['base_url']) !== ''
            ? esc_url_raw((string) $input['base_url'])
            : $settings['base_url'];
        $settings['api_key'] = isset($input['api_key']) && trim((string) $input['api_key']) !== ''
            ? sanitize_text_field((string) $input['api_key'])
            : (string) $old['api_key'];
        $settings['api_secret'] = isset($input['api_secret']) && trim((string) $input['api_secret']) !== ''
            ? sanitize_text_field((string) $input['api_secret'])
            : (string) $old['api_secret'];
        $settings['timeout'] = (string) max(5, min(120, absint(isset($input['timeout']) ? $input['timeout'] : $settings['timeout'])));
        $settings['import_status'] = in_array(isset($input['import_status']) ? $input['import_status'] : '', array('draft', 'publish'), true)
            ? sanitize_key($input['import_status'])
            : 'draft';
        $settings['language'] = in_array(isset($input['language']) ? $input['language'] : '', array('fr', 'en', 'es'), true)
            ? sanitize_key($input['language'])
            : (string) $old['language'];
        $settings['setup_required'] = 'no';
        $settings['webhook_secret'] = isset($input['webhook_secret']) && trim((string) $input['webhook_secret']) !== ''
            ? sanitize_text_field((string) $input['webhook_secret'])
            : (string) $old['webhook_secret'];

        foreach (array('validate_player', 'auto_complete_order', 'fail_order_on_error', 'refund_failed_orders', 'log_enabled', 'debug_enabled') as $checkbox) {
            $settings[$checkbox] = isset($input[$checkbox]) && in_array($input[$checkbox], array('yes', '1', 1, true), true) ? 'yes' : 'no';
        }

        return $settings;
    }

    public function translate_text($translation, $text, $domain)
    {
        if ($domain !== 'gift-access-topup-bridge') {
            return $translation;
        }

        $language = $this->language();
        $translations = $this->translations();
        return isset($translations[$language][$text]) ? $translations[$language][$text] : $translation;
    }

    public function translate_plural($translation, $single, $plural, $number, $domain)
    {
        if ($domain !== 'gift-access-topup-bridge') {
            return $translation;
        }

        $language = $this->language();
        $source = ((int) $number === 1) ? $single : $plural;
        $translations = $this->translations();
        return isset($translations[$language][$source]) ? $translations[$language][$source] : $translation;
    }

    private function translations()
    {
        return array(
            'fr' => array(
                'Reglages TopUp' => 'Réglages TopUp',
                'Importez uniquement les produits top-up autorises vers WooCommerce.' => 'Importez uniquement les produits top-up autorisés vers WooCommerce.',
                'Suivez les evenements API, webhooks et commandes.' => 'Suivez les événements API, webhooks et commandes.',
                'Reglages' => 'Réglages',
                'Choisissez la langue utilisee par les pages admin et les messages client du plugin.' => 'Choisissez la langue utilisée par les pages admin et les messages client du plugin.',
                'Premiere configuration' => 'Première configuration',
                'Selectionnez la langue de preference avant de continuer la configuration du module.' => 'Sélectionnez la langue de préférence avant de continuer la configuration du module.',
                'Langue preferee' => 'Langue préférée',
                'Francais' => 'Français',
                'Espanol' => 'Español',
                'Cle API' => 'Clé API',
                'Laissez vide pour garder le secret actuel.' => 'Laissez vide pour garder le secret actuel.',
                'Regles appliquees quand une commande WooCommerce est payee.' => 'Règles appliquées quand une commande WooCommerce est payée.',
                'Publie' => 'Publié',
                'Exiger verification ID avant panier' => 'Exiger vérification ID avant panier',
                'Completer la commande Woo si livraison complete' => 'Compléter la commande Woo si livraison complète',
                'Marquer la commande Woo en echec si API refuse' => 'Marquer la commande Woo en échec si API refuse',
                'Rembourser automatiquement si retour en echec' => 'Rembourser automatiquement si retour en échec',
                'URL a copier dans Gift Access pour recevoir les changements de statut.' => 'URL à copier dans Gift Access pour recevoir les changements de statut.',
                'Le mode debug ajoute les requetes et reponses API dans les logs locaux. A garder desactive en production sauf diagnostic.' => 'Le mode debug ajoute les requêtes et réponses API dans les logs locaux. À garder désactivé en production sauf diagnostic.',
                'Enregistrer les reglages' => 'Enregistrer les réglages',
                'Rafraichissez la liste, puis importez uniquement les produits top-up disponibles via Gift Access.' => 'Rafraîchissez la liste, puis importez uniquement les produits top-up disponibles via Gift Access.',
                'Derniere mise a jour: %s' => 'Dernière mise à jour : %s',
                'Rafraichir la liste' => 'Rafraîchir la liste',
                'Utile pour importer un produit precis sans parcourir toute la liste.' => 'Utile pour importer un produit précis sans parcourir toute la liste.',
                'Mettre a jour' => 'Mettre à jour',
                'Importe' => 'Importé',
                'Les erreurs API, webhooks et traitements de commandes sont conserves localement pour diagnostic.' => 'Les erreurs API, webhooks et traitements de commandes sont conservés localement pour diagnostic.',
                'Evenements recents' => 'Événements récents',
                '%d entree conservee.' => '%d entrée conservée.',
                '%d entrees conservees.' => '%d entrées conservées.',
                'Quand une commande, un webhook ou une erreur API arrive, elle apparaitra ici.' => 'Quand une commande, un webhook ou une erreur API arrive, elle apparaîtra ici.',
                'Detail du log' => 'Détail du log',
                'Contexte complet de levenement selectionne.' => 'Contexte complet de l’événement sélectionné.',
                'Resume' => 'Résumé',
                'Informations principales de cette entree.' => 'Informations principales de cette entrée.',
                'Payload redacte conserve par le plugin.' => 'Payload redacté conservé par le plugin.',
                'Cette entree de log nest plus disponible. Les logs recents peuvent deplacer les anciennes lignes.' => 'Cette entrée de log n’est plus disponible. Les logs récents peuvent déplacer les anciennes lignes.',
                '%d produit(s) autorise(s) charges.' => '%d produit(s) autorisé(s) chargé(s).',
                'Ce produit ne peut pas etre importe via Gift Access.' => 'Ce produit ne peut pas être importé via Gift Access.',
                'Produit Woo #%d synchronise avec %d variation(s).' => 'Produit Woo #%d synchronisé avec %d variation(s).',
                'Ouvrir les reglages' => 'Ouvrir les réglages',
                'Choisissez la langue du plugin pour terminer la premiere configuration.' => 'Choisissez la langue du plugin pour terminer la première configuration.',
                'ID du produit ou de la variation cote API.' => 'ID du produit ou de la variation côté API.',
                'Laissez vide sauf besoin avance. Exemple: [{"key":"userid","label":"ID","required":true}]' => 'Laissez vide sauf besoin avancé. Exemple : [{"key":"userid","label":"ID","required":true}]',
                'Verification ID requise.' => 'Vérification ID requise.',
                'Entrez un ID avant de verifier.' => 'Entrez un ID avant de vérifier.',
                'Choisissez une variation avant de verifier.' => 'Choisissez une variation avant de vérifier.',
                'Verification en cours...' => 'Vérification en cours...',
                'Verification impossible pour le moment.' => 'Vérification impossible pour le moment.',
                'Compte verifie' => 'Compte vérifié',
                'En attente de verification' => 'En attente de vérification',
                'Verification requise' => 'Vérification requise',
                'Verifier' => 'Vérifier',
                'Verification ID requise pour %s.' => 'Vérification ID requise pour %s.',
                'Verifiez l ID joueur avant d ajouter au panier.' => 'Vérifiez l ID joueur avant d ajouter au panier.',
                'Session expiree. Rechargez la page.' => 'Session expirée. Rechargez la page.',
                'Produit non configure pour la verification.' => 'Produit non configuré pour la vérification.',
                'Commande API refusee' => 'Commande API refusée',
                'Ajoutez la cle API et le secret avant de tester.' => 'Ajoutez la clé API et le secret avant de tester.',
                'La requete API a echoue.' => 'La requête API a échoué.',
                'Livraison TopUp echouee.' => 'Livraison TopUp échouée.',
                'Action non autorisee.' => 'Action non autorisée.',
                'Une commande TopUp a echoue.' => 'Une commande TopUp a échoué.',
                'Gift Access Gaming: commande API refusee pour item #%d: %s' => 'Gift Access Gaming: commande API refusée pour item #%d : %s',
                'Gift Access Gaming: commande API envoyee pour item #%d%s.' => 'Gift Access Gaming: commande API envoyée pour item #%d%s.',
                'Configuree' => 'Configurée',
                'A configurer' => 'À configurer',
                'Produits deja lies' => 'Produits déjà liés',
                'Derniers evenements' => 'Derniers événements',
                'Gift Access Gaming' => 'Gift Access Gaming',
                'WooCommerce gaming' => 'WooCommerce gaming',
                'Logs gaming' => 'Logs gaming',
                'Sections Gift Access Gaming' => 'Sections Gift Access Gaming',
                'Gift Access Gaming: ID joueur manquant pour item #%d.' => 'Gift Access Gaming: ID joueur manquant pour item #%d.',
                'Gift Access Gaming webhook: statut %s pour item #%d.' => 'Gift Access Gaming webhook: statut %s pour item #%d.',
            ),
            'en' => array(
                'Reglages TopUp' => 'TopUp settings',
                'Configurez la connexion API, la langue, la livraison et les webhooks.' => 'Configure the API connection, language, delivery and webhooks.',
                'Import produits' => 'Product import',
                'Importez uniquement les produits top-up autorises vers WooCommerce.' => 'Import only authorized top-up products into WooCommerce.',
                'Logs' => 'Logs',
                'Suivez les evenements API, webhooks et commandes.' => 'Track API, webhook and order events.',
                'Reglages' => 'Settings',
                'Langue du plugin' => 'Plugin language',
                'Choisissez la langue utilisee par les pages admin et les messages client du plugin.' => 'Choose the language used by the plugin admin pages and customer messages.',
                'Premiere configuration' => 'First setup',
                'Selectionnez la langue de preference avant de continuer la configuration du module.' => 'Select the preferred language before continuing the setup.',
                'Langue preferee' => 'Preferred language',
                'Francais' => 'French',
                'English' => 'English',
                'Espanol' => 'Spanish',
                'Connexion API' => 'API connection',
                'Identifiants utilises par WooCommerce pour communiquer avec Gift Access.' => 'Credentials used by WooCommerce to communicate with Gift Access.',
                'Base URL API' => 'API base URL',
                'Cle API' => 'API key',
                'Secret API' => 'API secret',
                'Laissez vide pour garder le secret actuel.' => 'Leave blank to keep the current secret.',
                'Livraison Woo' => 'Woo delivery',
                'Regles appliquees quand une commande WooCommerce est payee.' => 'Rules applied when a WooCommerce order is paid.',
                'Statut des imports' => 'Import status',
                'Brouillon' => 'Draft',
                'Publie' => 'Published',
                'Exiger verification ID avant panier' => 'Require ID verification before cart',
                'Completer la commande Woo si livraison complete' => 'Complete the Woo order when delivery is complete',
                'Marquer la commande Woo en echec si API refuse' => 'Mark the Woo order failed when the API declines',
                'Rembourser automatiquement si retour en echec' => 'Automatically refund on failed callback',
                'Webhook' => 'Webhook',
                'URL a copier dans Gift Access pour recevoir les changements de statut.' => 'URL to copy into Gift Access to receive status updates.',
                'Secret webhook' => 'Webhook secret',
                'Webhook URL' => 'Webhook URL',
                'Journalisation' => 'Logging',
                'Gardez les logs actifs pour diagnostiquer les commandes et webhooks.' => 'Keep logs enabled to diagnose orders and webhooks.',
                'Activer les logs' => 'Enable logs',
                'Logs debug' => 'Debug logs',
                'Le mode debug ajoute les requetes et reponses API dans les logs locaux. A garder desactive en production sauf diagnostic.' => 'Debug mode adds API requests and responses to local logs. Keep it disabled in production unless diagnosing.',
                'Enregistrer les reglages' => 'Save settings',
                'Catalogue Gift Access' => 'Gift Access catalog',
                'Rafraichissez la liste, puis importez uniquement les produits top-up disponibles via Gift Access.' => 'Refresh the list, then import only top-up products available through Gift Access.',
                'Derniere mise a jour: %s' => 'Last update: %s',
                'Rafraichir la liste' => 'Refresh list',
                'Importer par ID produit' => 'Import by product ID',
                'Utile pour importer un produit precis sans parcourir toute la liste.' => 'Useful to import a specific product without browsing the whole list.',
                'ID produit' => 'Product ID',
                'Importer / mettre a jour' => 'Import / update',
                'Produits API disponibles' => 'Available API products',
                '%d produit charge depuis le cache.' => '%d product loaded from cache.',
                '%d produits charges depuis le cache.' => '%d products loaded from cache.',
                'Aucun produit en cache' => 'No cached products',
                'Lancez un rafraichissement pour afficher les produits actifs disponibles via votre API.' => 'Refresh the list to show active products available through your API.',
                'ID' => 'ID',
                'Nom' => 'Name',
                'Type' => 'Type',
                'Prix' => 'Price',
                'Woo' => 'Woo',
                'Action' => 'Action',
                'Mettre a jour' => 'Update',
                'Importer' => 'Import',
                'Importe' => 'Imported',
                'Logs gaming' => 'Gaming logs',
                'Les erreurs API, webhooks et traitements de commandes sont conserves localement pour diagnostic.' => 'API errors, webhooks and order processing events are kept locally for diagnosis.',
                'Vider les logs' => 'Clear logs',
                'Evenements recents' => 'Recent events',
                '%d entree conservee.' => '%d saved entry.',
                '%d entrees conservees.' => '%d saved entries.',
                'Aucun log pour le moment' => 'No logs yet',
                'Quand une commande, un webhook ou une erreur API arrive, elle apparaitra ici.' => 'When an order, webhook or API error happens, it will appear here.',
                'Date' => 'Date',
                'Niveau' => 'Level',
                'Message' => 'Message',
                'Contexte' => 'Context',
                'Voir' => 'View',
                'Detail du log' => 'Log detail',
                'Contexte complet de levenement selectionne.' => 'Full context for the selected event.',
                'Retour aux logs' => 'Back to logs',
                'Resume' => 'Summary',
                'Informations principales de cette entree.' => 'Main information for this entry.',
                'Position' => 'Position',
                'Contexte JSON' => 'JSON context',
                'Payload redacte conserve par le plugin.' => 'Redacted payload kept by the plugin.',
                'Log introuvable' => 'Log not found',
                'Cette entree de log nest plus disponible. Les logs recents peuvent deplacer les anciennes lignes.' => 'This log entry is no longer available. Recent logs can move older rows.',
                'Connexion API valide.' => 'API connection valid.',
                '%d produit(s) autorise(s) charges.' => '%d authorized product(s) loaded.',
                'ID produit manquant.' => 'Missing product ID.',
                'Produit introuvable via Gift Access.' => 'Product not found through Gift Access.',
                'Ce produit ne peut pas etre importe via Gift Access.' => 'This product cannot be imported through Gift Access.',
                'Produit Woo #%d synchronise avec %d variation(s).' => 'Woo product #%d synced with %d variation(s).',
                'Logs vides.' => 'Logs cleared.',
                'Actif' => 'Active',
                'Inactif' => 'Inactive',
                'Ouvrir les reglages' => 'Open settings',
                'Choisissez la langue du plugin pour terminer la premiere configuration.' => 'Choose the plugin language to finish the first setup.',
                'Gift Access Gaming' => 'Gift Access Gaming',
                'WooCommerce gaming' => 'WooCommerce gaming',
                'Sections Gift Access Gaming' => 'Gift Access Gaming sections',
                'Gift Access Gaming: ID joueur manquant pour item #%d.' => 'Gift Access Gaming: missing player ID for item #%d.',
                'Gift Access Gaming: commande API refusee pour item #%d: %s' => 'Gift Access Gaming: API order declined for item #%d: %s',
                'Gift Access Gaming: commande API envoyee pour item #%d%s.' => 'Gift Access Gaming: API order sent for item #%d%s.',
                'Gift Access Gaming webhook: statut %s pour item #%d.' => 'Gift Access Gaming webhook: status %s for item #%d.',
                'Activer la livraison par API Gift Access pour ce produit.' => 'Enable Gift Access API delivery for this product.',
                'ID produit API' => 'API product ID',
                'ID du produit ou de la variation cote API.' => 'Product or variation ID on the API side.',
                'Champs requis JSON' => 'Required fields JSON',
                'Laissez vide sauf besoin avance. Exemple: [{"key":"userid","label":"ID","required":true}]' => 'Leave blank except for advanced needs. Example: [{"key":"userid","label":"ID","required":true}]',
                'Verification ID requise.' => 'ID verification required.',
                'Entrez un ID avant de verifier.' => 'Enter an ID before verifying.',
                'Choisissez une variation avant de verifier.' => 'Choose a variation before verifying.',
                'Verification en cours...' => 'Verifying...',
                'ID invalide.' => 'Invalid ID.',
                'Verification impossible pour le moment.' => 'Verification is currently unavailable.',
                'Compte verifie' => 'Account verified',
                'Nom joueur' => 'Player name',
                'ID joueur' => 'Player ID',
                'En attente de verification' => 'Waiting for verification',
                'Verification requise' => 'Verification required',
                'Verifier' => 'Verify',
                'Verification ID requise pour %s.' => 'ID verification required for %s.',
                'Entrez l ID joueur avant d ajouter au panier.' => 'Enter the player ID before adding to cart.',
                'Verifiez l ID joueur avant d ajouter au panier.' => 'Verify the player ID before adding to cart.',
                'Session expiree. Rechargez la page.' => 'Session expired. Reload the page.',
                'Produit non configure pour la verification.' => 'Product is not configured for verification.',
                'Entrez un ID valide.' => 'Enter a valid ID.',
                'Commande API refusee' => 'API order declined',
            ),
            'es' => array(
                'Reglages TopUp' => 'Ajustes TopUp',
                'Configurez la connexion API, la langue, la livraison et les webhooks.' => 'Configura la conexión API, el idioma, la entrega y los webhooks.',
                'Import produits' => 'Importar productos',
                'Importez uniquement les produits top-up autorises vers WooCommerce.' => 'Importa solo productos top-up autorizados a WooCommerce.',
                'Logs' => 'Registros',
                'Suivez les evenements API, webhooks et commandes.' => 'Sigue eventos API, webhooks y pedidos.',
                'Reglages' => 'Ajustes',
                'Langue du plugin' => 'Idioma del plugin',
                'Choisissez la langue utilisee par les pages admin et les messages client du plugin.' => 'Elige el idioma usado por las páginas admin y los mensajes del cliente.',
                'Premiere configuration' => 'Primera configuración',
                'Selectionnez la langue de preference avant de continuer la configuration du module.' => 'Selecciona el idioma preferido antes de continuar la configuración del módulo.',
                'Langue preferee' => 'Idioma preferido',
                'Francais' => 'Francés',
                'English' => 'Inglés',
                'Espanol' => 'Español',
                'Connexion API' => 'Conexión API',
                'Identifiants utilises par WooCommerce pour communiquer avec Gift Access.' => 'Credenciales usadas por WooCommerce para comunicarse con Gift Access.',
                'Base URL API' => 'URL base API',
                'Cle API' => 'Clave API',
                'Secret API' => 'Secreto API',
                'Laissez vide pour garder le secret actuel.' => 'Déjalo vacío para conservar el secreto actual.',
                'Livraison Woo' => 'Entrega Woo',
                'Regles appliquees quand une commande WooCommerce est payee.' => 'Reglas aplicadas cuando un pedido WooCommerce está pagado.',
                'Statut des imports' => 'Estado de importación',
                'Brouillon' => 'Borrador',
                'Publie' => 'Publicado',
                'Exiger verification ID avant panier' => 'Exigir verificación de ID antes del carrito',
                'Completer la commande Woo si livraison complete' => 'Completar el pedido Woo si la entrega se completa',
                'Marquer la commande Woo en echec si API refuse' => 'Marcar el pedido Woo como fallido si la API rechaza',
                'Rembourser automatiquement si retour en echec' => 'Reembolsar automáticamente si el callback falla',
                'Webhook' => 'Webhook',
                'URL a copier dans Gift Access pour recevoir les changements de statut.' => 'URL para copiar en Gift Access y recibir cambios de estado.',
                'Secret webhook' => 'Secreto webhook',
                'Webhook URL' => 'URL webhook',
                'Journalisation' => 'Registros',
                'Gardez les logs actifs pour diagnostiquer les commandes et webhooks.' => 'Mantener registros activos para diagnosticar pedidos y webhooks.',
                'Activer les logs' => 'Activar registros',
                'Logs debug' => 'Registros debug',
                'Le mode debug ajoute les requetes et reponses API dans les logs locaux. A garder desactive en production sauf diagnostic.' => 'El modo debug agrega solicitudes y respuestas API en los registros locales. Mantener desactivado en producción salvo diagnóstico.',
                'Enregistrer les reglages' => 'Guardar ajustes',
                'Catalogue Gift Access' => 'Catálogo Gift Access',
                'Rafraichissez la liste, puis importez uniquement les produits top-up disponibles via Gift Access.' => 'Actualiza la lista e importa solo productos top-up disponibles vía Gift Access.',
                'Derniere mise a jour: %s' => 'Última actualización: %s',
                'Rafraichir la liste' => 'Actualizar lista',
                'Importer par ID produit' => 'Importar por ID de producto',
                'Utile pour importer un produit precis sans parcourir toute la liste.' => 'Útil para importar un producto específico sin recorrer toda la lista.',
                'ID produit' => 'ID producto',
                'Importer / mettre a jour' => 'Importar / actualizar',
                'Produits API disponibles' => 'Productos API disponibles',
                '%d produit charge depuis le cache.' => '%d producto cargado desde cache.',
                '%d produits charges depuis le cache.' => '%d productos cargados desde cache.',
                'Aucun produit en cache' => 'No hay productos en caché',
                'Lancez un rafraichissement pour afficher les produits actifs disponibles via votre API.' => 'Actualiza para mostrar productos activos disponibles vía tu API.',
                'ID' => 'ID',
                'Nom' => 'Nombre',
                'Type' => 'Tipo',
                'Prix' => 'Precio',
                'Woo' => 'Woo',
                'Action' => 'Acción',
                'Mettre a jour' => 'Actualizar',
                'Importer' => 'Importar',
                'Importe' => 'Importado',
                'Logs gaming' => 'Registros gaming',
                'Les erreurs API, webhooks et traitements de commandes sont conserves localement pour diagnostic.' => 'Errores API, webhooks y procesos de pedidos se guardan localmente para diagnóstico.',
                'Vider les logs' => 'Vaciar registros',
                'Evenements recents' => 'Eventos recientes',
                '%d entree conservee.' => '%d entrada guardada.',
                '%d entrees conservees.' => '%d entradas guardadas.',
                'Aucun log pour le moment' => 'Sin registros por ahora',
                'Quand une commande, un webhook ou une erreur API arrive, elle apparaitra ici.' => 'Cuando llegue un pedido, webhook o error API, aparecerá aquí.',
                'Date' => 'Fecha',
                'Niveau' => 'Nivel',
                'Message' => 'Mensaje',
                'Contexte' => 'Contexto',
                'Voir' => 'Ver',
                'Detail du log' => 'Detalle del registro',
                'Contexte complet de levenement selectionne.' => 'Contexto completo del evento seleccionado.',
                'Retour aux logs' => 'Volver a registros',
                'Resume' => 'Resumen',
                'Informations principales de cette entree.' => 'Información principal de esta entrada.',
                'Position' => 'Posición',
                'Contexte JSON' => 'Contexto JSON',
                'Payload redacte conserve par le plugin.' => 'Payload redactado guardado por el plugin.',
                'Log introuvable' => 'Registro no encontrado',
                'Cette entree de log nest plus disponible. Les logs recents peuvent deplacer les anciennes lignes.' => 'Esta entrada ya no está disponible. Los registros recientes pueden mover líneas antiguas.',
                'Connexion API valide.' => 'Conexión API válida.',
                '%d produit(s) autorise(s) charges.' => '%d producto(s) autorizado(s) cargado(s).',
                'ID produit manquant.' => 'Falta ID de producto.',
                'Produit introuvable via Gift Access.' => 'Producto no encontrado vía Gift Access.',
                'Ce produit ne peut pas etre importe via Gift Access.' => 'Este producto no se puede importar vía Gift Access.',
                'Produit Woo #%d synchronise avec %d variation(s).' => 'Producto Woo #%d sincronizado con %d variación(es).',
                'Logs vides.' => 'Registros vaciados.',
                'Actif' => 'Activo',
                'Inactif' => 'Inactivo',
                'Ouvrir les reglages' => 'Abrir ajustes',
                'Choisissez la langue du plugin pour terminer la premiere configuration.' => 'Elige el idioma del plugin para terminar la primera configuración.',
                'Gift Access Gaming' => 'Gift Access Gaming',
                'WooCommerce gaming' => 'WooCommerce gaming',
                'Sections Gift Access Gaming' => 'Secciones Gift Access Gaming',
                'Gift Access Gaming: ID joueur manquant pour item #%d.' => 'Gift Access Gaming: falta el ID jugador para el item #%d.',
                'Gift Access Gaming: commande API refusee pour item #%d: %s' => 'Gift Access Gaming: pedido API rechazado para el item #%d: %s',
                'Gift Access Gaming: commande API envoyee pour item #%d%s.' => 'Gift Access Gaming: pedido API enviado para el item #%d%s.',
                'Gift Access Gaming webhook: statut %s pour item #%d.' => 'Gift Access Gaming webhook: estado %s para el item #%d.',
                'Activer la livraison par API Gift Access pour ce produit.' => 'Activar entrega por API Gift Access para este producto.',
                'ID produit API' => 'ID producto API',
                'ID du produit ou de la variation cote API.' => 'ID del producto o variación del lado API.',
                'Champs requis JSON' => 'Campos requeridos JSON',
                'Laissez vide sauf besoin avance. Exemple: [{"key":"userid","label":"ID","required":true}]' => 'Dejar vacío salvo necesidad avanzada. Ejemplo: [{"key":"userid","label":"ID","required":true}]',
                'Verification ID requise.' => 'Verificación de ID requerida.',
                'Entrez un ID avant de verifier.' => 'Ingresa un ID antes de verificar.',
                'Choisissez une variation avant de verifier.' => 'Elige una variación antes de verificar.',
                'Verification en cours...' => 'Verificando...',
                'ID invalide.' => 'ID inválido.',
                'Verification impossible pour le moment.' => 'Verificación no disponible por ahora.',
                'Compte verifie' => 'Cuenta verificada',
                'Nom joueur' => 'Nombre jugador',
                'ID joueur' => 'ID jugador',
                'En attente de verification' => 'Esperando verificación',
                'Verification requise' => 'Verificación requerida',
                'Verifier' => 'Verificar',
                'Verification ID requise pour %s.' => 'Verificación de ID requerida para %s.',
                'Entrez l ID joueur avant d ajouter au panier.' => 'Ingresa el ID jugador antes de agregar al carrito.',
                'Verifiez l ID joueur avant d ajouter au panier.' => 'Verifica el ID jugador antes de agregar al carrito.',
                'Session expiree. Rechargez la page.' => 'Sesión expirada. Recarga la página.',
                'Produit non configure pour la verification.' => 'Producto no configurado para verificación.',
                'Entrez un ID valide.' => 'Ingresa un ID válido.',
                'Commande API refusee' => 'Pedido API rechazado',
            ),
        );
    }

    public function product_cache()
    {
        $cache = get_option(self::PRODUCT_CACHE_OPTION, array());
        if (!is_array($cache)) {
            return array('fetched_at' => '', 'products' => array());
        }

        return array(
            'fetched_at' => sanitize_text_field((string) (isset($cache['fetched_at']) ? $cache['fetched_at'] : '')),
            'products' => isset($cache['products']) && is_array($cache['products']) ? $cache['products'] : array(),
        );
    }

    public function remember_product_cache(array $products)
    {
        update_option(self::PRODUCT_CACHE_OPTION, array(
            'fetched_at' => current_time('mysql'),
            'products' => $products,
        ), false);
    }

    public function product_imports()
    {
        $imports = get_option(self::PRODUCT_IMPORTS_OPTION, array());
        if (!is_array($imports)) {
            return array();
        }

        $clean = array();
        foreach ($imports as $api_product_id => $data) {
            if (!is_array($data)) {
                continue;
            }

            $api_product_id = sanitize_text_field((string) $api_product_id);
            $product_id = absint(isset($data['product_id']) ? $data['product_id'] : 0);
            if ($api_product_id === '' || $product_id <= 0) {
                continue;
            }

            $clean[$api_product_id] = array(
                'product_id' => $product_id,
                'product_name' => sanitize_text_field((string) (isset($data['product_name']) ? $data['product_name'] : '')),
                'updated_at' => sanitize_text_field((string) (isset($data['updated_at']) ? $data['updated_at'] : '')),
            );
        }

        return $clean;
    }

    public function remember_product_import($api_product_id, $product_id, $product_name = '')
    {
        $api_product_id = sanitize_text_field((string) $api_product_id);
        $product_id = absint($product_id);

        if ($api_product_id === '' || $product_id <= 0) {
            return;
        }

        $imports = $this->product_imports();
        $imports[$api_product_id] = array(
            'product_id' => $product_id,
            'product_name' => sanitize_text_field((string) $product_name),
            'updated_at' => current_time('mysql'),
        );

        uasort($imports, static function ($a, $b) {
            return strcmp((string) (isset($b['updated_at']) ? $b['updated_at'] : ''), (string) (isset($a['updated_at']) ? $a['updated_at'] : ''));
        });

        update_option(self::PRODUCT_IMPORTS_OPTION, $imports, false);
    }

    public function webhook_url()
    {
        return add_query_arg('secret', rawurlencode((string) $this->setting('webhook_secret')), rest_url('giftaccess-topup/v1/webhook'));
    }

    public function remember_remote_order($remote_order_id, $reference, $order_id, $item_id)
    {
        $map = get_option(self::MAP_OPTION, array());
        if (!is_array($map)) {
            $map = array();
        }

        foreach (array($remote_order_id, $reference) as $key) {
            $key = sanitize_text_field((string) $key);
            if ($key === '') {
                continue;
            }

            $map[$key] = array(
                'order_id' => (int) $order_id,
                'item_id' => (int) $item_id,
                'updated_at' => time(),
            );
        }

        uasort($map, static function ($a, $b) {
            return (int) (isset($b['updated_at']) ? $b['updated_at'] : 0) <=> (int) (isset($a['updated_at']) ? $a['updated_at'] : 0);
        });

        update_option(self::MAP_OPTION, array_slice($map, 0, 500, true), false);
    }

    public function find_local_order_item($remote_order_id = '', $reference = '')
    {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        foreach (array($reference, $remote_order_id) as $candidate) {
            if (preg_match('/wc_order:(\d+);item:(\d+)/', (string) $candidate, $matches)) {
                $order = wc_get_order((int) $matches[1]);
                if ($order) {
                    $item = $order->get_item((int) $matches[2]);
                    if ($item) {
                        return array($order, $item);
                    }
                }
            }
        }

        $map = get_option(self::MAP_OPTION, array());
        if (!is_array($map)) {
            return null;
        }

        foreach (array($remote_order_id, $reference) as $key) {
            $key = (string) $key;
            if ($key === '' || empty($map[$key])) {
                continue;
            }

            $order = wc_get_order((int) (isset($map[$key]['order_id']) ? $map[$key]['order_id'] : 0));
            if (!$order) {
                continue;
            }

            $item = $order->get_item((int) (isset($map[$key]['item_id']) ? $map[$key]['item_id'] : 0));
            if ($item) {
                return array($order, $item);
            }
        }

        return null;
    }
}
