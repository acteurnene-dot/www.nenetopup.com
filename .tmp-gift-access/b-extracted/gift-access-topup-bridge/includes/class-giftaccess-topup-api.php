<?php

if (!defined('ABSPATH')) {
    exit;
}

final class GiftAccess_Topup_API
{
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function test_connection()
    {
        if ($this->api_key() === '' || $this->api_secret() === '') {
            return new WP_Error('giftaccess_topup_missing_credentials', __('Ajoutez la cle API et le secret avant de tester.', 'gift-access-topup-bridge'));
        }

        $ping = $this->request('GET', '/ping');
        if (is_wp_error($ping)) {
            return $ping;
        }

        $balance = $this->request('GET', '/wallet/balance');
        if (is_wp_error($balance)) {
            return $balance;
        }

        return array(
            'ping' => $ping,
            'balance' => $balance,
        );
    }

    public function list_products()
    {
        $result = $this->request('GET', '/products');
        if (is_wp_error($result)) {
            return $result;
        }

        return $this->pick($result, array('products', 'data.products'), array());
    }

    public function get_product($product_id)
    {
        $product_id = trim((string) $product_id);
        if ($product_id === '') {
            return new WP_Error('giftaccess_topup_missing_product_id', __('ID produit manquant.', 'gift-access-topup-bridge'));
        }

        $result = $this->request('GET', '/products/' . rawurlencode($product_id));
        if (is_wp_error($result)) {
            return $result;
        }

        $product = $this->pick($result, array('product', 'data.product', 'data'), array());
        return is_array($product) ? $product : array();
    }

    public function lookup_player($product_id, array $fields)
    {
        $product_id = trim((string) $product_id);
        if ($product_id === '') {
            return new WP_Error('giftaccess_topup_missing_product_id', __('ID produit manquant.', 'gift-access-topup-bridge'));
        }

        return $this->request('POST', '/products/' . rawurlencode($product_id) . '/player/lookup', $fields);
    }

    public function create_order(array $payload, $idempotency_key = '')
    {
        $idempotency_key = sanitize_text_field((string) $idempotency_key);
        if ($idempotency_key === '' && !empty($payload['reference'])) {
            $idempotency_key = sanitize_text_field((string) $payload['reference']);
        }
        if ($idempotency_key === '' && function_exists('wp_generate_uuid4')) {
            $idempotency_key = wp_generate_uuid4();
        }
        if ($idempotency_key === '') {
            $idempotency_key = uniqid('giftaccess-topup-', true);
        }

        return $this->request('POST', '/orders', $payload, array(
            'Idempotency-Key' => $idempotency_key,
        ));
    }

    public function get_order($order_id)
    {
        $order_id = trim((string) $order_id);
        if ($order_id === '') {
            return new WP_Error('giftaccess_topup_missing_order_id', __('ID commande manquant.', 'gift-access-topup-bridge'));
        }

        return $this->request('GET', '/orders/' . rawurlencode($order_id));
    }

    public function request($method, $endpoint, $payload = null, array $extra_headers = array())
    {
        $method = strtoupper((string) $method);
        $endpoint = '/' . ltrim((string) $endpoint, '/');
        $body = '';

        if ($payload !== null) {
            $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        }

        $timestamp = (string) time();
        $nonce = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true);
        $headers = array_merge(array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'GiftAccess-TopUp-Woo/' . GIFTACCESS_TOPUP_VERSION,
            'X-API-KEY' => $this->api_key(),
            'X-API-TIMESTAMP' => $timestamp,
            'X-API-NONCE' => $nonce,
            'X-API-SIGNATURE' => $this->signature($method, $endpoint, $body, $timestamp, $nonce),
        ), $extra_headers);

        $args = array(
            'method' => $method,
            'timeout' => $this->timeout(),
            'headers' => $headers,
        );

        if ($payload !== null) {
            $args['body'] = $body;
        }

        $url = $this->base_url() . $endpoint;

        if ($this->plugin->is_enabled('debug_enabled')) {
            $this->plugin->logger()->log('debug', 'API request', array(
                'method' => $method,
                'endpoint' => $endpoint,
                'body' => is_array($payload) ? $payload : array(),
            ));
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->plugin->logger()->log('error', 'API transport error', array(
                'endpoint' => $endpoint,
                'error' => $response->get_error_message(),
            ));
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : array('raw' => $raw);

        if ($this->plugin->is_enabled('debug_enabled')) {
            $this->plugin->logger()->log('debug', 'API response', array(
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => $data,
            ));
        }

        if ($status < 200 || $status >= 300 || (isset($data['success']) && !(bool) $data['success'])) {
            $message = $this->error_message($data, wp_remote_retrieve_response_message($response));
            $this->plugin->logger()->log('error', 'API request failed', array(
                'endpoint' => $endpoint,
                'status' => $status,
                'message' => $message,
                'body' => $data,
            ));

            return new WP_Error('giftaccess_topup_api_error', $message, array(
                'status' => $status,
                'body' => $data,
            ));
        }

        return $data;
    }

    private function base_url()
    {
        $url = trim((string) $this->plugin->setting('base_url', 'https://portal.gift-access.com/api/v1'));
        if ($url === '') {
            $url = 'https://portal.gift-access.com/api/v1';
        }

        return rtrim(esc_url_raw($url), '/');
    }

    private function api_key()
    {
        return trim((string) $this->plugin->setting('api_key'));
    }

    private function api_secret()
    {
        return trim((string) $this->plugin->setting('api_secret'));
    }

    private function timeout()
    {
        return max(5, min(120, absint($this->plugin->setting('timeout', 30))));
    }

    private function signature($method, $endpoint, $body, $timestamp, $nonce)
    {
        $base_path = (string) parse_url($this->base_url(), PHP_URL_PATH);
        $path = '/' . trim($base_path, '/') . '/' . ltrim($endpoint, '/');
        $path = preg_replace('#/+#', '/', $path);
        $payload = $timestamp . strtoupper((string) $method) . $path . (string) $body . (string) $nonce;

        return hash_hmac('sha256', $payload, $this->api_secret());
    }

    private function error_message(array $data, $fallback = '')
    {
        foreach (array('error.message', 'message', 'error') as $path) {
            $value = $this->pick($data, array($path), '');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $fallback ? (string) $fallback : __('La requete API a echoue.', 'gift-access-topup-bridge');
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
