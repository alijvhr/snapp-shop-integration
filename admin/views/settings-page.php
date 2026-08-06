<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap snapp-shop-admin">
    <h1>SnappShop Sync</h1>

    <?php if ($message !== '') : ?>
        <div class="notice notice-info is-dismissible"><p><?php echo esc_html($message); ?></p></div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper" aria-label="SnappShop settings">
        <?php foreach (['settings' => 'Settings', 'categories' => 'Category Mapping', 'orders' => 'Orders & API'] as $key => $label) : ?>
            <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(Snapp_Shop_Settings::tab_url($key)); ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="snapp-shop-admin__content">
        <?php if ($tab === 'settings') : ?>
            <h2>Connection settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields(Snapp_Shop_Settings::OPTION); ?>
                <?php do_settings_sections(Snapp_Shop_Settings::OPTION); ?>
                <?php submit_button('Save Settings'); ?>
            </form>
        <?php elseif ($tab === 'categories') : ?>
            <h2>Category mapping</h2>
            <?php Snapp_Shop_Category_Catalogue::render_admin_tab(); ?>
        <?php else : ?>
            <h2>Orders & API</h2>
            <p>Use this tab to manually import new and changed SnappShop orders or connect your catalogue endpoint.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('snapp_shop_sync_orders'); ?>
                <input type="hidden" name="action" value="snapp_shop_sync_orders">
                <?php submit_button('Sync Orders Now', 'secondary'); ?>
            </form>
            <h3>Product catalogue endpoints</h3>
            <p><code><?php echo esc_html(rest_url('snappshop/v1/products?page=1&per_page=100')); ?></code></p>
            <p><code><?php echo esc_html(rest_url('snappshop/v1/products/{product_code}')); ?></code></p>
        <?php endif; ?>
    </div>
</div>
