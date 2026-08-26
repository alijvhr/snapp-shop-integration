<?php
/** SnappShop catalogue category synchronisation and WooCommerce category mapping. */

if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Category_Catalogue
{
    public const CATEGORIES_OPTION = 'snapp_shop_catalogue_categories';
    public const MAPPINGS_OPTION = 'snapp_shop_catalogue_category_mappings';
    public const CRON_HOOK = 'snapp_shop_catalogue_categories_daily';
    private const SETTINGS_OPTION = 'snapp_shop_order_sync_settings';
    private const API_BASE = 'https://apix.snappshop.ir/automation/v1';
    private static ?self $instance = null;

    public function __construct()
    {
        self::$instance = $this;
        add_action('init', [$this, 'ensure_schedule']);
        add_action('admin_post_snapp_shop_save_category_mappings', [$this, 'save_mappings']);
        add_action('admin_post_snapp_shop_sync_categories', [$this, 'manual_sync']);
        add_action(self::CRON_HOOK, [$this, 'sync_categories']);
    }

    public static function activate(): void
    {
        self::schedule_if_needed();
    }

    private static function schedule_if_needed(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', self::CRON_HOOK);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function render_admin_tab(): void
    {
        if (self::$instance instanceof self) {
            self::$instance->render_tab();
        }
    }

    public function render_tab(): void
    {
        $categories = self::get_categories();
        $leaves = $this->leaf_categories($categories);
        $groups = $this->group_leaf_categories($leaves, $categories);
        $mappings = self::get_mappings();
        $wpCategories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);

        require SNAPP_SHOP_PLUGIN_DIR . 'admin/views/category-mapping.php';
    }

    public static function get_categories(): array
    {
        $categories = get_option(self::CATEGORIES_OPTION, []);
        return is_array($categories) ? $categories : [];
    }

    private function leaf_categories(array $categories): array
    {
        $parents = array_filter(array_column($categories, 'parent_id'));
        return array_values(array_filter($categories, static fn($category) => !in_array((string)$category['id'], $parents, true)));
    }

    private function group_leaf_categories(array $leaves, array $categories): array
    {
        $groups = [];

        foreach ($leaves as $leaf) {
            $ancestors = $this->category_ancestors($leaf, $categories);
            $root = $ancestors[0] ?? $leaf;
            $subgroup = $ancestors[1] ?? null;
            $rootId = (string)$root['id'];
            $subgroupId = $subgroup ? (string)$subgroup['id'] : '';

            if (!isset($groups[$rootId])) {
                $groups[$rootId] = ['category' => $root, 'children' => []];
            }
            if (!isset($groups[$rootId]['children'][$subgroupId])) {
                $groups[$rootId]['children'][$subgroupId] = ['category' => $subgroup, 'leaves' => []];
            }
            $groups[$rootId]['children'][$subgroupId]['leaves'][] = $leaf;
        }

        foreach ($groups as &$group) {
            $group['children'] = array_values($group['children']);
        }
        unset($group);

        return array_values($groups);
    }

    public static function get_mappings(): array
    {
        $mappings = get_option(self::MAPPINGS_OPTION, []);
        return is_array($mappings) ? $mappings : [];
    }

    private function category_ancestors(array $category, array $categories): array
    {
        $byId = [];
        foreach ($categories as $item) $byId[(string)$item['id']] = $item;
        $ancestors = [];
        $parent = (string)$category['parent_id'];
        $seen = [];
        while ($parent !== '' && isset($byId[$parent]) && !isset($seen[$parent])) {
            $seen[$parent] = true;
            array_unshift($ancestors, $byId[$parent]);
            $parent = (string)$byId[$parent]['parent_id'];
        }
        return $ancestors;
    }

    public static function mapped_wp_category_ids(): array { return array_values(array_unique(array_filter(array_map(static fn($m) => absint($m['wp_category_id'] ?? 0), self::get_mappings())))); }

    public static function inclusion_for_wp_category(int $wpCategoryId): int
    {
        $dates = [];
        foreach (self::get_mappings() as $mapping) if ((int)($mapping['wp_category_id'] ?? 0) === $wpCategoryId) $dates[] = (int)($mapping['included_at'] ?? 0);
        return empty($dates) ? 0 : max($dates);
    }

    public function ensure_schedule(): void { self::schedule_if_needed(); }

    public function save_mappings(): void
    {
        $this->guard('snapp_shop_save_category_mappings');
        $old = self::get_mappings();
        $input = isset($_POST['mappings']) && is_array($_POST['mappings']) ? wp_unslash($_POST['mappings']) : [];
        $valid = array_column($this->leaf_categories(self::get_categories()), 'id');
        $new = [];
        foreach ($input as $snappId => $wpId) {
            $snappId = sanitize_text_field((string)$snappId);
            $wpId = absint($wpId);
            if ($wpId && in_array($snappId, array_map('strval', $valid), true) && term_exists($wpId, 'product_cat')) {
                $new[$snappId] = ['wp_category_id' => $wpId, 'included_at' => isset($old[$snappId]['wp_category_id']) && (int)$old[$snappId]['wp_category_id'] === $wpId ? (int)$old[$snappId]['included_at'] : time()];
            }
        }
        update_option(self::MAPPINGS_OPTION, $new, false);
        if ($old !== $new) {
            do_action('snapp_shop_category_mappings_changed');
        }
        $this->redirect('Category mappings saved.');
    }

    private function guard(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) wp_die('You do not have permission to manage WooCommerce settings.');
        check_admin_referer($nonce);
    }

    private function redirect(string $message): void
    {
        wp_safe_redirect(add_query_arg(['page' => Snapp_Shop_Settings::PAGE, 'tab' => 'categories', 'snapp_category_sync' => $message], admin_url('admin.php')));
        exit;
    }

    public function manual_sync(): void
    {
        $this->guard('snapp_shop_sync_categories');
        $result = $this->sync_categories();
        $this->redirect($result['message']);
    }

    public function sync_categories(): array
    {
        $settings = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($settings) || empty($settings['token']) || empty($settings['user_agent'])) return ['message' => 'Configure SnappShop credentials first.'];
        $path = trim((string)($settings['category_endpoint'] ?? '/catalog/categories'));
        $path = str_replace('{vendor_id}', rawurlencode((string)($settings['vendor_id'] ?? '')), $path);
        $url = preg_match('#^https?://#i', $path) ? $path : trailingslashit(self::API_BASE) . ltrim($path, '/');
        $response = wp_remote_get($url, ['timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $settings['token'], 'User-Agent' => $settings['user_agent'], 'Accept' => 'application/json']]);
        if (is_wp_error($response)) return ['message' => 'Category sync failed: ' . $response->get_error_message()];
        $decoded = json_decode((string)wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300 || !is_array($decoded)) return ['message' => 'Category sync failed: invalid API response.'];
        $source = $decoded['data']['categories'] ?? $decoded['data'] ?? $decoded['categories'] ?? $decoded;
        $categories = $this->normalise_categories($source);
        if (empty($categories)) return ['message' => 'Category sync failed: no categories found in the API response.'];
        update_option(self::CATEGORIES_OPTION, $categories, false);
        return ['message' => sprintf('%d SnappShop categories refreshed.', count($categories))];
    }

    private function normalise_categories($source, string $parentId = ''): array
    {
        if (!is_array($source)) return [];
        $result = [];
        foreach ($source as $item) {
            if (!is_array($item)) continue;
            $id = (string)($item['id'] ?? $item['code'] ?? $item['category_id'] ?? '');
            $name = (string)($item['name'] ?? $item['title'] ?? $item['label'] ?? '');
            if ($id === '' || $name === '') continue;
            $result[$id] = ['id' => $id, 'name' => $name, 'parent_id' => (string)($item['parent_id'] ?? $item['parentId'] ?? $parentId)];
            foreach ($this->normalise_categories($item['children'] ?? $item['subcategories'] ?? [], $id) as $child) $result[$child['id']] = $child;
        }
        return array_values($result);
    }
}
