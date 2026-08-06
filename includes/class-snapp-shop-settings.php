<?php
/** Plugin configuration and the WooCommerce administration screen. */

if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Settings
{
    public const OPTION = 'snapp_shop_order_sync_settings';
    public const PAGE = 'snapp-shop-order-sync';

    private const TABS = [
        'settings' => 'Settings',
        'categories' => 'Category Mapping',
        'orders' => 'Orders & API',
    ];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function get(): array
    {
        $value = get_option(self::OPTION, []);

        return array_merge([
            'token' => '',
            'vendor_id' => '',
            'user_agent' => '',
            'category_endpoint' => '/catalog/categories',
            'excluded_brands' => '',
        ], is_array($value) ? $value : []);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            'SnappShop Sync',
            'SnappShop Sync',
            'manage_woocommerce',
            self::PAGE,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => [],
        ]);

        add_settings_section('snapp_shop_main', 'SnappShop credentials', '__return_false', self::OPTION);

        foreach ([
            'token' => 'Token',
            'vendor_id' => 'Vendor ID',
            'user_agent' => 'User-Agent (Unique Code)',
            'category_endpoint' => 'Category endpoint (supports {vendor_id})',
            'excluded_brands' => 'Excluded brands (comma-separated)',
        ] as $key => $label) {
            add_settings_field($key, $label, [$this, 'render_field'], self::OPTION, 'snapp_shop_main', ['key' => $key]);
        }
    }

    public function enqueue_assets(string $hook): void
    {
        if ('woocommerce_page_' . self::PAGE !== $hook) {
            return;
        }

        wp_enqueue_style('snapp-shop-admin', SNAPP_SHOP_PLUGIN_URL . 'assets/css/admin.css', [], '1.3.0');
        wp_enqueue_script('snapp-shop-admin', SNAPP_SHOP_PLUGIN_URL . 'assets/js/admin.js', [], '1.3.0', true);
    }

    public function sanitize(array $input): array
    {
        return [
            'token' => sanitize_text_field($input['token'] ?? ''),
            'vendor_id' => sanitize_text_field($input['vendor_id'] ?? ''),
            'user_agent' => sanitize_text_field($input['user_agent'] ?? ''),
            'category_endpoint' => esc_url_raw($input['category_endpoint'] ?? ''),
            'excluded_brands' => sanitize_textarea_field($input['excluded_brands'] ?? ''),
        ];
    }

    public function render_field(array $args): void
    {
        $key = $args['key'];
        $settings = $this->get();
        $type = $key === 'token' ? 'password' : ($key === 'excluded_brands' ? 'textarea' : 'text');

        if ($type === 'textarea') {
            printf('<textarea class="large-text" rows="3" name="%s[%s]">%s</textarea>', esc_attr(self::OPTION), esc_attr($key), esc_textarea($settings[$key]));
            return;
        }

        printf('<input type="%s" class="regular-text" autocomplete="off" name="%s[%s]" value="%s" />', $type, esc_attr(self::OPTION), esc_attr($key), esc_attr($settings[$key]));
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have permission to manage WooCommerce settings.');
        }

        $tab = sanitize_key($_GET['tab'] ?? 'settings');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'settings';
        $message = sanitize_text_field(wp_unslash($_GET['snapp_sync'] ?? $_GET['snapp_category_sync'] ?? ''));

        require SNAPP_SHOP_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public static function tab_url(string $tab): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => $tab], admin_url('admin.php'));
    }
}
