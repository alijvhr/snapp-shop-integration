<?php
/** Authenticated transport for the SnappShop automation API. */
if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Api_Client
{
    private const BASE = 'https://apix.snappshop.ir/automation/v1';

    public function __construct(private Snapp_Shop_Settings $settings) { }

    public function get(string $path, array $query = []) { return $this->request('GET', $path, null, $query); }

    public function patch(string $path, array $body) { return $this->request('PATCH', $path, $body); }

    private function request(string $method, string $path, ?array $body = null, array $query = [])
    {
        $settings = $this->settings->get();
        $url = trailingslashit(self::BASE) . ltrim($path, '/');
        if ($query) $url = add_query_arg($query, $url);
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $settings['token'], 'User-Agent' => $settings['user_agent'], 'Accept' => 'application/json']];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) return $response;
        $status = wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($status < 200 || $status >= 300) return new WP_Error('snapp_shop_api_error', is_array($decoded) ? (string)($decoded['message'] ?? 'Unknown API error') : 'Unknown API error', ['status' => $status]);
        return is_array($decoded) ? $decoded : new WP_Error('snapp_shop_api_invalid_json', 'Invalid JSON response from API.');
    }
}
