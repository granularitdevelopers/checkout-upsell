<?php
// Ensure this file is being included by a parent file
if (!defined('ABSPATH')) exit;

$upsell_data = $this->get_upsell_data();
$filter = isset($_GET['filter']) ? sanitize_key($_GET['filter']) : 'all';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// Filter and Search logic
$filtered_data = array_filter($upsell_data, function($product) use ($filter, $search) {
    $category_match = $filter === 'all' || strtolower($product['category']) === $filter;
    $search_match = empty($search) || stripos($product['name'], $search) !== false;
    return $category_match && $search_match;
});

// Prepare metrics data for charts
$metrics = $this->get_upsell_metrics();
$total_upsell_products = count($filtered_data);
$total_times_shown = array_sum(array_column($metrics, 'total_shown'));
$total_purchases = array_sum(array_column($metrics, 'total_purchased'));
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <!-- Overview Section -->
    <h2><?php esc_html_e('Overview', 'checkout-upsell'); ?></h2>
    <div class="overview-cards">
        <div class="overview-card">
            <span class="dashicons dashicons-cart"></span>
            <div>
                <strong><?php esc_html_e('Total Upsell Products:', 'checkout-upsell'); ?></strong>
                <span><?php echo esc_html($total_upsell_products); ?></span>
            </div>
        </div>
        <div class="overview-card">
            <span class="dashicons dashicons-visibility"></span>
            <div>
                <strong><?php esc_html_e('Total Times Shown:', 'checkout-upsell'); ?></strong>
                <span><?php echo esc_html($total_times_shown); ?></span>
            </div>
        </div>
        <div class="overview-card">
            <span class="dashicons dashicons-yes"></span>
            <div>
                <strong><?php esc_html_e('Total Purchases:', 'checkout-upsell'); ?></strong>
                <span><?php echo esc_html($total_purchases); ?></span>
            </div>
        </div>
    </div>

    <!-- Enable/Disable Form -->
    <form action="options.php" method="post">
        <?php
        settings_fields('checkout_upsell_options');
        do_settings_sections('checkout_upsell_options');
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php _e('Enable Checkout Upsell', 'checkout-upsell'); ?></th>
                <td>
                    <input type="checkbox" name="checkout_upsell_enabled" value="yes" <?php checked('yes', get_option('checkout_upsell_enabled', 'yes')); ?> />
                </td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <!-- Filter and Search -->
    <form method="get" class="search-box">
        <input type="hidden" name="page" value="checkout-upsell">
        <select name="filter">
            <option value="all" <?php selected($filter, 'all'); ?>><?php esc_html_e('All Products', 'checkout-upsell'); ?></option>
            <option value="dog" <?php selected($filter, 'dog'); ?>><?php esc_html_e('Dog Products', 'checkout-upsell'); ?></option>
            <option value="cat" <?php selected($filter, 'cat'); ?>><?php esc_html_e('Cat Products', 'checkout-upsell'); ?></option>
        </select>
        <input type="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search products...', 'checkout-upsell'); ?>">
        <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'checkout-upsell'); ?>">
    </form>

    <!-- Nonces -->
    <?php wp_nonce_field('checkout_upsell_nonce', 'checkout_upsell_nonce'); ?>
    <?php wp_nonce_field('update_upsell_prices', 'upsell_nonce'); ?>
    <?php wp_nonce_field('remove_upsell_product', 'remove_upsell_nonce'); ?>

    <!-- Upsell Products Table -->
    <h2><?php esc_html_e('Upsell Products', 'checkout-upsell'); ?></h2>
    <table class="wp-list-table widefat fixed striped" id="upsell-products-table">
        <thead>
            <tr>
                <th><?php esc_html_e('ID', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Name', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Regular Price', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Upsell Price', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Min Quantity', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Category', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Actions', 'checkout-upsell'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($filtered_data as $product) : ?>
                <tr>
                    <td><?php echo esc_html($product['id']); ?></td>
                    <td><?php echo esc_html($product['name']); ?></td>
                    <td><?php echo wc_price($product['regular_price']); ?></td>
                    <td>
                        <input type="number" step="0.01" name="upsell_price[<?php echo $product['id']; ?>]" 
                            value="<?php echo esc_attr($product['upsell_price']); ?>" class="small-text upsell-price-input"
                            data-product-id="<?php echo $product['id']; ?>">
                        <button type="button" class="button update-upsell-price" data-product-id="<?php echo esc_attr($product['id']); ?>">
                            <?php esc_html_e('Update', 'checkout-upsell'); ?>
                        </button>
                    </td>
                    <td><?php echo esc_html($product['min_quantity']); ?></td>
                    <td>
                        <span class="dashicons <?php echo $product['category'] === 'Dog' ? 'dashicons-pets' : 'dashicons-heart'; ?>"></span>
                        <?php echo esc_html($product['category']); ?>
                    </td>
                    <td>
                        <button type="button" class="button remove-upsell" data-product-id="<?php echo $product['id']; ?>">
                            <?php esc_html_e('Remove', 'checkout-upsell'); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Add New Upsell Product Form -->
    <h2><?php esc_html_e('Add New Upsell Product', 'checkout-upsell'); ?></h2>
    <form id="add-upsell-product-form" class="add-upsell-form">
        <?php wp_nonce_field('add_upsell_product', 'add_upsell_nonce'); ?>
        <div class="form-field">
            <label for="add-upsell-product-select"><?php esc_html_e('Product:', 'checkout-upsell'); ?></label>
            <select name="product_id" id="add-upsell-product-select" required>
                <option value=""><?php esc_html_e('Select a product', 'checkout-upsell'); ?></option>
                <?php
                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                );
                $products = get_posts($args);
                foreach ($products as $product) {
                    $wc_product = wc_get_product($product->ID);
                    $price = $wc_product->get_regular_price();
                    $category = $this->get_product_category($wc_product);
                    echo '<option value="' . esc_attr($product->ID) . '" data-price="' . esc_attr($price) . '" data-category="' . esc_attr($category) . '">' . esc_html($product->post_title) . '</option>';
                }
                ?>
            </select>
        </div>
        <div class="form-field">
            <label for="upsell-price"><?php esc_html_e('Upsell Price:', 'checkout-upsell'); ?></label>
            <input type="number" step="0.01" name="upsell_price" id="upsell-price" style="width: 100px;" />
        </div>
        <div class="form-field">
            <label><?php esc_html_e('Category:', 'checkout-upsell'); ?> <span id="product-category"></span></label>
        </div>
        <button type="submit" class="button button-primary" id="add-upsell-submit">
            <?php esc_html_e('Add Upsell Product', 'checkout-upsell'); ?>
        </button>
    </form>

    <!-- Upsell Conversion Metrics -->
    <h2><?php esc_html_e('Upsell Conversion Metrics', 'checkout-upsell'); ?></h2>
    <div class="charts-container">
        <!-- Bar Chart -->
        <div class="chart-wrapper">
            <canvas id="conversionBarChart"></canvas>
        </div>
        <!-- Pie Chart -->
        <div class="chart-wrapper">
            <canvas id="categoryPieChart"></canvas>
        </div>
    </div>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Product', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Times Shown', 'checkout-upsell'); ?></th>
                <th><?php esc_html_e('Times Purchased', 'checkout-upsell'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (empty($metrics)) {
                echo '<tr><td colspan="3">' . esc_html__('No metrics available.', 'checkout-upsell') . '</td></tr>';
            } else {
                foreach ($metrics as $product_id => $data) : 
            ?>
                <tr>
                    <td><?php echo esc_html($data['name']); ?></td>
                    <td><?php echo esc_html($data['total_shown']); ?></td>
                    <td><?php echo esc_html($data['total_purchased']); ?></td>
                </tr>
            <?php endforeach; } ?>
        </tbody>
    </table>

    <!-- Pass metrics data to JavaScript -->
    <script>
        error_log('Metrics: ' . print_r($metrics, true));
        const upsellMetrics = <?php echo wp_json_encode($metrics); ?>;
    </script>
</div>