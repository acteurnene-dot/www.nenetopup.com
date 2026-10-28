<?php

if (!defined('ABSPATH')) {
    exit;
}

final class GiftAccess_Topup_WooCommerce
{
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;

        add_action('woocommerce_product_options_general_product_data', array($this, 'render_product_fields'));
        add_action('woocommerce_admin_process_product_object', array($this, 'save_product_fields'));
        add_action('woocommerce_product_after_variable_attributes', array($this, 'render_variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_fields'), 10, 2);

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('woocommerce_before_add_to_cart_button', array($this, 'render_player_field'));
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_cart_item'), 10, 5);
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array($this, 'display_cart_item_data'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_order_item_meta'), 10, 4);
        add_filter('woocommerce_order_item_get_formatted_meta_data', array($this, 'hide_private_order_item_meta'), 10, 2);

        add_action('wp_ajax_giftaccess_topup_verify_player', array($this, 'ajax_verify_player'));
        add_action('wp_ajax_nopriv_giftaccess_topup_verify_player', array($this, 'ajax_verify_player'));

        add_action('woocommerce_payment_complete', array($this, 'process_paid_order'), 20);
        add_action('woocommerce_order_status_processing', array($this, 'process_paid_order'), 20);
        add_action('woocommerce_order_status_completed', array($this, 'process_paid_order'), 20);
    }

    public function render_product_fields()
    {
        echo '<div class="options_group">';
        woocommerce_wp_checkbox(array(
            'id' => '_giftaccess_topup_enabled',
            'label' => __('Gift Access Gaming', 'gift-access-topup-bridge'),
            'description' => __('Activer la livraison par API Gift Access pour ce produit.', 'gift-access-topup-bridge'),
        ));
        woocommerce_wp_text_input(array(
            'id' => '_giftaccess_topup_product_id',
            'label' => __('ID produit API', 'gift-access-topup-bridge'),
            'description' => __('ID du produit ou de la variation cote API.', 'gift-access-topup-bridge'),
        ));
        woocommerce_wp_textarea_input(array(
            'id' => '_giftaccess_topup_required_fields',
            'label' => __('Champs requis JSON', 'gift-access-topup-bridge'),
            'description' => __('Laissez vide sauf besoin avance. Exemple: [{"key":"userid","label":"ID","required":true}]', 'gift-access-topup-bridge'),
        ));
        echo '</div>';
    }

    public function save_product_fields($product)
    {
        $enabled = isset($_POST['_giftaccess_topup_enabled']) ? 'yes' : 'no';
        $product_id = isset($_POST['_giftaccess_topup_product_id']) ? sanitize_text_field(wp_unslash($_POST['_giftaccess_topup_product_id'])) : '';
        $required_fields = isset($_POST['_giftaccess_topup_required_fields']) ? wp_kses_post(wp_unslash($_POST['_giftaccess_topup_required_fields'])) : '';

        $product->update_meta_data('_giftaccess_topup_enabled', $enabled);
        $product->update_meta_data('_giftaccess_topup_product_id', $product_id);
        $product->update_meta_data('_giftaccess_topup_required_fields', $this->sanitize_required_fields_json($required_fields));
    }

    public function render_variation_fields($loop, $variation_data, $variation)
    {
        $variation_id = $variation->ID;
        echo '<div class="form-row form-row-full">';
        woocommerce_wp_checkbox(array(
            'id' => "_giftaccess_topup_variation_enabled_{$loop}",
            'name' => "_giftaccess_topup_variation_enabled[{$variation_id}]",
            'label' => __('Gift Access Gaming', 'gift-access-topup-bridge'),
            'value' => get_post_meta($variation_id, '_giftaccess_topup_enabled', true),
        ));
        woocommerce_wp_text_input(array(
            'id' => "_giftaccess_topup_variation_product_id_{$loop}",
            'name' => "_giftaccess_topup_variation_product_id[{$variation_id}]",
            'label' => __('ID produit API', 'gift-access-topup-bridge'),
            'value' => get_post_meta($variation_id, '_giftaccess_topup_product_id', true),
        ));
        echo '</div>';
    }

