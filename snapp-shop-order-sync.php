<?php
/**
 * Plugin Name: Snapp Shop Integration
 * Description: SnappShop catalogue, category, order, and product inventory integration for WooCommerce.
 * Version: 1.1.0
 * Author: Snapp Shop Integration
 * Text Domain: snapp-shop-order-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SNAPP_SHOP_PLUGIN_FILE', __FILE__);
define('SNAPP_SHOP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNAPP_SHOP_PLUGIN_URL', plugin_dir_url(__FILE__));

foreach ([
    'class-snapp-shop-settings.php',
    'class-snapp-shop-api-client.php',
    'class-snapp-shop-category-catalogue.php',
    'class-snapp-shop-crawler.php',
    'class-snapp-shop-order-importer.php',
    'class-snapp-shop-product-inventory-sync.php',
] as $file) {
    require_once __DIR__ . '/includes/' . $file;
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

$snapp_shop_settings = new Snapp_Shop_Settings();
$snapp_shop_api_client = new Snapp_Shop_Api_Client($snapp_shop_settings);
new Snapp_Shop_Category_Catalogue();
new Snapp_Shop_Crawler();
new Snapp_Shop_Order_Importer($snapp_shop_settings, $snapp_shop_api_client);
new Snapp_Shop_Product_Inventory_Sync($snapp_shop_settings, $snapp_shop_api_client);

register_activation_hook(__FILE__, static function (): void {
    Snapp_Shop_Order_Importer::activate();
    Snapp_Shop_Category_Catalogue::activate();
    Snapp_Shop_Product_Inventory_Sync::activate();
});
register_deactivation_hook(__FILE__, static function (): void {
    Snapp_Shop_Order_Importer::deactivate();
    Snapp_Shop_Category_Catalogue::deactivate();
    Snapp_Shop_Product_Inventory_Sync::deactivate();
});
