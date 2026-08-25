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

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('snappshop fetch-orders', [$this, 'cli_fetch_orders']);
        }
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
        error_log('Snapp_Shop_Order_Importer::sync_orders() called');
        if (!class_exists('WooCommerce')) return ['message' => 'WooCommerce is required.'];

        $fetched = $this->fetch_orders(10, null, true);
        if (is_wp_error($fetched)) {
            return ['message' => 'Sync failed: ' . $fetched->get_error_message()];
        }

        return $this->sync_fetched_orders($fetched);
    }

    /** Import the orders returned by fetch_orders(). */
    private function sync_fetched_orders(array $fetched): array
    {
        if (!class_exists('WooCommerce')) return ['message' => 'WooCommerce is required.'];

        $settings = $this->settings->get();
        $created = $updated = 0;
        $skipped = (int)($fetched['skipped'] ?? 0);

        foreach ((array)$fetched['orders'] as $fetched_order) {
            $event = (array)($fetched_order['event'] ?? []);
            $data = (array)($fetched_order['order'] ?? []);
            $type = (string)($event['event_type'] ?? '');
            if ($type === 'NEW_ORDER') {
                $result = $this->upsert_order($settings['vendor_id'], $data, $type);
                match ($result) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default   => $skipped++,
                };
            }
        }

        return ['message' => sprintf('Sync complete: %d created, %d updated, %d skipped.', $created, $updated, $skipped)];
    }

    /**
     * Fetch order events and their complete order payloads without importing them.
     *
     * The cursor is only persisted when $persist_cursor is true. This keeps the
     * CLI command read-only while allowing sync_orders() to retain its behavior.
     *
     * @return array|WP_Error
     */
    public function fetch_orders(int $max_pages = 10, ?string $cursor = null, bool $persist_cursor = false)
    {
        $settings = $this->settings->get();
        if (!$settings['token'] || !$settings['vendor_id'] || !$settings['user_agent']) {
            return new WP_Error('snapp_shop_missing_settings', 'Please configure token, vendor ID, and user-agent first.');
        }

        $max_pages = max(1, min(100, $max_pages));
        $cursor = $cursor ?? (string)get_option(self::CURSOR_OPTION, '');
        $orders = [];
        $skipped = 0;
        $pages_fetched = 0;
        $has_more = false;

        for ($page = 0; $page < $max_pages; $page++) {
            $pages_fetched++;
            $events = $this->api->get(
                '/vendors/' . rawurlencode($settings['vendor_id']) . '/orders/events',
                $cursor ? ['cursor' => $cursor] : []
            );
            if (is_wp_error($events)) {
                return $events;
            }

            foreach ((array)($events['data'] ?? []) as $event) {
                $number = (string)($event['order_number'] ?? '');
                $type = (string)($event['event_type'] ?? '');
                if (!$number || !in_array($type, ['NEW_ORDER', 'CHANGE_STATUS', 'CANCELLATION'], true)) {
                    continue;
                }

                $order = $this->api->get(
                    '/vendors/' . rawurlencode($settings['vendor_id']) . '/orders/' . rawurlencode($number)
                );
                if (is_wp_error($order) || !is_array($order['data'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $orders[] = [
                    'event' => $event,
                    'order' => $order['data'],
                ];
            }

            $pagination = (array)($events['meta']['pagination'] ?? []);
            $next_cursor = (string)($pagination['next_cursor'] ?? '');
            $has_more = !empty($pagination['has_more']) && (bool)$next_cursor;
            $cursor = $next_cursor;

            if ($persist_cursor && $cursor) {
                update_option(self::CURSOR_OPTION, $cursor, false);
            }

            if (!$has_more) {
                break;
            }
        }

        return [
            'orders'      => $orders,
            'pages'       => $pages_fetched,
            'skipped'     => $skipped,
            'next_cursor' => $cursor,
            'has_more'    => $has_more,
        ];
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
            $pid = (int)(explode('-', $item['sku'])[1] ?? 0);
            $product = wc_get_product($pid);
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
        $order->set_address([
            'first_name' => sanitize_text_field($customer['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($customer['last_name'] ?? ''),
            'phone'      => sanitize_text_field($customer['phone'] ?? ''),
        ], 'billing');
        $order->update_meta_data(self::ORDER_NUMBER_META, $number);
        $order->update_meta_data(self::FINGERPRINT_META, $fingerprint);
        $order->update_meta_data('_snapp_shop_vendor_id', $vendorId);
        $status = strtoupper((string)($data['order_status'] ?? ''));
//        $order->set_status();
        $order->calculate_totals();
        $order->save();
        return $existing ? 'updated' : 'created';
    }

    /**
     * Dump fetched order events and payloads through WP-CLI.
     *
     * ## OPTIONS
     *
     * [--cursor=<cursor>]
     * : Start from this API cursor. Defaults to the saved importer cursor.
     *
     * [--pages=<pages>]
     * : Maximum number of event pages to fetch (maximum 100). Default: 10.
     *
     * [--format=<format>]
     * : Output format: json, yaml, or var_dump. Default: json.
     *
     * [--sync]
     * : Import the fetched orders into WooCommerce and advance the saved cursor.
     *
     * ## EXAMPLES
     *
     *     wp snappshop fetch-orders
     *     wp snappshop fetch-orders --pages=2 --format=yaml
     *     wp snappshop fetch-orders --sync
     */
    public function cli_fetch_orders(array $args, array $assoc_args): void
    {
        $pages = isset($assoc_args['pages'])
            ? filter_var($assoc_args['pages'], FILTER_VALIDATE_INT)
            : 10;
        if ($pages === false || $pages < 1) {
            WP_CLI::error('--pages must be a positive integer.');
        }

        $format = strtolower((string)($assoc_args['format'] ?? 'json'));
        if (!in_array($format, ['json', 'yaml', 'var_dump'], true)) {
            WP_CLI::error('--format must be json, yaml, or var_dump.');
        }

        $cursor = isset($assoc_args['cursor']) ? (string)$assoc_args['cursor'] : null;
        $sync = isset($assoc_args['sync']);
        if ($sync && !class_exists('WooCommerce')) {
            WP_CLI::error('WooCommerce is required to use --sync.');
        }

        $result = $this->fetch_orders($pages, $cursor, $sync);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if ($sync) {
            // Fetch once, then import exactly the payloads that are dumped.
            $result['sync'] = $this->sync_fetched_orders($result);
        }

        WP_CLI::print_value($result, ['format' => $format]);
    }
}