    public function save_variation_fields($variation_id, $loop)
    {
        $enabled = isset($_POST['_giftaccess_topup_variation_enabled'][$variation_id]) ? 'yes' : 'no';
        $product_id = isset($_POST['_giftaccess_topup_variation_product_id'][$variation_id])
            ? sanitize_text_field(wp_unslash($_POST['_giftaccess_topup_variation_product_id'][$variation_id]))
            : '';

        update_post_meta($variation_id, '_giftaccess_topup_enabled', $enabled);
        update_post_meta($variation_id, '_giftaccess_topup_product_id', $product_id);
    }

    public function enqueue_assets()
    {
        if (!is_product()) {
            return;
        }

        $product_id = get_queried_object_id();
        if (!$product_id || !$this->mapping_for_product($product_id)) {
            return;
        }

        wp_enqueue_style('giftaccess-topup-product', GIFTACCESS_TOPUP_URL . 'assets/product.css', array(), GIFTACCESS_TOPUP_VERSION);
        wp_enqueue_script('giftaccess-topup-product', GIFTACCESS_TOPUP_URL . 'assets/product.js', array('jquery'), GIFTACCESS_TOPUP_VERSION, true);
        wp_localize_script('giftaccess-topup-product', 'GiftAccessTopupProduct', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('giftaccess_topup_verify_player'),
            'verifyRequired' => $this->plugin->setting('validate_player'),
            'texts' => array(
                'required' => __('Verification ID requise.', 'gift-access-topup-bridge'),
                'emptyId' => __('Entrez un ID avant de verifier.', 'gift-access-topup-bridge'),
                'chooseVariation' => __('Choisissez une variation avant de verifier.', 'gift-access-topup-bridge'),
                'checking' => __('Verification en cours...', 'gift-access-topup-bridge'),
                'failed' => __('ID invalide.', 'gift-access-topup-bridge'),
                'serverError' => __('Verification impossible pour le moment.', 'gift-access-topup-bridge'),
                'verified' => __('Compte verifie', 'gift-access-topup-bridge'),
                'playerName' => __('Nom joueur', 'gift-access-topup-bridge'),
                'playerId' => __('ID joueur', 'gift-access-topup-bridge'),
                'notVerified' => __('En attente de verification', 'gift-access-topup-bridge'),
            ),
        ));
    }

    public function render_player_field()
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        $mapping = $this->mapping_for_product($product->get_id());
        if (!$mapping || !$this->requires_player_id($mapping)) {
            return;
        }

        $input_label = __('ID joueur', 'gift-access-topup-bridge');
        $placeholder = __('ID', 'gift-access-topup-bridge');

        echo '<div class="giftaccess-topup-player-field is-idle" data-giftaccess-topup-player>';
        echo '<div class="giftaccess-topup-player-heading">';
        echo '<label class="giftaccess-topup-sr-only" for="giftaccess_topup_player_id">' . esc_html($input_label) . '</label>';
        echo '<span class="giftaccess-topup-player-badge">' . esc_html__('Verification requise', 'gift-access-topup-bridge') . '</span>';
        echo '</div>';
        echo '<div class="giftaccess-topup-verify-row">';
        echo '<div class="giftaccess-topup-input-shell">';
        echo '<input class="giftaccess-topup-player-input" id="giftaccess_topup_player_id" name="giftaccess_topup_player_id" type="text" inputmode="text" autocomplete="off" aria-label="' . esc_attr($input_label) . '" placeholder="' . esc_attr($placeholder) . '" required />';
        echo '<span class="giftaccess-topup-input-state" aria-hidden="true"></span>';
        echo '</div>';
        echo '</div>';
        echo '<input id="giftaccess_topup_player_verified" name="giftaccess_topup_player_verified" type="hidden" value="no" />';
        echo '<input id="giftaccess_topup_player_name" name="giftaccess_topup_player_name" type="hidden" value="" />';
        echo '<div class="giftaccess-topup-verify-result" aria-live="polite"></div>';
        echo '<p class="giftaccess-topup-required-note">' . esc_html(sprintf(__('Verification ID requise pour %s.', 'gift-access-topup-bridge'), $product->get_name())) . '</p>';
        echo '</div>';
    }

    public function validate_cart_item($passed, $product_id, $quantity, $variation_id = 0, $variations = array())
    {
        $mapping = $this->mapping_for_product($product_id, $variation_id);
        if (!$mapping || !$this->requires_player_id($mapping)) {
            return $passed;
        }

        $player_id = $this->posted_player_id();
        if ($player_id === '') {
            wc_add_notice(__('Entrez l ID joueur avant d ajouter au panier.', 'gift-access-topup-bridge'), 'error');
            return false;
        }

        if ($this->plugin->is_enabled('validate_player') && $this->posted_player_verified() !== 'yes') {
            wc_add_notice(__('Verifiez l ID joueur avant d ajouter au panier.', 'gift-access-topup-bridge'), 'error');
            return false;
        }

        return $passed;
    }

    public function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        $mapping = $this->mapping_for_product($product_id, $variation_id);
        if (!$mapping) {
            return $cart_item_data;
        }

        $player_id = $this->posted_player_id();
        if ($player_id !== '') {
            $cart_item_data['giftaccess_topup_player_id'] = $player_id;
            $cart_item_data['giftaccess_topup_player_name'] = $this->posted_player_name();
            $cart_item_data['giftaccess_topup_fields'] = $this->fields_payload($mapping, $player_id);
            $cart_item_data['giftaccess_topup_unique'] = md5($mapping['product_id'] . '|' . $player_id . '|' . microtime(true));
        }

        return $cart_item_data;
    }

    public function display_cart_item_data($item_data, $cart_item)
    {
        if (!empty($cart_item['giftaccess_topup_player_id'])) {
            $item_data[] = array(
                'name' => __('ID joueur', 'gift-access-topup-bridge'),
                'value' => esc_html((string) $cart_item['giftaccess_topup_player_id']),
            );
        }

        if (!empty($cart_item['giftaccess_topup_player_name'])) {
            $item_data[] = array(
                'name' => __('Nom joueur', 'gift-access-topup-bridge'),
                'value' => esc_html((string) $cart_item['giftaccess_topup_player_name']),
            );
        }

        return $item_data;
    }

    public function add_order_item_meta($item, $cart_item_key, $values, $order)
    {
        if (!empty($values['giftaccess_topup_player_id'])) {
            $player_id = $this->sanitize_player_id($values['giftaccess_topup_player_id']);
            $item->add_meta_data(__('ID joueur', 'gift-access-topup-bridge'), $player_id, true);
            $item->add_meta_data('_giftaccess_topup_player_id', $player_id, true);
        }

        if (!empty($values['giftaccess_topup_player_name'])) {
            $item->add_meta_data(__('Nom joueur', 'gift-access-topup-bridge'), sanitize_text_field((string) $values['giftaccess_topup_player_name']), true);
        }

        if (!empty($values['giftaccess_topup_fields']) && is_array($values['giftaccess_topup_fields'])) {
            $item->add_meta_data('_giftaccess_topup_fields', wp_json_encode($values['giftaccess_topup_fields'], JSON_UNESCAPED_SLASHES), true);
        }
    }

    public function hide_private_order_item_meta($formatted_meta, $item)
    {
        $hidden = array(
            '_giftaccess_topup_player_id',
            '_giftaccess_topup_fields',
            '_giftaccess_topup_product_id',
            '_giftaccess_topup_order_id',
            '_giftaccess_topup_reference',
            '_giftaccess_topup_status',
            '_giftaccess_topup_response',
            '_giftaccess_topup_webhook_payload',
        );

        foreach ($formatted_meta as $key => $meta) {
            if (in_array((string) $meta->key, $hidden, true)) {
                unset($formatted_meta[$key]);
            }
        }

        return $formatted_meta;
    }

    public function ajax_verify_player()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'giftaccess_topup_verify_player')) {
            wp_send_json_error(array('message' => __('Session expiree. Rechargez la page.', 'gift-access-topup-bridge')), 403);
        }

        $product_id = absint(isset($_POST['product_id']) ? $_POST['product_id'] : 0);
        $variation_id = absint(isset($_POST['variation_id']) ? $_POST['variation_id'] : 0);
        $player_id = $this->sanitize_player_id(isset($_POST['player_id']) ? wp_unslash($_POST['player_id']) : '');

        $mapping = $this->mapping_for_product($product_id, $variation_id);
        if (!$mapping) {
            wp_send_json_error(array('message' => __('Produit non configure pour la verification.', 'gift-access-topup-bridge')), 422);
        }

        if ($player_id === '') {
            wp_send_json_error(array('message' => __('Entrez un ID valide.', 'gift-access-topup-bridge')), 422);
        }

        $result = $this->plugin->api()->lookup_player($mapping['product_id'], $this->fields_payload($mapping, $player_id));
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 422);
        }

        wp_send_json_success(array(
            'player_id' => $player_id,
            'player_name' => $this->extract_player_name($result, $player_id),
        ));
    }

    public function process_paid_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $lock = (int) $order->get_meta('_giftaccess_topup_processing_lock');
        if ($lock > 0 && (time() - $lock) < 120) {
            return;
        }

        $order->update_meta_data('_giftaccess_topup_processing_lock', (string) time());
        $order->save();

        try {
            $this->process_order_items($order);
        } catch (Throwable $exception) {
            $this->plugin->logger()->log('error', 'Order processing exception', array(
                'order_id' => $order->get_id(),
                'error' => $exception->getMessage(),
            ));
        }

        $order->delete_meta_data('_giftaccess_topup_processing_lock');
        $order->save();
    }

    private function process_order_items($order)
    {
        $processed = 0;
        $failed = 0;

        foreach ($order->get_items() as $item_id => $item) {
            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $mapping = $this->mapping_for_product($product_id, $variation_id);
            if (!$mapping) {
                continue;
            }

            $existing_status = strtoupper((string) $item->get_meta('_giftaccess_topup_status'));
            if (in_array($existing_status, array('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'LOCAL_ERROR'), true)) {
                continue;
            }

            $player_id = $this->sanitize_player_id($item->get_meta('_giftaccess_topup_player_id') ?: $item->get_meta(__('ID joueur', 'gift-access-topup-bridge')));
            if ($this->requires_player_id($mapping) && $player_id === '') {
                $failed++;
                $item->update_meta_data('_giftaccess_topup_status', 'LOCAL_ERROR');
                $item->save();
                $order->add_order_note(sprintf(__('Gift Access Gaming: ID joueur manquant pour item #%d.', 'gift-access-topup-bridge'), (int) $item_id));
                continue;
            }

            $reference = 'wc_order:' . $order->get_id() . ';item:' . $item_id;
            $quantity = max(1, (int) $item->get_quantity());
            $fields = $this->decode_json($item->get_meta('_giftaccess_topup_fields'));
            if (!$fields && $player_id !== '') {
                $fields = $this->fields_payload($mapping, $player_id);
            }

            $payload = array(
                'product_id' => $mapping['product_id'],
                'quantity' => $quantity,
                'reference' => $reference,
                'fields' => $fields,
            );

            $item->update_meta_data('_giftaccess_topup_product_id', $mapping['product_id']);
            $item->update_meta_data('_giftaccess_topup_reference', $reference);
            $item->update_meta_data('_giftaccess_topup_status', 'PROCESSING');
            $item->save();

            $result = $this->plugin->api()->create_order($payload, 'giftaccess-topup-' . $order->get_id() . '-' . $item_id);
            if (is_wp_error($result)) {
                $failed++;
                $item->update_meta_data('_giftaccess_topup_status', 'FAILED');
                $item->update_meta_data('_giftaccess_topup_response', wp_json_encode(array('error' => $result->get_error_message()), JSON_UNESCAPED_SLASHES));
                $item->save();
                $order->add_order_note(sprintf(__('Gift Access Gaming: commande API refusee pour item #%d: %s', 'gift-access-topup-bridge'), (int) $item_id, $result->get_error_message()));
                continue;
            }

            $order_payload = $this->extract_order_payload($result);
            $remote_order_id = $this->pick($order_payload, array('id', 'order.id', 'data.order.id'), '');
            $remote_status = strtoupper((string) $this->pick($order_payload, array('status', 'order.status', 'data.order.status'), 'PENDING'));

            $item->update_meta_data('_giftaccess_topup_order_id', $remote_order_id);
            $item->update_meta_data('_giftaccess_topup_status', $remote_status);
            $item->update_meta_data('_giftaccess_topup_response', wp_json_encode($result, JSON_UNESCAPED_SLASHES));
            $item->save();

            $this->plugin->remember_remote_order($remote_order_id, $reference, $order->get_id(), $item_id);
            $processed++;
            $order->add_order_note(sprintf(__('Gift Access Gaming: commande API envoyee pour item #%d%s.', 'gift-access-topup-bridge'), (int) $item_id, $remote_order_id ? ' #' . $remote_order_id : ''));
        }

        if ($failed > 0 && $this->plugin->is_enabled('fail_order_on_error')) {
            $order->update_status('failed', __('Une commande TopUp a echoue.', 'gift-access-topup-bridge'));
        } elseif ($processed > 0) {
            $order->save();
        }
    }

    public function sync_product_from_api(array $api_product)
    {
        $api_product_id = sanitize_text_field((string) (isset($api_product['id']) ? $api_product['id'] : ''));
        if ($api_product_id === '') {
            return new WP_Error('giftaccess_topup_invalid_product', __('Produit API invalide.', 'gift-access-topup-bridge'));
        }

        $variations = isset($api_product['variations']) && is_array($api_product['variations']) ? $api_product['variations'] : array();
        $required_fields = isset($api_product['required_fields']) && is_array($api_product['required_fields']) ? array_values($api_product['required_fields']) : array();
        $existing_id = $this->find_existing_product_id($api_product_id);
        $product = $existing_id > 0 ? wc_get_product($existing_id) : false;

        if (!$product || ($variations && !$product->is_type('variable')) || (!$variations && !$product->is_type('simple'))) {
            $product = $variations ? new WC_Product_Variable() : new WC_Product_Simple();
        }

        $name = sanitize_text_field((string) (isset($api_product['name']) ? $api_product['name'] : $api_product_id));
        $product->set_name($name);
        $product->set_status($this->plugin->setting('import_status', 'draft'));
        $product->set_catalog_visibility('visible');
        $product->set_virtual(true);

        if (!$variations) {
            $price = $this->api_price($api_product);
            if ($price !== null) {
                $product->set_regular_price((string) $price);
                $product->set_price((string) $price);
            }
        }

        if (!empty($api_product['description'])) {
            $product->set_short_description(wp_kses_post((string) $api_product['description']));
        }

        $product->update_meta_data('_giftaccess_topup_enabled', 'yes');
        $product->update_meta_data('_giftaccess_topup_product_id', $api_product_id);
        $product->update_meta_data('_giftaccess_topup_required_fields', wp_json_encode($required_fields, JSON_UNESCAPED_SLASHES));
        $product->update_meta_data('_giftaccess_topup_synced_at', current_time('mysql'));
        $product->update_meta_data('_giftaccess_topup_api_payload', wp_json_encode($api_product, JSON_UNESCAPED_SLASHES));

        if (!empty($api_product['category'])) {
            $term_id = $this->ensure_category((string) $api_product['category']);
            if ($term_id > 0) {
                $product->set_category_ids(array($term_id));
            }
        }

        $product_id = $product->save();

        $variation_count = 0;
        if ($variations) {
            $labels = array();
            foreach ($variations as $variation) {
                if (is_array($variation) && !empty($variation['label'])) {
                    $labels[] = sanitize_text_field((string) $variation['label']);
                }
            }

            $attribute = new WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name('denomination');
            $attribute->set_options(array_values(array_unique($labels)));
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $product->set_attributes(array($attribute));
            $product->save();

            $existing = $this->existing_variations_by_api_id($product_id);
            foreach ($variations as $variation) {
                if (!is_array($variation)) {
                    continue;
                }

                $variation_api_id = sanitize_text_field((string) (isset($variation['id']) ? $variation['id'] : ''));
                $label = sanitize_text_field((string) (isset($variation['label']) ? $variation['label'] : $variation_api_id));
                if ($variation_api_id === '' || $label === '') {
                    continue;
                }

                $variation_id = isset($existing[$variation_api_id]) ? (int) $existing[$variation_api_id] : 0;
                $woo_variation = $variation_id > 0 ? wc_get_product($variation_id) : new WC_Product_Variation();
                if (!$woo_variation) {
                    $woo_variation = new WC_Product_Variation();
                }

                $woo_variation->set_parent_id($product_id);
                $woo_variation->set_attributes(array('denomination' => $label));
                $price = $this->api_price($variation);
                if ($price !== null) {
                    $woo_variation->set_regular_price((string) $price);
                    $woo_variation->set_price((string) $price);
                }
                $woo_variation->set_virtual(true);
                $woo_variation->set_status(!empty($variation['available']) ? 'publish' : 'private');
                $woo_variation->update_meta_data('_giftaccess_topup_enabled', 'yes');
                $woo_variation->update_meta_data('_giftaccess_topup_product_id', $variation_api_id);
                $woo_variation->update_meta_data('_giftaccess_topup_parent_product_id', $api_product_id);
                $woo_variation->update_meta_data('_giftaccess_topup_required_fields', wp_json_encode($required_fields, JSON_UNESCAPED_SLASHES));
                $woo_variation->update_meta_data('_giftaccess_topup_api_payload', wp_json_encode($variation, JSON_UNESCAPED_SLASHES));
                $woo_variation->save();
                $variation_count++;
            }
        }

        $this->plugin->remember_product_import($api_product_id, $product_id, $name);

        return array(
            'product_id' => $product_id,
            'variations' => $variation_count,
        );
    }

    public function mapping_for_product($product_id, $variation_id = 0)
    {
        $ids = array();
        if ($variation_id > 0) {
            $ids[] = (int) $variation_id;
        }
        if ($product_id > 0) {
            $ids[] = (int) $product_id;
        }

        foreach ($ids as $id) {
            $enabled = get_post_meta($id, '_giftaccess_topup_enabled', true);
            $api_product_id = trim((string) get_post_meta($id, '_giftaccess_topup_product_id', true));
            if ($enabled === 'yes' && $api_product_id !== '') {
                $required_fields = $this->decode_json(get_post_meta($id, '_giftaccess_topup_required_fields', true));
                if (!$required_fields && $variation_id > 0 && $product_id > 0) {
                    $required_fields = $this->decode_json(get_post_meta($product_id, '_giftaccess_topup_required_fields', true));
                }

                return array(
                    'product_id' => $api_product_id,
                    'parent_product_id' => trim((string) get_post_meta($id, '_giftaccess_topup_parent_product_id', true)),
                    'required_fields' => is_array($required_fields) ? $required_fields : array(),
                );
            }
        }

        return null;
    }

    private function requires_player_id(array $mapping)
    {
        return is_array($this->player_field($mapping));
    }

    private function player_field(array $mapping)
    {
        foreach ($mapping['required_fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = strtolower((string) (isset($field['key']) ? $field['key'] : ''));
            if (in_array($key, array('userid', 'user_id', 'player_id', 'account_id', 'customer_identifier'), true)) {
                return $field;
            }
        }

        return null;
    }

    private function fields_payload(array $mapping, $player_id)
    {
        $player_id = $this->sanitize_player_id($player_id);
        $fields = array(
            'userid' => $player_id,
            'user_id' => $player_id,
            'player_id' => $player_id,
            'account_id' => $player_id,
            'customer_identifier' => $player_id,
        );

        $field = $this->player_field($mapping);
        if (is_array($field) && !empty($field['key'])) {
            $fields[(string) $field['key']] = $player_id;
        }

        return array(
            'userid' => $player_id,
            'user_id' => $player_id,
            'player_id' => $player_id,
            'fields' => $fields,
        );
    }

    private function find_existing_product_id($api_product_id)
    {
        $query = new WP_Query(array(
            'post_type' => array('product'),
            'post_status' => array('publish', 'draft', 'private'),
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_giftaccess_topup_product_id',
                    'value' => (string) $api_product_id,
                ),
            ),
        ));

        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private function existing_variations_by_api_id($product_id)
    {
        $map = array();
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return $map;
        }

        foreach ($product->get_children() as $variation_id) {
            $api_id = get_post_meta((int) $variation_id, '_giftaccess_topup_product_id', true);
            if ($api_id !== '') {
                $map[(string) $api_id] = (int) $variation_id;
            }
        }

        return $map;
    }

    private function ensure_category($name)
    {
        $name = sanitize_text_field($name);
        if ($name === '') {
            return 0;
        }

        $term = term_exists($name, 'product_cat');
        if (is_array($term) && !empty($term['term_id'])) {
            return (int) $term['term_id'];
        }

        $created = wp_insert_term($name, 'product_cat');
        return is_array($created) && !empty($created['term_id']) ? (int) $created['term_id'] : 0;
    }

    private function api_price(array $source)
    {
        foreach (array('price', 'min_amount', 'amount') as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return round((float) $source[$key], 2);
            }
        }

        return null;
    }

    private function posted_player_id()
    {
        return $this->sanitize_player_id(isset($_POST['giftaccess_topup_player_id']) ? wp_unslash($_POST['giftaccess_topup_player_id']) : '');
    }

    private function posted_player_name()
    {
        return sanitize_text_field(isset($_POST['giftaccess_topup_player_name']) ? wp_unslash($_POST['giftaccess_topup_player_name']) : '');
    }

    private function posted_player_verified()
    {
        return sanitize_key(isset($_POST['giftaccess_topup_player_verified']) ? wp_unslash($_POST['giftaccess_topup_player_verified']) : 'no');
    }

    public function sanitize_player_id($value)
    {
        return preg_replace('/[^A-Za-z0-9_.:@-]/', '', sanitize_text_field((string) $value));
    }

    private function sanitize_required_fields_json($json)
    {
        $json = trim((string) $json);
        if ($json === '') {
            return '';
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '';
        }

        return wp_json_encode(array_values($decoded), JSON_UNESCAPED_SLASHES);
    }

    private function decode_json($json)
    {
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private function extract_player_name(array $result, $player_id = '')
    {
        $preferred_paths = array(
            'lookup.player.name',
            'lookup.player.player_name',
            'lookup.name',
            'data.lookup.player.name',
            'data.lookup.name',
            'result.lookup.player.name',
            'result.lookup.name',
            'player.name',
            'player.player_name',
            'data.player.name',
            'data.player.player_name',
            'profile.name',
            'profile.player_name',
            'result.player.name',
            'result.player.player_name',
            'name',
            'player_name',
        );

        foreach ($preferred_paths as $path) {
            $candidate = $this->clean_player_name($this->pick($result, array($path), ''), $player_id);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $this->find_player_name($result, $player_id);
    }

    private function find_player_name($source, $player_id = '', $depth = 0)
    {
        if (!is_array($source) || $depth > 6) {
            return '';
        }

        $name_keys = array(
            'playername',
            'name',
            'nickname',
            'nick',
            'username',
            'displayname',
            'accountname',
            'gamename',
            'charactername',
            'charname',
            'rolename',
            'ign',
        );

        foreach ($source as $key => $value) {
            $normalized_key = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $key));
            if (in_array($normalized_key, $name_keys, true)) {
                $candidate = $this->clean_player_name($value, $player_id);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        foreach ($source as $value) {
            if (is_array($value)) {
                $candidate = $this->find_player_name($value, $player_id, $depth + 1);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function clean_player_name($value, $player_id = '')
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $name = sanitize_text_field((string) $value);
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $submitted_id = $this->sanitize_player_id($player_id);
        if ($submitted_id !== '' && $name === $submitted_id) {
            return '';
        }

        if (preg_match('/^\d+$/', $name)) {
            return '';
        }

        return $name;
    }

    private function extract_order_payload(array $result)
    {
        if (isset($result['order']) && is_array($result['order'])) {
            return $result['order'];
        }
        if (isset($result['data']['order']) && is_array($result['data']['order'])) {
            return $result['data']['order'];
        }
        return $result;
    }

    private function pick(array $source, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = $source;
            foreach (explode('.', (string) $path) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }
}
