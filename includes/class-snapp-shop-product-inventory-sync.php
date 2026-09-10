<?php
/** Queues and sends WooCommerce price, sale-price and stock changes to SnappShop. */
if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Product_Inventory_Sync
{
    private const SIGNATURE_META = '_snapp_shop_product_sync_signature';
    private const LAST_SYNC_META = '_snapp_shop_product_last_sync_at';
    /** Retained only to migrate queues created before the dedicated table existed. */
    private const LEGACY_QUEUE_OPTION = 'snapp_shop_product_inventory_sync_queue';
    private const QUEUE_TABLE_VERSION_OPTION = 'snapp_shop_product_inventory_sync_queue_table_version';
    private const QUEUE_TABLE_VERSION = '2';
    private const LAST_MODIFIED_OPTION = 'snapp_shop_product_inventory_sync_last_modified';
    private const INITIAL_SYNC_OPTION = 'snapp_shop_product_inventory_sync_initialized';
    private const LOCK_TRANSIENT = 'snapp_shop_product_inventory_sync_lock';
    private const CRON_HOOK = 'snapp_shop_product_inventory_sync_cron';
    private const MAX_PRODUCTS_PER_REQUEST = 50;
    private const REQUEST_DELAY_MICROSECONDS = 12e5;

    public function __construct(private Snapp_Shop_Settings $settings, private Snapp_Shop_Api_Client $api)
    {
        add_action('save_post_product', [$this, 'queue_saved_product'], 20, 3);
        add_action('save_post_product_variation', [$this, 'queue_saved_product'], 20, 3);
        add_action('woocommerce_product_set_stock', [$this, 'queue_product'], 20);
        add_action('woocommerce_variation_set_stock', [$this, 'queue_product'], 20);
        add_action('woocommerce_variation_options_inventory', [$this, 'render_last_sync_field'], 10, 3);
        add_action('snapp_shop_category_mappings_changed', [$this, 'reinitialize_queue']);
        add_action(self::CRON_HOOK, [$this, 'sync_products']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('snappshop sync-product', [$this, 'cli_sync_product']);
            WP_CLI::add_command('snappshop sync-queue', [$this, 'cli_sync_queue']);
        }

        // Also schedule the event for installations upgraded without reactivation.
        self::activate();
    }

    public static function activate(): void
    {
        self::install_queue_table();

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

    /** Create the durable, de-duplicated queue and migrate any legacy option value. */
    private static function install_queue_table(): void
    {
        global $wpdb;

        $table = self::queue_table();
        if (get_option(self::QUEUE_TABLE_VERSION_OPTION) !== self::QUEUE_TABLE_VERSION
            || !self::queue_table_exists($table)
        ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charsetCollate = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE $table (
                product_id bigint(20) unsigned NOT NULL,
                queued_at datetime NOT NULL,
                PRIMARY KEY  (product_id),
                KEY queued_at_product_id (queued_at, product_id)
            ) $charsetCollate;");
        }

        if (!self::queue_table_exists($table)) {
            // Do not discard the legacy queue or mark the schema installed if creation failed.
            error_log('SnappShop inventory queue table could not be created.');
            delete_option(self::QUEUE_TABLE_VERSION_OPTION);
            return;
        }

        update_option(self::QUEUE_TABLE_VERSION_OPTION, self::QUEUE_TABLE_VERSION, false);

        $legacyQueue = get_option(self::LEGACY_QUEUE_OPTION, null);
        if (is_array($legacyQueue)) {
            self::insert_product_ids($legacyQueue);
            delete_option(self::LEGACY_QUEUE_OPTION);
        }
    }

    private static function queue_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'snapp_shop_inventory_sync_queue';
    }

    private static function queue_table_exists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($table)
        )) === $table;
    }

    private static function insert_product_ids(array $productIds): void
    {
        global $wpdb;

        $productIds = array_values(array_unique(array_filter(array_map('absint', $productIds))));
        foreach (array_chunk($productIds, 500) as $ids) {
            $values = [];
            $arguments = [];
            foreach ($ids as $productId) {
                $values[] = '(%d, %s)';
                $arguments[] = $productId;
                $arguments[] = current_time('mysql', true);
            }

            $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . self::queue_table() . ' (product_id, queued_at) VALUES ' . implode(', ', $values),
                ...$arguments
            ));
        }
    }

    private function enqueue_product_ids(array $productIds): void
    {
        self::insert_product_ids($productIds);
    }

    private function remove_product_ids(array $productIds): void
    {
        global $wpdb;

        $productIds = array_values(array_unique(array_filter(array_map('absint', $productIds))));
        foreach (array_chunk($productIds, 500) as $ids) {
            $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::queue_table() . " WHERE product_id IN ($placeholders)",
                ...$ids
            ));
        }
    }

    private function clear_queue(): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM ' . self::queue_table());
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

        if (!$this->is_in_crawler_categories($productId)) {
            return;
        }

        $this->enqueue_product_ids([$productId]);
    }

    /** Rebuild the queue whenever the crawler category mappings change. */
    public function reinitialize_queue(): void
    {
        $this->clear_queue();
        $this->enqueue_product_ids($this->get_all_product_ids());
    }

    /** A product is queueable only when it belongs to a category selected for the crawler. */
    private function is_in_crawler_categories(int $productId): bool
    {
        $mappedCategoryIds = Snapp_Shop_Category_Catalogue::mapped_wp_category_ids();
        if (!$mappedCategoryIds) {
            return false;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : false;
        if (!$product || !$product->is_type('variation')) {
            return false;
        }

        $categoryProductId = $product->get_parent_id() ?: $product->get_id();
        $productCategoryIds = wp_get_post_terms($categoryProductId, 'product_cat', ['fields' => 'ids']);
        return !is_wp_error($productCategoryIds) && (bool)array_intersect($mappedCategoryIds, array_map('absint', $productCategoryIds));
    }

    /**
     * Load one eligible queue page directly from the database.
     *
     * The cursor prevents a failed item from being selected again in the same
     * run while leaving it in the queue for the next cron invocation.
     */
    private function get_queue_batch(int $limit, ?array $cursor = null): array
    {
        global $wpdb;

        $mappedCategoryIds = Snapp_Shop_Category_Catalogue::mapped_wp_category_ids();
        if (!$mappedCategoryIds) {
            return [];
        }

        $cursorSql = '';
        $arguments = [];
        if ($cursor !== null) {
            $cursorSql = ' AND (queue.queued_at > %s OR (queue.queued_at = %s AND queue.product_id > %d))';
            $arguments[] = $cursor['queued_at'];
            $arguments[] = $cursor['queued_at'];
            $arguments[] = $cursor['product_id'];
        }
        $arguments = array_merge($arguments, $mappedCategoryIds);
        $arguments[] = max(1, $limit);

        $query = $wpdb->prepare(
            'SELECT queue.product_id, queue.queued_at
             FROM ' . self::queue_table() . ' AS queue FORCE INDEX (queued_at_product_id)
             INNER JOIN ' . $wpdb->posts . ' AS variation
                 ON variation.ID = queue.product_id
                 AND variation.post_type = \'product_variation\'
             WHERE 1 = 1' . $cursorSql . '
             AND EXISTS (
                 SELECT 1
                 FROM ' . $wpdb->term_relationships . ' AS category_rel
                 INNER JOIN ' . $wpdb->term_taxonomy . ' AS category_tax
                     ON category_tax.term_taxonomy_id = category_rel.term_taxonomy_id
                     AND category_tax.taxonomy = \'product_cat\'
                 WHERE category_rel.object_id = variation.post_parent
                 AND category_tax.term_id IN (' . implode(', ', array_fill(0, count($mappedCategoryIds), '%d')) . ')
             )
             ORDER BY queue.queued_at ASC, queue.product_id ASC
             LIMIT %d',
            ...$arguments
        );

        return (array)$wpdb->get_results($query, ARRAY_A);
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

        $this->mark_product_synced($productId, hash('sha256', wp_json_encode($payload)));
        return ['success' => true, 'message' => sprintf('Product %d price and stock synced to SnappShop.', $productId)];
    }

    /** Show the last successful SnappShop sync in the variation editor. */
    public function render_last_sync_field($loop, $variationData, $variation): void
    {
        $variationId = is_object($variation) && method_exists($variation, 'get_id')
            ? (int)$variation->get_id()
            : (int)($variation->ID ?? 0);
        $lastSyncAt = (string)get_post_meta($variationId, self::LAST_SYNC_META, true);
        $value = $lastSyncAt ? $this->format_last_sync($lastSyncAt) : __('Never', 'snapp-shop-order-sync');

        woocommerce_wp_text_input([
            'id'                => 'snapp_shop_last_sync_' . (int)$loop,
            'label'             => __('SnappShop last sync', 'snapp-shop-order-sync'),
            'value'             => $value,
            'wrapper_class'     => 'form-row form-row-full',
            'custom_attributes' => ['readonly' => 'readonly'],
            'desc_tip'          => true,
            'description'       => __('The last time this variation was successfully synchronized with SnappShop.', 'snapp-shop-order-sync'),
        ]);
    }

    private function mark_product_synced(int $productId, string $signature): void
    {
        update_post_meta($productId, self::SIGNATURE_META, $signature);
        update_post_meta($productId, self::LAST_SYNC_META, current_time('mysql', true));
    }

    private function format_last_sync(string $lastSyncAt): string
    {
        $timestamp = strtotime($lastSyncAt . ' UTC');
        if (!$timestamp) {
            return $lastSyncAt;
        }

        return wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            $timestamp
        );
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

        if (!$id) {
            return [];
        }

        if($product->get_status() !== 'publish') {
            $stock = 0;
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
            ) {
                $successful[] = $index;
            }
        }

        return $successful;
    }

    /**
     * Sync queued products in a limited number of API batches.
     *
     * ## OPTIONS
     *
     * <batch-count>
     * : Number of API batches to process. Each batch contains up to 50 products.
     *
     * ## EXAMPLES
     *
     *     wp snappshop sync-queue 2
     */
    public function cli_sync_queue(array $args, array $assocArgs): void
    {
        $batchCount = absint($args[0] ?? $assocArgs['batch-count'] ?? 0);
        if (!$batchCount) {
            WP_CLI::error('A positive batch count is required.');
        }

        $result = $this->sync_products($batchCount, static function ($apiResponse): void {
            if (is_wp_error($apiResponse)) {
                $apiResponse = [
                    'error'   => $apiResponse->get_error_code(),
                    'message' => $apiResponse->get_error_message(),
                    'data'    => $apiResponse->get_error_data(),
                ];
            }

            WP_CLI::log(wp_json_encode($apiResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        });
        if (($result['failed'] ?? 0) > 0) {
            WP_CLI::warning($result['message']);
            return;
        }

        WP_CLI::success($result['message']);
    }

    /**
     * Discover changed products, then send queued products in API-sized batches.
     * A failed batch remains queued and will be retried by the next cron run.
     */
    public function sync_products(int $batchCount = 100, ?callable $apiResponseOutput = null): array
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
            $batchCount = max(1, $batchCount);
            $this->queue_modified_products();

            // The first cron run requires a full eligible-product scan.
            if ($initialSync) {
                $this->enqueue_product_ids($this->get_all_product_ids());
                $this->initialize_last_modified_cursor();
                update_option(self::INITIAL_SYNC_OPTION, 1, false);
            } elseif (!get_option(self::INITIAL_SYNC_OPTION, false)) {
                // Recognize installations that already completed the old initial sync.
                update_option(self::INITIAL_SYNC_OPTION, 1, false);
            }

            $updated = 0;
            $failed = 0;
            $reqNo = 0;
            $cursor = null;
            $hasQueuedProducts = false;

            while ($reqNo < $batchCount) {
                $queueRows = $this->get_queue_batch(self::MAX_PRODUCTS_PER_REQUEST, $cursor);
                if (!$queueRows) {
                    break;
                }

                $hasQueuedProducts = true;
                $lastQueueRow = $queueRows[count($queueRows) - 1];
                $cursor = [
                    'queued_at'  => (string)$lastQueueRow['queued_at'],
                    'product_id' => (int)$lastQueueRow['product_id'],
                ];
                $productIds = array_map('absint', array_column($queueRows, 'product_id'));
                $batch = $this->build_batch($productIds);

                // Products without a usable SKU/ID, price, or stock cannot be sent.
                $invalidIds = array_values(array_diff($productIds, $batch['ids']));
                if ($invalidIds) {
                    $this->remove_product_ids($invalidIds);
                }

                if (!$batch['items']) {
                    continue;
                }

                if ($reqNo) {
                    usleep(self::REQUEST_DELAY_MICROSECONDS);
                }
                $reqNo++;

                $result = $this->api->patch(
                    '/vendors/' . rawurlencode($settings['vendor_id']) . '/products',
                    ['products' => array_column($batch['items'], 'payload')]
                );

                if ($apiResponseOutput !== null) {
                    $apiResponseOutput($result);
                }

                if (is_wp_error($result) || !is_array($result['data'] ?? null)) {
                    error_log('SnappShop product inventory sync failed: ' . (is_wp_error($result) ? $result->get_error_message() : 'Invalid API response.'));
                    $failed += count($batch['items']);
                    continue;
                }

                $successfulIndexes = $this->successful_indexes($result, count($batch['items']));
                $successfulIds = [];
                foreach ($successfulIndexes as $index) {
                    $item = $batch['items'][$index];
                    $this->mark_product_synced($item['product_id'], $item['signature']);
                    $successfulIds[] = $item['product_id'];
                    $updated++;
                }
                $this->remove_product_ids($successfulIds);

                $failed += count($batch['items']) - count($successfulIndexes);
            }

            if (!$hasQueuedProducts) {
                return ['updated' => 0, 'failed' => 0, 'message' => 'No products to sync.'];
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
        $mappedCategoryIds = Snapp_Shop_Category_Catalogue::mapped_wp_category_ids();
        if (!$mappedCategoryIds) {
            return [];
        }
        $query = $wpdb->prepare(
            "SELECT DISTINCT variation.ID, variation.post_modified_gmt
             FROM {$wpdb->posts} AS variation
             INNER JOIN {$wpdb->term_relationships} AS category_rel
                 ON category_rel.object_id = variation.post_parent
             INNER JOIN {$wpdb->term_taxonomy} AS category_tax
                 ON category_tax.term_taxonomy_id = category_rel.term_taxonomy_id
                 AND category_tax.taxonomy = 'product_cat'
             WHERE variation.post_type = 'product_variation'
             AND category_tax.term_id IN (" . implode(', ', array_fill(0, count($mappedCategoryIds), '%d')) . ")
             AND variation.post_modified_gmt > %s
             ORDER BY variation.post_modified_gmt DESC, variation.ID DESC",
            ...array_merge($mappedCategoryIds, [$lastModified])
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

        $this->enqueue_product_ids($ids);
        return $ids;
    }

    private function get_all_product_ids(): array
    {
        global $wpdb;

        $mappedCategoryIds = Snapp_Shop_Category_Catalogue::mapped_wp_category_ids();
        if (!$mappedCategoryIds) {
            return [];
        }

        $query = $wpdb->prepare(
            "SELECT DISTINCT variation.ID
             FROM {$wpdb->posts} AS variation
             INNER JOIN {$wpdb->term_relationships} AS category_rel
                 ON category_rel.object_id = variation.post_parent
             INNER JOIN {$wpdb->term_taxonomy} AS category_tax
                 ON category_tax.term_taxonomy_id = category_rel.term_taxonomy_id
                 AND category_tax.taxonomy = 'product_cat'
             WHERE variation.post_type = 'product_variation'
             AND category_tax.term_id IN (" . implode(', ', array_fill(0, count($mappedCategoryIds), '%d')) . ")
             ORDER BY variation.ID ASC",
            ...$mappedCategoryIds
        );

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
             WHERE post_type  = 'product_variation'"
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
}
