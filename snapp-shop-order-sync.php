<?php
/**
 * Plugin Name: Snapp Shop Order Sync
 * Description: Fetches Snapp Shop order events and creates corresponding WooCommerce orders by matching items with product SKU.
 * Version: 1.0.0
 * Author: Snapp Shop Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Order_Sync {
    private const SETTINGS_OPTION = 'snapp_shop_order_sync_settings';
    private const CURSOR_OPTION = 'snapp_shop_order_sync_cursor';
    private const PROCESSED_OPTION = 'snapp_shop_order_sync_processed';
    private const CRON_HOOK = 'snapp_shop_order_sync_cron';
    private const META_ORDER_NUMBER = '_snapp_shop_order_number';
    private const META_FINGERPRINT = '_snapp_shop_order_fingerprint';
    private const API_BASE = 'https://apix.snappshop.ir/automation/v1';
    private const MAX_PAGES_PER_SYNC = 10;
    private const LOCK_DURATION_SECONDS = 5 * MINUTE_IN_SECONDS;
    private const MAX_PROCESSED_ORDERS = 500;
    private const API_TIMEOUT_SECONDS = 20;

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_snapp_shop_sync_orders', [$this, 'handle_manual_sync']);
        add_action(self::CRON_HOOK, [$this, 'sync_orders']);
    }

    public static function activate(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $schedules = wp_get_schedules();
            $recurrence = isset($schedules['five_minutes']) ? 'five_minutes' : 'hourly';
            wp_schedule_event(time() + 60, $recurrence, self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function register_settings(): void {
        register_setting(self::SETTINGS_OPTION, self::SETTINGS_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => [],
        ]);

        add_settings_section(
            'snapp_shop_order_sync_main',
            'Snapp Shop Credentials',
            '__return_false',
            self::SETTINGS_OPTION
        );

        $fields = [
            'token' => 'Token',
            'vendor_id' => 'Vendor ID',
            'user_agent' => 'User-Agent (Unique Code)',
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                [$this, 'render_text_field'],
                self::SETTINGS_OPTION,
                'snapp_shop_order_sync_main',
                ['key' => $key]
            );
        }
    }

    public function sanitize_settings(array $input): array {
        return [
            'token' => isset($input['token']) ? sanitize_text_field($input['token']) : '',
            'vendor_id' => isset($input['vendor_id']) ? sanitize_text_field($input['vendor_id']) : '',
            'user_agent' => isset($input['user_agent']) ? sanitize_text_field($input['user_agent']) : '',
        ];
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Snapp Shop Order Sync',
            'Snapp Shop Sync',
            'manage_woocommerce',
            'snapp-shop-order-sync',
            [$this, 'render_settings_page']
        );
    }

    public function render_text_field(array $args): void {
        $settings = $this->get_settings();
        $key = $args['key'];
        $value = isset($settings[$key]) ? (string) $settings[$key] : '';

        printf(
            '<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" />',
            esc_attr(self::SETTINGS_OPTION),
            esc_attr($key),
            esc_attr($value)
        );
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        $message = isset($_GET['snapp_sync']) ? sanitize_text_field(wp_unslash($_GET['snapp_sync'])) : '';

        echo '<div class="wrap"><h1>Snapp Shop Order Sync</h1>';

        if ($message !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields(self::SETTINGS_OPTION);
        do_settings_sections(self::SETTINGS_OPTION);
        submit_button('Save Settings');
        echo '</form>';

        echo '<hr />';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('snapp_shop_sync_orders');
        echo '<input type="hidden" name="action" value="snapp_shop_sync_orders" />';
        submit_button('Sync Orders Now', 'secondary');
        echo '</form>';
        echo '</div>';
    }

    public function handle_manual_sync(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('snapp_shop_sync_orders');

        $result = $this->sync_orders();
        $message = isset($result['message']) ? $result['message'] : 'Sync completed.';

        wp_safe_redirect(add_query_arg([
            'page' => 'snapp-shop-order-sync',
            'snapp_sync' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    public function sync_orders(): array {
        if (!class_exists('WooCommerce')) {
            return ['message' => 'WooCommerce is required.'];
        }

        $settings = $this->get_settings();
        if (empty($settings['token']) || empty($settings['vendor_id']) || empty($settings['user_agent'])) {
            return ['message' => 'Please configure token, vendor ID, and user-agent first.'];
        }

        $cursor = get_option(self::CURSOR_OPTION, '');
        $processedInRun = [];
        $created = 0;
        $duplicates = 0;
        $skipped = 0;

        for ($page = 0; $page < self::MAX_PAGES_PER_SYNC; $page++) {
            $params = [];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $eventsResponse = $this->api_get('/vendors/' . rawurlencode($settings['vendor_id']) . '/orders/events', $params);
            if (is_wp_error($eventsResponse)) {
                return ['message' => 'Sync failed: ' . $eventsResponse->get_error_message()];
            }

            $events = isset($eventsResponse['data']) && is_array($eventsResponse['data']) ? $eventsResponse['data'] : [];
            foreach ($events as $event) {
                if (($event['event_type'] ?? '') !== 'NEW_ORDER') {
                    continue;
                }

                $orderNumber = isset($event['order_number']) ? (string) $event['order_number'] : '';
                if ($orderNumber === '' || isset($processedInRun[$orderNumber])) {
                    continue;
                }
                $processedInRun[$orderNumber] = true;

                $orderResponse = $this->api_get('/vendors/' . rawurlencode($settings['vendor_id']) . '/orders/' . rawurlencode($orderNumber));
                if (is_wp_error($orderResponse) || !isset($orderResponse['data']) || !is_array($orderResponse['data'])) {
                    $skipped++;
                    continue;
                }

                $createdOrder = $this->create_woocommerce_order($settings['vendor_id'], $orderResponse['data']);
                if ($createdOrder === 'duplicate') {
                    $duplicates++;
                } elseif ($createdOrder === true) {
                    $created++;
                } else {
                    $skipped++;
                }
            }

            $pagination = $eventsResponse['meta']['pagination'] ?? [];
            $nextCursor = isset($pagination['next_cursor']) ? (string) $pagination['next_cursor'] : '';
            if ($nextCursor !== '') {
                update_option(self::CURSOR_OPTION, $nextCursor, false);
            }

            $hasMore = !empty($pagination['has_more']);
            if (!$hasMore) {
                break;
            }

            if ($nextCursor === '' || $nextCursor === $cursor) {
                break;
            }
            $cursor = $nextCursor;
        }

        return [
            'message' => sprintf('Sync complete. Created: %d, duplicates: %d, skipped: %d.', $created, $duplicates, $skipped),
        ];
    }

    private function create_woocommerce_order(string $vendorId, array $orderData) {
        $orderNumber = isset($orderData['order_number']) ? (string) $orderData['order_number'] : '';
        if ($orderNumber === '') {
            return false;
        }

        $fingerprint = $this->build_order_fingerprint($vendorId, $orderData);
        if ($this->is_duplicate_order($orderNumber, $fingerprint)) {
            return 'duplicate';
        }

        $lockKey = 'snapp_shop_order_lock_' . md5($vendorId . '|' . $orderNumber);
        if (get_transient($lockKey)) {
            return 'duplicate';
        }
        set_transient($lockKey, '1', self::LOCK_DURATION_SECONDS);

        try {
            if ($this->is_duplicate_order($orderNumber, $fingerprint)) {
                return 'duplicate';
            }

            $items = isset($orderData['items']) && is_array($orderData['items']) ? $orderData['items'] : [];
            if (empty($items)) {
                return false;
            }

            $wcOrder = wc_create_order();
            if (is_wp_error($wcOrder)) {
                return false;
            }
            $hasLineItems = false;
            $missingSkus = [];

            foreach ($items as $item) {
                $sku = isset($item['sku']) ? trim((string) $item['sku']) : '';
                $quantity = max(1, (int) ($item['quantity'] ?? 1));

                if ($sku === '') {
                    $missingSkus[] = 'item-without-sku';
                    continue;
                }

                $productId = wc_get_product_id_by_sku($sku);
                if (!$productId) {
                    $missingSkus[] = $sku;
                    continue;
                }

                $product = wc_get_product($productId);
                if (!$product) {
                    $missingSkus[] = $sku;
                    continue;
                }

                $wcOrder->add_product($product, $quantity);
                $hasLineItems = true;
            }

            if (!$hasLineItems) {
                $wcOrder->delete(true);
                return false;
            }

            $customer = isset($orderData['customer']) && is_array($orderData['customer']) ? $orderData['customer'] : [];
            $billingData = [
                'first_name' => isset($customer['first_name']) ? sanitize_text_field((string) $customer['first_name']) : '',
                'last_name' => isset($customer['last_name']) ? sanitize_text_field((string) $customer['last_name']) : '',
                'phone' => isset($customer['phone']) ? sanitize_text_field((string) $customer['phone']) : '',
            ];

            $wcOrder->set_address($billingData, 'billing');
            $wcOrder->update_meta_data(self::META_ORDER_NUMBER, $orderNumber);
            $wcOrder->update_meta_data(self::META_FINGERPRINT, $fingerprint);
            $wcOrder->update_meta_data('_snapp_shop_vendor_id', sanitize_text_field($vendorId));
            $wcOrder->update_meta_data('_snapp_shop_raw_order_status', sanitize_text_field((string) ($orderData['order_status'] ?? '')));
            $wcOrder->add_order_note('Imported from Snapp Shop order #' . $orderNumber . '.');

            if (!empty($missingSkus)) {
                $wcOrder->add_order_note('Some Snapp Shop SKUs were not found in WooCommerce and were skipped: ' . implode(', ', array_unique($missingSkus)));
            }

            $wcOrder->calculate_totals();
            $wcOrder->set_status('on-hold');
            $wcOrder->save();

            $this->mark_order_processed($fingerprint, (int) $wcOrder->get_id());
            return true;
        } finally {
            delete_transient($lockKey);
        }
    }

    private function is_duplicate_order(string $orderNumber, string $fingerprint): bool {
        $existing = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'meta_key' => self::META_ORDER_NUMBER,
            'meta_value' => $orderNumber,
        ]);

        if (!empty($existing)) {
            return true;
        }

        $processed = get_option(self::PROCESSED_OPTION, []);
        return is_array($processed) && array_key_exists($fingerprint, $processed);
    }

    private function mark_order_processed(string $fingerprint, int $orderId): void {
        $processed = get_option(self::PROCESSED_OPTION, []);
        if (!is_array($processed)) {
            $processed = [];
        }

        $processed[$fingerprint] = [
            'order_id' => $orderId,
            'processed_at' => time(),
        ];

        if (count($processed) > self::MAX_PROCESSED_ORDERS) {
            $processed = array_slice($processed, -self::MAX_PROCESSED_ORDERS, self::MAX_PROCESSED_ORDERS, true);
        }

        update_option(self::PROCESSED_OPTION, $processed, false);
    }

    private function build_order_fingerprint(string $vendorId, array $orderData): string {
        $orderNumber = isset($orderData['order_number']) ? (string) $orderData['order_number'] : '';
        $createdAt = isset($orderData['created_at']) ? (string) $orderData['created_at'] : '';
        $items = [];

        $rawItems = isset($orderData['items']) && is_array($orderData['items']) ? $orderData['items'] : [];
        foreach ($rawItems as $item) {
            $items[] = [
                'sku' => isset($item['sku']) ? (string) $item['sku'] : '',
                'quantity' => (int) ($item['quantity'] ?? 0),
                'final_price' => (string) ($item['final_price'] ?? ''),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp($a['sku'] . ':' . $a['quantity'], $b['sku'] . ':' . $b['quantity']);
        });

        return hash('sha256', wp_json_encode([
            'vendor_id' => $vendorId,
            'order_number' => $orderNumber,
            'created_at' => $createdAt,
            'items' => $items,
        ]));
    }

    private function api_get(string $path, array $query = []) {
        $settings = $this->get_settings();
        $url = trailingslashit(self::API_BASE) . ltrim($path, '/');

        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => self::API_TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $settings['token'],
                'User-Agent' => $settings['user_agent'],
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message']) ? (string) $decoded['message'] : 'Unknown API error';
            return new WP_Error('snapp_shop_api_error', $message, ['status' => $status]);
        }

        if (!is_array($decoded)) {
            return new WP_Error('snapp_shop_api_invalid_json', 'Invalid JSON response from API.');
        }

        return $decoded;
    }

    private function get_settings(): array {
        $defaults = [
            'token' => '',
            'vendor_id' => '',
            'user_agent' => '',
        ];

        $settings = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            return $defaults;
        }

        return array_merge($defaults, $settings);
    }
}

add_filter('cron_schedules', static function (array $schedules): array {
    if (!isset($schedules['five_minutes'])) {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'snapp-shop-order-sync'),
        ];
    }

    return $schedules;
});

$snappShopOrderSync = new Snapp_Shop_Order_Sync();
register_activation_hook(__FILE__, ['Snapp_Shop_Order_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['Snapp_Shop_Order_Sync', 'deactivate']);
