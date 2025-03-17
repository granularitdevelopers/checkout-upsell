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
error_log('Admin Page Metrics: ' . print_r($metrics, true)); // Debug log to verify metrics data
$total_upsell_products = count($filtered_data);
$total_times_shown = array_sum(array_column($metrics, 'total_shown'));
$total_purchases = array_sum(array_column($metrics, 'total_purchased'));

// Assume is_premium_user() is available from the main plugin file
$is_premium = is_premium_user();
?>

<div class="wrap">
    <!-- Premium Features Styling -->
    <style>
    /* Premium Feature Overlay */
    .premium-feature-container {
        position: relative;
        margin: 20px 0;
        border-radius: 8px;
        overflow: hidden;
        min-height: 400px;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    /* Blur effect for premium features */
    .premium-blur {
        filter: blur(6px);
        pointer-events: none;
        opacity: 0.7;
        transition: all 0.3s ease;
    }

    /* Remove blur when premium */
    <?php if ($is_premium): ?>
    .premium-blur {
        filter: none;
        pointer-events: auto;
        opacity: 1;
    }
    <?php endif; ?>

    /* Premium overlay */
    .premium-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.5);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        z-index: 100;
        backdrop-filter: blur(2px);
    }

    /* Premium badge */
    .premium-badge {
        background: #FFD700;
        color: #333;
        padding: 5px 12px;
        border-radius: 15px;
        font-weight: bold;
        font-size: 14px;
        margin-bottom: 15px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    /* Upgrade button */
    .upgrade-button {
        background: #0073aa;
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 5px;
        font-weight: bold;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        display: inline-block;
    }

    .upgrade-button:hover {
        background: #005a87;
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        color: white;
    }

    /* Message below button */
    .premium-message {
        margin-top: 15px;
        text-align: center;
        max-width: 80%;
        font-size: 14px;
        color: #555;
    }

    /* Floating upgrade button */
    .floating-upgrade {
        position: fixed;
        bottom: 30px;
        right: 30px;
        z-index: 1000;
        padding: 15px 25px;
        font-size: 16px;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(0, 115, 170, 0.7); }
        70% { box-shadow: 0 0 0 10px rgba(0, 115, 170, 0); }
        100% { box-shadow: 0 0 0 0 rgba(0, 115, 170, 0); }
    }

    /* Overview Cards */
    .overview-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 20px;
    }

    .overview-card {
        flex: 1;
        min-width: 250px;
        background: white;
        border-radius: 5px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        padding: 20px;
        display: flex;
        align-items: center;
    }

    .overview-card .dashicons {
        font-size: 30px;
        margin-right: 15px;
        color: #0073aa;
    }

    /* Search and filter form */
    .search-box {
        margin: 20px 0;
        padding: 15px;
        background: white;
        border-radius: 5px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Add upsell form - Stack vertically */
    .add-upsell-form {
        margin: 20px 0;
        padding: 20px;
        background: white;
        border-radius: 5px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column; /* Stack vertically */
        gap: 15px;
    }

    .form-field {
        width: 100%; /* Full width for vertical stacking */
        max-width: 400px; /* Optional: Limit width for better readability */
    }

    .form-field label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }

    .form-field select,
    .form-field input[type="number"] {
        width: 100%; /* Full width for inputs */
        box-sizing: border-box;
    }

    .add-upsell-form .button-primary {
        width: 100%;
        max-width: 200px; /* Limit button width */
        align-self: flex-start; /* Align button to the left */
    }

    /* Charts and table container */
    .metrics-container {
        padding: 20px;
    }

    /* Charts container - Use flexbox to align charts side by side */
    .charts-container {
        display: flex;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 20px;
        width: 100%;
        min-height: 300px;
    }

    /* Chart wrapper - Ensure consistent sizing */
    .chart-wrapper {
        flex: 1;
        min-width: 0; /* Allow shrinking */
        max-width: 50%; /* Equal split */
        position: relative;
        background-color: #fff;
        border-radius: 4px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        padding: 15px;
    }

    /* Canvas styling for charts */
    .chart-wrapper canvas {
        width: 100% !important;
        height: 250px !important; /* Reduced height for better fit */
        display: block;
    }

    /* Chart title styling */
    .chart-title {
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 10px;
        text-align: center;
        color: #333;
    }

    /* Table styling for better readability */
    .wp-list-table {
        width: 100%;
        margin-top: 20px;
        background: #fff;
        border: 1px solid #e5e5e5;
        box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        table-layout: fixed;
    }

    /* Ensure the charts container and table are fully visible when premium */
    <?php if ($is_premium): ?>
    .charts-container, .wp-list-table {
        filter: none !important;
        opacity: 1 !important;
        pointer-events: auto !important;
    }
    <?php endif; ?>
    </style>

    <!-- Floating Upgrade Button -->
    <?php if (!$is_premium): ?>
        <a href="https://checkout-upsell-plugin.netlify.app/" class="button button-primary floating-upgrade">
            <span class="dashicons dashicons-star-filled" style="margin-right: 5px;"></span> Upgrade to Premium
        </a>
    <?php endif; ?>

    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <!-- License Key Form -->
    <h2><?php esc_html_e('License Key Activation', 'checkout-upsell'); ?></h2>
    <form method="post" action="options.php">
        <?php
        settings_fields('checkout_upsell_license_settings');
        do_settings_sections('checkout_upsell_license_settings');
        ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php _e('License Key', 'checkout-upsell'); ?></th>
                <td>
                    <input type="text" name="checkout_upsell_license_key" value="<?php echo esc_attr(get_option('checkout_upsell_license_key', '')); ?>" class="regular-text" placeholder="<?php esc_attr_e('Enter your license key', 'checkout-upsell'); ?>" />
                    <p class="description"><?php _e('Enter your license key to unlock premium features. You can find your key in your purchase email.', 'checkout-upsell'); ?></p>
                </td>
            </tr>
        </table>
        <?php submit_button('Activate License'); ?>
    </form>

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
            <input type="number" step="0.01" name="upsell_price" id="upsell-price" />
        </div>
        <div class="form-field">
            <label for="product-category-select"><?php esc_html_e('Category:', 'checkout-upsell'); ?></label>
            <select name="category" id="product-category-select" required>
                <option value=""><?php esc_html_e('Select a category', 'checkout-upsell'); ?></option>
                <option value="Dog"><?php esc_html_e('Dog', 'checkout-upsell'); ?></option>
                <option value="Cat"><?php esc_html_e('Cat', 'checkout-upsell'); ?></option>
            </select>
        </div>
        <button type="submit" class="button button-primary" id="add-upsell-submit">
            <?php esc_html_e('Add Upsell Product', 'checkout-upsell'); ?>
        </button>
    </form>

    <!-- Upsell Conversion Metrics with Premium Overlay -->
    <h2><?php esc_html_e('Upsell Conversion Metrics', 'checkout-upsell'); ?></h2>
    <div class="premium-feature-container">
        <div class="metrics-container">
            <!-- Original charts container with blur effect -->
            <div class="charts-container <?php echo !$is_premium ? 'premium-blur' : ''; ?>">
                <!-- Bar Chart -->
                <div class="chart-wrapper">
                    <div class="chart-title">Product Performance</div>
                    <canvas id="conversionBarChart"></canvas>
                </div>
                <!-- Pie Chart -->
                <div class="chart-wrapper">
                    <div class="chart-title">Category Distribution</div>
                    <canvas id="categoryPieChart"></canvas>
                </div>
            </div>

            <!-- Metrics Table with Premium Overlay -->
            <table class="wp-list-table widefat fixed striped <?php echo !$is_premium ? 'premium-blur' : ''; ?>">
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
        </div>
        
        <!-- Premium overlay -->
        <?php if (!$is_premium): ?>
            <div class="premium-overlay">
                <div class="premium-badge">Premium Feature</div>
                <a href="https://checkout-upsell-plugin.netlify.app/" class="upgrade-button">Unlock Analytics</a>
                <p class="premium-message">Get detailed insights on your upsell performance with our premium plan</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pass metrics data to JavaScript and initialize charts -->
    <script>
    <?php 
    $clean_metrics = array();
    foreach ($metrics as $product_id => $data) {
        $clean_metrics[$product_id] = array(
            'name' => isset($data['name']) ? $data['name'] : 'Unknown',
            'category' => isset($data['category']) ? $data['category'] : 'Unknown',
            'total_shown' => isset($data['total_shown']) ? (int)$data['total_shown'] : 0,
            'total_purchased' => isset($data['total_purchased']) ? (int)$data['total_purchased'] : 0
        );
    }
    ?>
    const upsellMetrics = <?php echo json_encode($clean_metrics); ?>;
    console.log('Metrics loaded:', upsellMetrics);

    document.addEventListener('DOMContentLoaded', function() {
        const ctxBar = document.getElementById('conversionBarChart').getContext('2d');
        const ctxPie = document.getElementById('categoryPieChart').getContext('2d');

        // Aggregate data for pie chart by category
        const categoryData = {};
        Object.values(upsellMetrics).forEach(item => {
            const category = item.category;
            if (!categoryData[category]) {
                categoryData[category] = 0;
            }
            categoryData[category] += item.total_purchased;
        });

        const pieLabels = Object.keys(categoryData);
        const pieValues = Object.values(categoryData);

        // Bar Chart (Conversion Metrics)
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: Object.values(upsellMetrics).map(item => item.name.length > 15 ? item.name.substring(0, 15) + '...' : item.name), // Truncate long labels
                datasets: [{
                    label: 'Times Shown',
                    data: Object.values(upsellMetrics).map(item => item.total_shown),
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }, {
                    label: 'Times Purchased',
                    data: Object.values(upsellMetrics).map(item => item.total_purchased),
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 45, // Rotate labels for better fit
                            minRotation: 45
                        }
                    },
                    y: {
                        beginAtZero: true
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                }
            }
        });

        // Pie Chart (Category Distribution)
        new Chart(ctxPie, {
            type: 'pie',
            data: {
                labels: pieLabels, // Unique category labels
                datasets: [{
                    data: pieValues, // Aggregated total_purchased values
                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 10,
                            padding: 10
                        }
                    }
                }
            }
        });
    });
    </script>
</div>