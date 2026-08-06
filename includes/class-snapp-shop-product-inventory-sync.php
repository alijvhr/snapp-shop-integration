<?php
/** Sends WooCommerce price, sale-price and stock changes to SnappShop. */
if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Product_Inventory_Sync
{
    private const SIGNATURE_META = '_snapp_shop_product_sync_signature';

    public function __construct(private Snapp_Shop_Settings $settings, private Snapp_Shop_Api_Client $api)
    {
        add_action('save_post_product', [$this, 'sync_saved_product'], 20, 3);
        add_action('save_post_product_variation', [$this, 'sync_saved_product'], 20, 3);
    }

    public function sync_saved_product($postId, $post, $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
        $product = function_exists('wc_get_product') ? wc_get_product($postId) : false;
        if ($product) $this->sync($product);
    }

    private function sync(WC_Product $product): bool
    {
        $settings = $this->settings->get();
        if (!$settings['token'] || !$settings['vendor_id'] || !$settings['user_agent']) return false;
        $id = trim((string)$product->get_sku());
        $field = 'sku';
        if (!$id) {
            $id = trim((string)get_post_meta($product->get_id(), '_snapp_shop_product_id', true));
            $field = 'id';
        }
        if (!$id || $product->get_regular_price() === '' || $product->get_stock_quantity() === null) return false;
        $payload = [$field => $id, 'stock' => max(0, (int)$product->get_stock_quantity()), 'price' => max(0, (int)$product->get_regular_price())];
        if ($product->get_sale_price() !== '' && (float)$product->get_sale_price() > 0 && (float)$product->get_sale_price() < (float)$product->get_regular_price()) {
            $payload['special_price'] = (int)$product->get_sale_price();
            $payload['special_price_stock'] = $payload['stock'];
            if ($from = $product->get_date_on_sale_from()) $payload['special_price_start_at'] = $from->date('Y-m-d');
            if ($to = $product->get_date_on_sale_to()) $payload['special_price_end_at'] = $to->date('Y-m-d');
        }
        $signature = hash('sha256', wp_json_encode($payload));
        if (hash_equals((string)get_post_meta($product->get_id(), self::SIGNATURE_META, true), $signature)) return true;
        $result = $this->api->patch('/vendors/' . rawurlencode($settings['vendor_id']) . '/products', ['products' => [$payload]]);
        if (is_wp_error($result) || !is_array($result['data'] ?? null)) return false;
        update_post_meta($product->get_id(), self::SIGNATURE_META, $signature);
        return true;
    }
}
