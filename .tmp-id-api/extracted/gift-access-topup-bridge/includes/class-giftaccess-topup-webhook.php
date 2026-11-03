<?php

if (!defined('ABSPATH')) {
    exit;
}

final class GiftAccess_Topup_Webhook
{
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes()
    {
        register_rest_route('giftaccess-topup/v1', '/webhook', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    public function handle(WP_REST_Request $request)
    {
        $secret = (string) $this->plugin->setting('webhook_secret');
        $incoming = (string) $request->get_param('secret');
        if ($secret === '' || !hash_equals($secret, $incoming)) {
            $this->plugin->logger()->log('warning', 'Webhook rejected: invalid secret');
            return new WP_Error('giftaccess_topup_forbidden', __('Secret invalide.', 'gift-access-topup-bridge'), array('status' => 403));
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = array();
        }

        $order_payload = isset($payload['order']) && is_array($payload['order']) ? $payload['order'] : $payload;
        $remote_order_id = $this->pick($order_payload, array('id', 'order_id', 'external_id'), '');
        $reference = $this->pick($order_payload, array('reference', 'external_reference', 'remark'), '');
        $status = strtoupper((string) $this->pick($order_payload, array('status', 'state'), ''));

        $target = $this->plugin->find_local_order_item($remote_order_id, $reference);
        if (!$target) {
            $this->plugin->logger()->log('warning', 'Webhook target not found', array(
                'remote_order_id' => $remote_order_id,
                'reference' => $reference,
                'status' => $status,
            ));

            return array('success' => true, 'message' => 'Webhook received but local order was not found.');
        }

        list($order, $item) = $target;
        $item->update_meta_data('_giftaccess_topup_webhook_payload', wp_json_encode($payload, JSON_UNESCAPED_SLASHES));
        if ($status !== '') {
            $item->update_meta_data('_giftaccess_topup_status', $status);
        }
        if ($remote_order_id !== '') {
            $item->update_meta_data('_giftaccess_topup_order_id', $remote_order_id);
        }
        $item->save();

        $order->add_order_note(sprintf(__('Gift Access Gaming webhook: statut %s pour item #%d.', 'gift-access-topup-bridge'), $status ?: '-', (int) $item->get_id()));

        if (in_array(strtolower($status), array('completed', 'complete', 'success', 'delivered'), true) && $this->plugin->is_enabled('auto_complete_order')) {
            $order->update_status('completed', __('Livraison TopUp complete.', 'gift-access-topup-bridge'));
        }

        if (in_array(strtolower($status), array('failed', 'cancelled', 'canceled', 'refunded'), true)) {
            if ($this->plugin->is_enabled('refund_failed_orders') && function_exists('wc_create_refund')) {
                $this->refund_order_item($order, $item);
            } elseif ($this->plugin->is_enabled('fail_order_on_error')) {
                $order->update_status('failed', __('Livraison TopUp echouee.', 'gift-access-topup-bridge'));
            }
        }

        $order->save();
        $this->plugin->logger()->log('info', 'Webhook processed', array(
            'order_id' => $order->get_id(),
            'item_id' => $item->get_id(),
            'remote_order_id' => $remote_order_id,
            'status' => $status,
        ));

        return array('success' => true);
    }

    private function refund_order_item($order, $item)
    {
        if ($order->get_total_refunded() >= $order->get_total()) {
            return;
        }

        $amount = (float) $item->get_total() + (float) $item->get_total_tax();
        if ($amount <= 0) {
            return;
        }

        $refund = wc_create_refund(array(
            'amount' => $amount,
            'reason' => __('Livraison TopUp echouee.', 'gift-access-topup-bridge'),
            'order_id' => $order->get_id(),
            'line_items' => array(
                $item->get_id() => array(
                    'qty' => $item->get_quantity(),
                    'refund_total' => (float) $item->get_total(),
                    'refund_tax' => array(),
                ),
            ),
        ));

        if (is_wp_error($refund)) {
            $this->plugin->logger()->log('error', 'Refund failed', array(
                'order_id' => $order->get_id(),
                'item_id' => $item->get_id(),
                'error' => $refund->get_error_message(),
            ));
        }
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
