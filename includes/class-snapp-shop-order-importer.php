<?php
/** Imports SnappShop order events into WooCommerce orders. */
if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Order_Importer
{
    private const CURSOR_OPTION = 'snapp_shop_order_sync_cursor';
    private const CRON_HOOK = 'snapp_shop_order_sync_cron';
    private const ORDER_NUMBER_META = '_snapp_shop_order_number';
    private const FINGERPRINT_META = '_snapp_shop_order_fingerprint';

    public function __construct(private Snapp_Shop_Settings $settings, private Snapp_Shop_Api_Client $api)
    {
        add_action('admin_post_snapp_shop_sync_orders', [$this, 'manual_sync']);
        add_action(self::CRON_HOOK, [$this, 'sync_orders']);
    }

    public static function activate(): void { if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time(), isset(wp_get_schedules()['five_minutes']) ? 'five_minutes' : 'hourly', self::CRON_HOOK); }

    public static function deactivate(): void { wp_clear_scheduled_hook(self::CRON_HOOK); }

    public function manual_sync(): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('You do not have permission to manage WooCommerce settings.');
        check_admin_referer('snapp_shop_sync_orders');
        $result = $this->sync_orders();
        wp_safe_redirect(add_query_arg(['page' => 'snapp-shop-order-sync', 'tab' => 'orders', 'snapp_sync' => $result['message'] ?? 'Sync completed.'], admin_url('admin.php')));
        exit;
    }

    public function sync_orders(): array
    {
        if (!class_exists('WooCommerce')) return ['message' => 'WooCommerce is required.'];
        $settings = $this->settings->get();
        if (!$settings['token'] || !$settings['vendor_id'] || !$settings['user_agent']) return ['message' => 'Please configure token, vendor ID, and user-agent first.'];
        $cursor = (string)get_option(self::CURSOR_OPTION, '');
        $created = $updated = $skipped = 0;
        for ($page = 0; $page < 10; $page++) {
            $events = $this->api->get('/vendors/' . rawurlencode($settings['vendor_id']) . '/orders/events', $cursor ? ['cursor' => $cursor] : []);
            if (is_wp_error($events)) return ['message' => 'Sync failed: ' . $events->get_error_message()];
            foreach ((array)($events['data'] ?? []) as $event) {
                $number = (string)($event['order_number'] ?? '');
                $type = (string)($event['event_type'] ?? '');
                if (!$number || !in_array($type, ['NEW_ORDER', 'CHANGE_STATUS', 'CANCELLATION'], true)) continue;
                $order = $this->api->get('/vendors/' . rawurlencode($settings['vendor_id']) . '/orders/' . rawurlencode($number));
                if (is_wp_error($order) || !is_array($order['data'] ?? null)) {
                    $skipped++;
                    continue;
                }
                $result = $this->upsert_order($settings['vendor_id'], $order['data'], $type);
                $result === 'created' ? $created++ : ($result === 'updated' ? $updated++ : $skipped++);
            }
            $pagination = (array)($events['meta']['pagination'] ?? []);
            $cursor = (string)($pagination['next_cursor'] ?? '');
            if ($cursor) update_option(self::CURSOR_OPTION, $cursor, false);
            if (empty($pagination['has_more']) || !$cursor) break;
        }
        return ['message' => sprintf('Sync complete: %d created, %d updated, %d skipped.', $created, $updated, $skipped)];
    }

    private function upsert_order(string $vendorId, array $data, string $eventType): string
    {
        $number = (string)($data['order_number'] ?? '');
        if (!$number) return 'skipped';
        $fingerprint = hash('sha256', wp_json_encode([$vendorId, $data]));
        $existing = wc_get_orders(['limit' => 1, 'return' => 'ids', 'meta_key' => self::ORDER_NUMBER_META, 'meta_value' => $number]);
        $order = $existing ? wc_get_order((int)$existing[0]) : wc_create_order();
        if (!$order || is_wp_error($order)) return 'skipped';
        if ($order->get_meta(self::FINGERPRINT_META, true) === $fingerprint) return 'skipped';
        foreach ($order->get_items('line_item') as $id => $item) $order->remove_item($id);
        foreach ((array)($data['items'] ?? []) as $item) {
            $sku = (string)($item['sku'] ?? $item['vendor_product_info_id'] ?? '');
            $product = $sku ? wc_get_product(wc_get_product_id_by_sku($sku)) : false;
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            if (!$product || !$quantity) continue;
            $id = $order->add_product($product, $quantity);
            if ($id && array_key_exists('final_price', $item)) {
                $line = $order->get_item($id);
                $total = (float)preg_replace('/[^0-9.\-]/', '', (string)$item['final_price']);
                $line->set_subtotal($total);
                $line->set_total($total);
                $line->save();
            }
        }
        if (!$order->get_items('line_item')) return 'skipped';
        $customer = (array)($data['customer'] ?? []);
        $order->set_address(['first_name' => sanitize_text_field($customer['first_name'] ?? ''), 'last_name' => sanitize_text_field($customer['last_name'] ?? ''), 'phone' => sanitize_text_field($customer['phone'] ?? '')], 'billing');
        $order->update_meta_data(self::ORDER_NUMBER_META, $number);
        $order->update_meta_data(self::FINGERPRINT_META, $fingerprint);
        $order->update_meta_data('_snapp_shop_vendor_id', $vendorId);
        $status = strtoupper((string)($data['order_status'] ?? ''));
        $order->set_status($eventType === 'CANCELLATION' || in_array($status, ['CANCELED', 'CANCELLED'], true) ? 'cancelled' : ($status === 'COMPLETED' ? 'completed' : ($status === 'PROCESSING' ? 'processing' : 'on-hold')));
        $order->calculate_totals();
        $order->save();
        return $existing ? 'updated' : 'created';
    }
}
