<?php
/** Public catalogue endpoint restricted to explicitly mapped WooCommerce categories. */
if (!defined('ABSPATH')) {
    exit;
}

final class Snapp_Shop_Crawler
{
    private const NAMESPACE = 'snappshop/v1';
    private const MAX_PER_PAGE = 100;

    public function __construct() { add_action('rest_api_init', [$this, 'routes']); }

    public function routes(): void
    {
        register_rest_route(self::NAMESPACE, '/products', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'products'], 'permission_callback' => '__return_true', 'args' => ['page' => ['default' => 1, 'sanitize_callback' => 'absint'], 'per_page' => ['default' => self::MAX_PER_PAGE, 'sanitize_callback' => 'absint']]]);
        register_rest_route(self::NAMESPACE, '/products/(?P<product_code>\d+)', ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'product'], 'permission_callback' => '__return_true']);
    }

    public function products(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, absint($request['page']));
        $per = min(self::MAX_PER_PAGE, max(5, absint($request['per_page'])));
        $result = $this->eligible_products($page, $per);

        return new WP_REST_Response([
            'status' => true,
            'data'   => [
                'products'     => array_map(static function (object $product): array {
                    return [
                        'id'     => (int)$product->id,
                        'code'   => (int)$product->id,
                        'active' => (bool)$product->active,
                        'url'    => (string)get_permalink((int)$product->id),
                    ];
                }, $result['items']),
                'current_page' => $page,
                'per_page'     => $per,
                'total_items'  => $result['total'],
                'total_pages'  => (int)ceil($result['total'] / $per),
            ],
        ]);
    }

    private function eligible_products(int $page, int $per): array
    {
        global $wpdb;

        $categoryDates = [];
        foreach (Snapp_Shop_Category_Catalogue::get_mappings() as $mapping) {
            $categoryId = absint($mapping['wp_category_id'] ?? 0);
            if (!$categoryId) continue;
            $categoryDates[$categoryId] = max($categoryDates[$categoryId] ?? 0, (int)($mapping['included_at'] ?? 0));
        }
        if (!$categoryDates) return ['items' => [], 'total' => 0];

        $settings = get_option('snapp_shop_order_sync_settings', []);
        $excludedBrands = array_values(array_filter(array_map(
            'sanitize_title',
            preg_split('/[\r\n,]+/', (string)($settings['excluded_brands'] ?? ''))
        )));

        [$eligibleSql, $eligibleParams] = $this->eligible_products_sql($categoryDates, $excludedBrands);
        $query = "
            SELECT result.id, result.active, result.total_items, result.is_total, result.inclusion_date
            FROM (
                SELECT page.id, page.active, page.inclusion_date, 0 AS total_items, 0 AS is_total
                FROM (
                    SELECT eligible.id, eligible.active, eligible.inclusion_date
                    FROM ({$eligibleSql}) AS eligible
                    ORDER BY eligible.inclusion_date DESC, eligible.id ASC
                    LIMIT %d OFFSET %d
                ) AS page
                UNION ALL
                SELECT NULL AS id, 0 AS active, NULL AS inclusion_date, COUNT(*) AS total_items, 1 AS is_total
                FROM ({$eligibleSql}) AS countable
            ) AS result
            ORDER BY result.is_total ASC, result.inclusion_date DESC, result.id ASC";
        $params = array_merge($eligibleParams, [$per, ($page - 1) * $per], $eligibleParams);
        $rows = $wpdb->get_results($wpdb->prepare($query, ...$params));

        $items = [];
        $total = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if ((int)$row->is_total === 1) {
                $total = (int)$row->total_items;
                continue;
            }
            $items[] = $row;
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * Build the complete list query. It deliberately returns scalar post rows,
     * not WC_Product objects, and is embedded twice so pagination and the total
     * are returned by one database statement.
     */
    private function eligible_products_sql(array $categoryDates, array $excludedBrands): array
    {
        global $wpdb;

        $categoryIds = array_keys($categoryDates);
        $categoryPlaceholders = implode(', ', array_fill(0, count($categoryIds), '%d'));
        $inclusionCase = 'CASE mapped_tax.term_id';
        $inclusionParams = [];
        foreach ($categoryDates as $categoryId => $includedAt) {
            $inclusionCase .= ' WHEN %d THEN %d';
            $inclusionParams[] = $categoryId;
            $inclusionParams[] = $includedAt;
        }
        $inclusionCase .= ' ELSE 0 END';

        $brandTaxonomies = ['product_brand', 'pwb-brand', 'pa_brand', 'brand'];
        $brandJoin = '';
        $brandParams = [];
        $excludedSql = '';
        $excludedParams = [];
        if ($excludedBrands) {
            $brandTaxonomyPlaceholders = implode(', ', array_fill(0, count($brandTaxonomies), '%s'));
            $brandJoin = "
                LEFT JOIN (
                    SELECT brand_rel.object_id,
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(
                                brand_term.slug
                                ORDER BY FIELD(brand_tax.taxonomy, {$brandTaxonomyPlaceholders}), brand_term.name, brand_term.term_id
                                SEPARATOR ','
                            ), ',', 1
                        ) AS brand_slug
                    FROM {$wpdb->term_relationships} AS brand_rel
                    INNER JOIN {$wpdb->term_taxonomy} AS brand_tax
                        ON brand_tax.term_taxonomy_id = brand_rel.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} AS brand_term
                        ON brand_term.term_id = brand_tax.term_id
                    WHERE brand_tax.taxonomy IN ({$brandTaxonomyPlaceholders})
                    GROUP BY brand_rel.object_id
                ) AS brands ON brands.object_id = p.ID";
            $brandParams = array_merge($brandTaxonomies, $brandTaxonomies);
            $excludedSql = ' AND (brands.brand_slug IS NULL OR brands.brand_slug NOT IN (' . implode(', ', array_fill(0, count($excludedBrands), '%s')) . '))';
            $excludedParams = $excludedBrands;
        }

        $sql = "
            SELECT
                p.ID AS id,
                MAX({$inclusionCase}) AS inclusion_date,
                MAX(CASE WHEN COALESCE(visibility.meta_value, 'visible') = 'hidden' THEN 0 ELSE 1 END) AS active
            FROM {$wpdb->posts} AS p
            {$brandJoin}
            INNER JOIN {$wpdb->term_relationships} AS mapped_rel
                ON mapped_rel.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} AS mapped_tax
                ON mapped_tax.term_taxonomy_id = mapped_rel.term_taxonomy_id
                AND mapped_tax.taxonomy = 'product_cat'
            LEFT JOIN {$wpdb->postmeta} AS visibility
                ON visibility.post_id = p.ID
                AND visibility.meta_key = '_catalog_visibility'
            WHERE p.post_type = 'product'
                AND p.post_status = 'publish'
                AND mapped_tax.term_id IN ({$categoryPlaceholders})
                {$excludedSql}
            GROUP BY p.ID
        ";

        // Placeholder order follows the SQL: SELECT CASE, brand JOIN, category IN, brand exclusion.
        return [$sql, array_merge($inclusionParams, $brandParams, $categoryIds, $excludedParams)];
    }

    public function product(WP_REST_Request $request)
    {
        $product = function_exists('wc_get_product') ? wc_get_product(absint($request['product_code'])) : false;
        if (!$product || $product->get_status() !== 'publish' || !$this->is_eligible($product)) return new WP_Error('snappshop_product_not_found', 'Product not found.', ['status' => 404]);
        return new WP_REST_Response(['status' => true, 'data' => $this->details($product)]);
    }

    private function is_eligible(WC_Product $product): bool
    {
        $mapped = Snapp_Shop_Category_Catalogue::mapped_wp_category_ids();
        $productCategories = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']);
        return (bool)array_intersect($mapped, $productCategories) && !in_array(sanitize_title($this->brand($product)), $this->excluded_brands(), true);
    }

    private function details(WC_Product $product): array
    {
        $ids = $product->get_gallery_image_ids();
        array_unshift($ids, $product->get_image_id());
        $variations = [];
        if ($product->is_type('variable')) foreach ($product->get_children() as $id) {
            $variation = wc_get_product($id);
            if (!$variation instanceof WC_Product_Variation) continue;
            $image = $variation->get_image_id();
            if ($image) $ids[] = $image;
            $variations[] = ['id' => $variation->get_id(), 'active' => $this->active($variation), 'specs' => $this->variation_specs($variation), 'medias' => $image ? ['image-' . $image] : []];
        }
        $medias = [];
        foreach (array_unique(array_filter(array_map('absint', $ids))) as $id) {
            $url = wp_get_attachment_image_url($id, 'full');
            if ($url) $medias[] = ['code' => 'image-' . $id, 'type' => 'image', 'url' => $url];
        }
        $image = $product->get_image_id();
        return ['id' => $product->get_id(), 'code' => $product->get_id(), 'title' => wp_strip_all_tags($product->get_name()), 'description' => wp_strip_all_tags($product->get_description() ?: $product->get_short_description()), 'specs' => $this->specs($product), 'category' => $this->category($product), 'brand' => $this->brand($product), 'active' => $this->active($product), 'is_fake' => false, 'variations' => $variations, 'medias' => $medias, 'cover_image' => $image ? 'image-' . $image : '', 'url' => get_permalink($product->get_id())];
    }

    private function brand(WC_Product $product): string
    {
        foreach (['product_brand', 'pwb-brand', 'pa_brand', 'brand'] as $taxonomy) if (taxonomy_exists($taxonomy)) {
            $brands = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'names']);
            if ($brands) return (string)$brands[0];
        }
        return '';
    }

    private function excluded_brands(): array
    {
        $settings = get_option('snapp_shop_order_sync_settings', []);
        return array_values(array_filter(array_map('sanitize_title', preg_split('/[\r\n,]+/', (string)($settings['excluded_brands'] ?? '')))));
    }

    private function active(WC_Product $product): bool { return $product->get_status() === 'publish' && $product->get_catalog_visibility() !== 'hidden'; }

    private function variation_specs(WC_Product_Variation $variation): array
    {
        $specs = [];
        foreach ($variation->get_attributes() as $name => $value) {
            if ($value === '') continue;
            $taxonomy = str_replace('attribute_', '', $name);
            $term = taxonomy_exists($taxonomy) ? get_term_by('slug', $value, $taxonomy) : false;
            $specs[] = ['name' => wc_attribute_label($taxonomy), 'value' => $term ? $term->name : (string)$value];
        }
        return $specs;
    }

    private function specs(WC_Product $product): array
    {
        $specs = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute) continue;
            $values = $attribute->is_taxonomy() ? wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names']) : $attribute->get_options();
            $values = array_values(array_filter(array_map('strval', $values), 'strlen'));
            if ($values) $specs[] = ['name' => wc_attribute_label($attribute->get_name()), 'value' => $values];
        }
        return $specs;
    }

    private function category(WC_Product $product): array
    {
        $terms = wp_get_post_terms($product->get_id(), 'product_cat');
        usort($terms, static fn($a, $b) => $b->parent <=> $a->parent);
        return $terms ? ['code' => $terms[0]->slug, 'name' => $terms[0]->name] : ['code' => '', 'name' => ''];
    }

    private function inclusion_date(WC_Product $product): int
    {
        $dates = array_map(static fn($id) => Snapp_Shop_Category_Catalogue::inclusion_for_wp_category((int)$id), wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']));
        return empty($dates) ? 0 : max($dates);
    }
}
