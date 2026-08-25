<?php
/** Queues and sends WooCommerce price, sale-price and stock changes to SnappShop. */
if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Product_Inventory_Sync
{
    private const SIGNATURE_META = '_snapp_shop_product_sync_signature';
    private const QUEUE_OPTION = 'snapp_shop_product_inventory_sync_queue';
    private const LAST_MODIFIED_OPTION = 'snapp_shop_product_inventory_sync_last_modified';
    private const INITIAL_SYNC_OPTION = 'snapp_shop_product_inventory_sync_initialized';
    private const LOCK_TRANSIENT = 'snapp_shop_product_inventory_sync_lock';
    private const CRON_HOOK = 'snapp_shop_product_inventory_sync_cron';
    private const MAX_PRODUCTS_PER_REQUEST = 50;
    private const REQUEST_DELAY_SECONDS = 2;

    public function __construct(private Snapp_Shop_Settings $settings, private Snapp_Shop_Api_Client $api)
    {
        add_action('save_post_product', [$this, 'queue_saved_product'], 20, 3);
        add_action('save_post_product_variation', [$this, 'queue_saved_product'], 20, 3);
        add_action('woocommerce_product_set_stock', [$this, 'queue_product'], 20);
        add_action('woocommerce_variation_set_stock', [$this, 'queue_product'], 20);
        add_action(self::CRON_HOOK, [$this, 'sync_products']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('snappshop sync-product', [$this, 'cli_sync_product']);
        }

        // Also schedule the event for installations upgraded without reactivation.
        self::activate();
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(
                time(),
                isset(wp_get_schedules()['five_minutes']) ? 'five_minutes' : 'hourly',
                self::CRON_HOOK
            );
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_transient(self::LOCK_TRANSIENT);
    }

    /** Backwards-compatible entry point for integrations that called the old save callback. */
    public function sync_saved_product($postId, $post, $update): void
    {
        $this->queue_saved_product($postId, $post, $update);
    }

    /** Queue a product after WordPress has saved it; the HTTP request runs in cron. */
    public function queue_saved_product($postId, $post, $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $this->queue_product((int)$postId);
    }

    /** Queue a product when WooCommerce updates its stock directly. */
    public function queue_product($productId): void
    {
        $productId = is_object($productId) && method_exists($productId, 'get_id')
            ? (int)$productId->get_id()
            : (int)$productId;

        if ($productId <= 0) {
            return;
        }

        $queue = $this->get_queue();
        if (!in_array($productId, $queue, true)) {
            $queue[] = $productId;
            update_option(self::QUEUE_OPTION, $queue, false);
        }
    }

    private function get_queue(): array
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (!is_array($queue)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $queue))));
    }

    /**
     * Discover changed products, then send queued products in API-sized batches.
     * A failed batch remains queued and will be retried by the next cron run.
     */
    public function sync_products(): array
    {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return ['updated' => 0, 'failed' => 0, 'message' => 'A sync is already running.'];
        }

        error_log('Starting SnappShop product inventory sync...');

        set_transient(self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS);

        try {
            if (!class_exists('WooCommerce')) {
                return ['updated' => 0, 'failed' => 0, 'message' => 'WooCommerce is required.'];
            }

            $settings = $this->settings->get();
            if (!$settings['token'] || !$settings['vendor_id'] || !$settings['user_agent']) {
                return ['updated' => 0, 'failed' => 0, 'message' => 'Please configure token, vendor ID, and user-agent first.'];
            }

            $initialSync = !get_option(self::INITIAL_SYNC_OPTION, false)
                && !get_option(self::LAST_MODIFIED_OPTION, false);
            $this->queue_modified_products();
            $queue = $this->get_queue();

            // The first cron run is a full synchronization; later runs are incremental.
            if ($initialSync) {
                $queue = $this->merge_queue($queue, $this->get_all_product_ids());
                update_option(self::QUEUE_OPTION, $queue, false);
                $this->initialize_last_modified_cursor();
                update_option(self::INITIAL_SYNC_OPTION, 1, false);
            } elseif (!get_option(self::INITIAL_SYNC_OPTION, false)) {
                // Recognize installations that already completed the old initial sync.
                update_option(self::INITIAL_SYNC_OPTION, 1, false);
            }

            if (!$queue) {
                return ['updated' => 0, 'failed' => 0, 'message' => 'No products to sync.'];
            }

            $updated = 0;
            $failed = 0;
            $reqNo = 0;

            foreach (array_chunk($queue, self::MAX_PRODUCTS_PER_REQUEST) as $productIds) {
                $batch = $this->build_batch($productIds);

                // Products without a usable SKU/ID, price, or stock cannot be sent.
                $invalidIds = array_values(array_diff($productIds, $batch['ids']));
                if ($invalidIds) {
                    $queue = array_values(array_diff($queue, $invalidIds));
                    update_option(self::QUEUE_OPTION, $queue, false);
                }

                if (!$batch['items']) {
                    continue;
                }

                if ($reqNo++) {
                    sleep(self::REQUEST_DELAY_SECONDS);
                }

                $result = $this->api->patch(
                    '/vendors/' . rawurlencode($settings['vendor_id']) . '/products',
                    ['products' => array_column($batch['items'], 'payload')]
                );

                if (is_wp_error($result) || !is_array($result['data'] ?? null)) {
                    error_log('SnappShop product inventory sync failed: ' . $result->get_error_message());
                    $failed += count($batch['items']);
                    continue;
                }

                $successfulIndexes = $this->successful_indexes($result, count($batch['items']));
                foreach ($successfulIndexes as $index) {
                    $item = $batch['items'][$index];
                    update_post_meta($item['product_id'], self::SIGNATURE_META, $item['signature']);
                    $queue = array_values(array_diff($queue, [$item['product_id']]));
                    $updated++;
                }

                $failed += count($batch['items']) - count($successfulIndexes);
                update_option(self::QUEUE_OPTION, $queue, false);
                if ($reqNo >= 10)
                    break;
            }

            return [
                'updated' => $updated,
                'failed'  => $failed,
                'message' => sprintf('Inventory sync complete: %d updated, %d failed.', $updated, $failed),
            ];
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    /** Add products modified since the previous cron scan to the durable queue. */
    private function queue_modified_products(): array
    {
        global $wpdb;

        $lastModified = (string)get_option(self::LAST_MODIFIED_OPTION, '');
        if (!$lastModified) {
            return [];
        }

        $statuses = [
            'publish',
            'private',
            'pending',
            'draft',
            'future',
        ];
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $typePlaceholders = '%s, %s';
        $query = $wpdb->prepare(
            "SELECT ID, post_modified_gmt
             FROM {$wpdb->posts}
             WHERE post_type IN ($typePlaceholders)
             AND post_status IN ($statusPlaceholders)
             AND post_modified_gmt > %s
             ORDER BY post_modified_gmt ASC, ID ASC",
            array_merge(['product', 'product_variation'], $statuses, [$lastModified])
        );
        $rows = $wpdb->get_results($query);
        $ids = [];
        $latestModified = $lastModified;

        foreach ((array)$rows as $row) {
            $ids[] = (int)$row->ID;
            if ((string)$row->post_modified_gmt > $latestModified) {
                $latestModified = (string)$row->post_modified_gmt;
            }
        }

        if ($latestModified !== $lastModified) {
            update_option(self::LAST_MODIFIED_OPTION, $latestModified, false);
        }

        if (!$ids) {
            return [];
        }

        $queue = $this->merge_queue($this->get_queue(), $ids);
        update_option(self::QUEUE_OPTION, $queue, false);
        return $ids;
    }

    private function merge_queue(array $queue, array $ids): array
    {
        return array_values(array_unique(array_merge($queue, array_map('intval', $ids))));
    }

    private function get_all_product_ids(): array
    {
        global $wpdb;

        $query = "SELECT ID
                  FROM {$wpdb->posts}
                  WHERE post_type IN ('product', 'product_variation')
                  AND post_status NOT IN ('trash', 'auto-draft')
                  ORDER BY ID ASC";

        return array_map('intval', (array)$wpdb->get_col($query));
    }

    private function initialize_last_modified_cursor(): void
    {
        if (get_option(self::LAST_MODIFIED_OPTION, '')) {
            return;
        }

        global $wpdb;
        $latestModified = $wpdb->get_var(
            "SELECT MAX(post_modified_gmt)
             FROM {$wpdb->posts}
             WHERE post_type IN ('product', 'product_variation')
             AND post_status NOT IN ('trash', 'auto-draft')"
        );

        if ($latestModified) {
            update_option(self::LAST_MODIFIED_OPTION, (string)$latestModified, false);
        }
    }

    /** Build a batch while preserving each product's identity and signature. */
    private function build_batch(array $productIds): array
    {
        $items = [];
        $validIds = [];

        foreach ($productIds as $productId) {
            $product = function_exists('wc_get_product') ? wc_get_product((int)$productId) : false;
            if (!$product) {
                continue;
            }

            $payload = $this->build_payload($product);
            if (!$payload) {
                continue;
            }

            $validIds[] = (int)$productId;
            $items[] = [
                'product_id' => (int)$productId,
                'payload'    => $payload,
                'signature'  => hash('sha256', wp_json_encode($payload)),
            ];
        }

        return ['ids' => $validIds, 'items' => $items];
    }

    /** Treat a response without per-item statuses as an all-items success. */
    private function successful_indexes(array $result, int $itemCount): array
    {
        if (array_key_exists('status', $result) && $result['status'] === false) {
            return [];
        }

        $responses = array_values((array)$result['data']);
        $hasItemStatuses = false;
        foreach ($responses as $response) {
            if (is_array($response) && array_key_exists('status', $response)) {
                $hasItemStatuses = true;
                break;
            }
        }

        $successful = [];
        for ($index = 0; $index < $itemCount; $index++) {
            if (!$hasItemStatuses) {
                $successful[] = $index;
                continue;
            }

            if (isset($responses[$index])
                && is_array($responses[$index])
                && (!array_key_exists('status', $responses[$index]) || $responses[$index]['status'] !== false)
            ) {
                $successful[] = $index;
            }
        }

        return $successful;
    }

    private function build_payload(WC_Product $product): array
    {
        $id = trim($product->get_parent_id() . '-' . $product->get_id());
        $field = 'sku';
        if (!$id) {
            $id = trim((string)get_post_meta($product->get_id(), '_snapp_shop_product_id', true));
            $field = 'id';
        }

        $stock = (int)$product->get_stock_quantity();
        $regular_price = (int)$product->get_regular_price();
        $sale_price = (int)$product->get_sale_price();

        if (!$id || $regular_price === 0 || $stock === 0) {
            return [];
        }

        $payload = [
            $field  => $id,
            'stock' => max(0, $stock),
            'price' => max(0, $regular_price),
        ];

        if ($sale_price > 0 && $sale_price < $regular_price) {
            $payload['special_price'] = $sale_price;
            $payload['special_price_stock'] = $stock;
            $date = new DateTime('today');
            $payload['special_price_start_at'] = $date->format('Y-m-d');
            $payload['special_price_end_at'] = $date->modify('+180 Days')->format('Y-m-d');
        }
        return $payload;
    }

    /**
     * Immediately sync one WooCommerce product or variation to SnappShop.
     *
     * ## OPTIONS
     *
     * <product-id>
     * : WooCommerce product or variation ID.
     *
     * ## EXAMPLES
     *
     *     wp snappshop sync-product 123
     */
    public function cli_sync_product(array $args, array $assocArgs): void
    {
        $productId = absint($args[0] ?? 0);
        if (!$productId) {
            WP_CLI::error('A valid WooCommerce product ID is required.');
        }

        $result = $this->sync_product($productId);
        if (!$result['success']) {
            WP_CLI::error($result['message']);
        }

        WP_CLI::success($result['message']);
    }

    /** Send one product directly, without adding it to or draining the sync queue. */
    public function sync_product(int $productId): array
    {
        if (!class_exists('WooCommerce')) {
            return ['success' => false, 'message' => 'WooCommerce is required.'];
        }

        $settings = $this->settings->get();
        if (!$settings['token'] || !$settings['vendor_id'] || !$settings['user_agent']) {
            return ['success' => false, 'message' => 'Please configure token, vendor ID, and user-agent first.'];
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : false;
        if (!$product) {
            return ['success' => false, 'message' => sprintf('Product %d was not found.', $productId)];
        }

        $payload = $this->build_payload($product);
        if (!$payload) {
            return ['success' => false, 'message' => sprintf('Product %d needs a SKU or SnappShop product ID, regular price, and managed stock quantity.', $productId)];
        }

        $result = $this->api->patch(
            '/vendors/' . rawurlencode($settings['vendor_id']) . '/products',
            ['products' => [$payload]]
        );
        echo "\n" . sprintf('Syncing product %d to SnappShop...', $productId) . "\n";
        if (is_wp_error($result) || !is_array($result['data'] ?? null) || !$this->successful_indexes($result, 1)) {
            return ['success' => false, 'message' => is_wp_error($result)
                ? 'SnappShop sync failed: ' . $result->get_error_message()
                : sprintf('SnappShop rejected product %d.', $productId)];
        }

        update_post_meta($productId, self::SIGNATURE_META, hash('sha256', wp_json_encode($payload)));
        return ['success' => true, 'message' => sprintf('Product %d price and stock synced to SnappShop.', $productId)];
    }
}
