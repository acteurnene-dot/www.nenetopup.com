<?php

if (!defined('ABSPATH')) {
    exit;
}

final class GiftAccess_Topup_Logger
{
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    public function log($level, $message, array $context = array())
    {
        if (!$this->plugin->is_enabled('log_enabled') && $level !== 'error') {
            return;
        }

        $logs = get_option(GiftAccess_Topup_Plugin::LOG_OPTION, array());
        if (!is_array($logs)) {
            $logs = array();
        }

        array_unshift($logs, array(
            'time' => current_time('mysql'),
            'level' => sanitize_key((string) $level),
            'message' => sanitize_text_field((string) $message),
            'context' => $this->redact($context),
        ));

        update_option(GiftAccess_Topup_Plugin::LOG_OPTION, array_slice($logs, 0, 250), false);
    }

    public function logs()
    {
        $logs = get_option(GiftAccess_Topup_Plugin::LOG_OPTION, array());
        return is_array($logs) ? $logs : array();
    }

    public function clear()
    {
        delete_option(GiftAccess_Topup_Plugin::LOG_OPTION);
    }

    public function redact($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = array();
        foreach ($value as $key => $item) {
            $key_string = strtolower((string) $key);
            if (preg_match('/(secret|signature|api_key|authorization|password|token|nonce)/', $key_string)) {
                $redacted[$key] = '[redacted]';
                continue;
            }

            $redacted[$key] = is_array($item) ? $this->redact($item) : $item;
        }

        return $redacted;
    }
}
