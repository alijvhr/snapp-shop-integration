<?php
if (!defined('ABSPATH')) {
    exit;
}

$message = isset($_GET['snapp_category_sync']) ? sanitize_text_field(wp_unslash($_GET['snapp_category_sync'])) : '';
?>
<div class="snappshop-category-map">
    <?php if ($message !== '') : ?>
        <div class="notice notice-info"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>
    <p>Only final SnappShop categories can be matched. Search includes parent categories, and newer inclusions are sent to SnappShop first.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="snapp-category-refresh">
        <?php wp_nonce_field('snapp_shop_sync_categories'); ?>
        <input type="hidden" name="action" value="snapp_shop_sync_categories">
        <?php submit_button('Refresh SnappShop categories', 'secondary', 'submit', false); ?>
    </form>

    <?php if (empty($leaves)) : ?>
        <div class="notice notice-warning"><p>No categories have been downloaded yet. Configure the category endpoint on the Settings tab, then refresh.</p></div>
    <?php else : ?>
        <div class="snapp-category-toolbar">
            <div class="snapp-category-search">
                <label for="snapp-category-search">Search category name</label>
                <input type="search" id="snapp-category-search" autocomplete="off" placeholder="Type a category or parent name...">
            </div>
            <p class="snapp-category-count" id="snapp-category-count" role="status" aria-live="polite">Showing <?php echo esc_html((string) count($leaves)); ?> categories</p>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('snapp_shop_save_category_mappings'); ?>
            <input type="hidden" name="action" value="snapp_shop_save_category_mappings">
            <table class="widefat striped" id="snapp-category-table">
                <thead><tr><th>SnappShop category</th><th>WooCommerce category</th><th>Included since</th></tr></thead>
                <tbody>
                <?php foreach ($groups as $rootGroup) : $root = $rootGroup['category']; $rootGroupId = 'root-' . md5((string) $root['id']); ?>
                    <?php foreach ($rootGroup['children'] as $subGroup) : $sub = $subGroup['category']; $groupId = $rootGroupId . '-sub-' . md5((string) ($sub['id'] ?? '')); $groupNames = [$root['name']]; if ($sub !== null) $groupNames[] = $sub['name']; ?>
                        <tr class="snapp-category-group" data-category-group="<?php echo esc_attr($groupId); ?>">
                            <td colspan="3"><span class="snapp-category-group__label">Category group</span><strong class="snapp-category-group__name"><?php echo esc_html(implode(' > ', $groupNames)); ?></strong></td>
                        </tr>
                        <?php foreach ($subGroup['leaves'] as $category) : $id = (string) $category['id']; $mapping = $mappings[$id] ?? []; $ancestors = $this->category_ancestors($category, $categories); $searchNames = array_merge(array_column($ancestors, 'name'), [$category['name']]); $leafPath = array_merge(array_slice($ancestors, 2), [$category]); $leafNames = array_column($leafPath, 'name'); ?>
                            <tr class="snapp-category-leaf" data-category-group="<?php echo esc_attr($groupId); ?>" data-category-search="<?php echo esc_attr(implode(' ', $searchNames)); ?>">
                            <td><strong class="snapp-category-leaf__name"><?php echo esc_html(implode(' > ', $leafNames)); ?></strong><code><?php echo esc_html($id); ?></code></td>
                                <td><select name="mappings[<?php echo esc_attr($id); ?>]"><option value="">&mdash; Do not include &mdash;</option><?php foreach ($wpCategories as $wpCategory) : ?><option value="<?php echo esc_attr((string) $wpCategory->term_id); ?>" <?php selected((int) ($mapping['wp_category_id'] ?? 0), $wpCategory->term_id); ?>><?php echo esc_html($wpCategory->name); ?></option><?php endforeach; ?></select></td>
                                <td><?php echo !empty($mapping['included_at']) ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $mapping['included_at'])) : '&mdash;'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <tr class="snapp-category-empty" id="snapp-category-empty"><td colspan="3">No categories match your search.</td></tr>
                </tbody>
            </table>
            <nav class="snapp-category-pagination" id="snapp-category-pagination" aria-label="Category pages"></nav>
            <?php submit_button('Save category mappings'); ?>
        </form>
    <?php endif; ?>
</div>
