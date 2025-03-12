<?php
if (!defined('ABSPATH')) {
    exit;
}

// Define the testing constant using multiple checks
if (!defined('CHECKOUT_UPSELL_IS_TESTING')) {
    $is_testing = (defined('WP_TESTS_DIR') && WP_TESTS_DIR) || // Check WP_TESTS_DIR constant
                  (getenv('WP_TESTS_DIR') !== false) ||       // Check environment variable
                  (defined('PHPUnit_MAIN_METHOD') && PHPUnit_MAIN_METHOD); // Check PHPUnit
    define('CHECKOUT_UPSELL_IS_TESTING', $is_testing);
    if (CHECKOUT_UPSELL_IS_TESTING) {
        error_log("CHECKOUT_UPSELL_IS_TESTING set to: true");
    }
}

class Checkout_Upsell_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        add_option('manual_upsell_prices', array());
        

        $this->init_ajax_handlers();
        $this->init_tracking();
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_checkout-upsell' !== $hook) {
            return;
        }

        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true);
        wp_enqueue_style('datatables', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css');
        wp_enqueue_script('datatables', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', ['jquery'], '1.13.6', true);

        wp_enqueue_script('checkout-upsell-admin-script', CHECKOUT_UPSELL_PLUGIN_URL . 'admin/js/admin-script.js', array('jquery', 'chart-js', 'datatables'), CHECKOUT_UPSELL_VERSION, true);
        wp_enqueue_style('checkout-upsell-admin-style', CHECKOUT_UPSELL_PLUGIN_URL . 'admin/css/admin-style.css', array(), CHECKOUT_UPSELL_VERSION);

        wp_localize_script('checkout-upsell-admin-script', 'checkoutUpsellData', [
            'selectPlaceholder' => esc_html__('Select a product', 'checkout-upsell'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('add_upsell_product')
        ]);
    }

    private function init_ajax_handlers() {
        $ajax_actions = array(
            'update_upsell_price',
            'add_upsell_product',
            'remove_upsell_product',
        );

        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_' . $action, array($this, 'ajax_' . $action));
            add_action('wp_ajax_nopriv_' . $action, array($this, 'ajax_' . $action));
        }
    }

    public function ajax_update_upsell_price() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'update_upsell_prices')) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Invalid security token'];
            }
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
    
        if (!current_user_can('manage_options')) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Insufficient permissions'];
            }
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $new_price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    
        if ($product_id > 0 && $new_price !== false) {
            $manual_upsell_prices = get_option('manual_upsell_prices', []);
            $manual_upsell_prices[$product_id] = $new_price;
            update_option('manual_upsell_prices', $manual_upsell_prices);
            $response = ['success' => true, 'message' => 'Price updated successfully'];
    
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return $response;
            }
            wp_send_json_success(['message' => 'Price updated successfully']);
        } else {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Invalid product ID or price'];
            }
            wp_send_json_error('Invalid product ID or price');
        }
    }

    public function ajax_add_upsell_product() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'add_upsell_product')) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Invalid security token'];
            }
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
    
        if (!current_user_can('manage_options')) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Insufficient permissions'];
            }
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $product_id_raw = isset($_POST['product_id']) ? $_POST['product_id'] : 0;
        $product_id = intval($product_id_raw);
    
        if ($product_id <= 0) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Invalid product ID'];
            }
            wp_send_json_error(['message' => 'Invalid product ID']);
            return;
        }
    
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                if (CHECKOUT_UPSELL_IS_TESTING) {
                    return ['success' => false, 'message' => 'Product not found'];
                }
                wp_send_json_error(['message' => 'Product not found']);
                return;
            }
    
            $product_tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'slugs']);
            if (empty($product_tags)) {
                if (CHECKOUT_UPSELL_IS_TESTING) {
                    return ['success' => false, 'message' => 'Product has no tags'];
                }
                wp_send_json_error(['message' => 'Product has no tags']);
                return;
            }
    
            $dog_tags = ['dog', 'adult-dog', 'puppy'];
            $cat_tags = ['cat', 'adult-cat', 'kitten'];
    
            $category = null;
            $dog_match = array_intersect($product_tags, $dog_tags);
            $cat_match = array_intersect($product_tags, $cat_tags);
    
            if (!empty($dog_match)) {
                $category = 'dog';
            } elseif (!empty($cat_match)) {
                $category = 'cat';
            }
    
            if (!$category) {
                if (CHECKOUT_UPSELL_IS_TESTING) {
                    return ['success' => false, 'message' => 'Product must be in either Dog or Cat category to be added as an upsell'];
                }
                wp_send_json_error(['message' => 'Product must be in either Dog or Cat category to be added as an upsell']);
                return;
            }
    
            $tag_name = 'upsell-' . $category;
            $result = wp_set_post_terms($product_id, $tag_name, 'product_tag', true);
            if (is_wp_error($result)) {
                if (CHECKOUT_UPSELL_IS_TESTING) {
                    return ['success' => false, 'message' => $result->get_error_message()];
                }
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
    
            $manual_upsell_prices = get_option('manual_upsell_prices', []);
            $manual_upsell_prices[$product_id] = $product->get_regular_price();
            update_option('manual_upsell_prices', $manual_upsell_prices);
    
            $product->save();
    
            $response_data = [
                'success' => true,
                'message' => 'Product successfully added as upsell product',
                'product' => [
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'regular_price' => wc_price($product->get_regular_price()),
                    'upsell_price' => $manual_upsell_prices[$product_id] ?? $product->get_price(),
                    'min_quantity' => $product->get_meta('minimum_allowed_quantity', true) ?: 1,
                    'category' => ucfirst($category)
                ]
            ];
    
            if (CHECKOUT_UPSELL_IS_TESTING) {
                error_log("Test mode detected, returning response: " . print_r($response_data, true));
                return $response_data;
            }
            wp_send_json($response_data);
        } catch (Exception $e) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => $e->getMessage()];
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_remove_upsell_product() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'remove_upsell_product')) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Invalid security token'];
            }
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }
    
        if (!current_user_can('manage_options')) {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Insufficient permissions'];
            }
            wp_send_json_error('Insufficient permissions');
            return;
        }
    
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
                $tags_to_keep = array_filter($tags, function($tag) {
                    return strpos($tag, 'upsell') === false;
                });
                wp_set_post_terms($product_id, $tags_to_keep, 'product_tag');
    
                $product->save();
    
                $manual_upsell_prices = get_option('manual_upsell_prices', []);
                unset($manual_upsell_prices[$product_id]);
                update_option('manual_upsell_prices', $manual_upsell_prices);
    
                $response = [
                    'success' => true,
                    'data' => ['message' => 'Product removed successfully']
                ];
    
                if (CHECKOUT_UPSELL_IS_TESTING) {
                    return $response;
                }
                wp_send_json($response);
            } else {
                if (CHECKOUT_UPSELL_IS_TESTING) {
                    return ['success' => false, 'message' => 'Invalid product'];
                }
                wp_send_json_error('Invalid product');
            }
        } else {
            if (CHECKOUT_UPSELL_IS_TESTING) {
                return ['success' => false, 'message' => 'Invalid product ID'];
            }
            wp_send_json_error('Invalid product ID');
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Checkout Upsell', 'checkout-upsell'),
            __('Checkout Upsell', 'checkout-upsell'),
            'manage_options',
            'checkout-upsell',
            array($this, 'display_admin_page'),
            'dashicons-cart',
            56
        );
    }

    public function register_settings() {
        register_setting('checkout_upsell_options', 'checkout_upsell_enabled');
    }

    public function display_admin_page() {
        $admin_page_path = CHECKOUT_UPSELL_PLUGIN_DIR . 'admin/partials/admin-page.php';
        if (file_exists($admin_page_path)) {
            include $admin_page_path;
        } else {
            $error_message = sprintf(__('Admin page file not found: %s', 'checkout-upsell'), $admin_page_path);
            echo '<div class="error"><p>' . esc_html($error_message) . '</p></div>';
        }
    }

    private function get_tag_id($slug) {
        $term = get_term_by('slug', $slug, 'product_tag');
        return $term ? $term->term_id : 0;
    }

    public function get_upsell_data() {
        $upsell_data = array();
        $public_instance = new Checkout_Upsell_Public();
        $manual_upsell_prices = $public_instance->getManualUpsellPrices();

        $dog_upsells = $public_instance->call_get_upsell_products('upsell-dog');
        $cat_upsells = $public_instance->call_get_upsell_products('upsell-cat');

        foreach (array_merge($dog_upsells, $cat_upsells) as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $category = $this->get_product_category($product);
                $upsell_data[] = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'regular_price' => $product->get_regular_price(),
                    'upsell_price' => $manual_upsell_prices[$product_id] ?? $product->get_price(),
                    'min_quantity' => $product->get_meta('minimum_allowed_quantity', true) ?: 1,
                    'category' => $category
                );
            }
        }

        return $upsell_data;
    }

    public function handle_admin_actions() {
        if (isset($_POST['update_upsell_prices']) && check_admin_referer('update_upsell_prices', 'upsell_nonce')) {
            $this->update_upsell_prices();
        }
    }

    private function update_upsell_prices() {
        $public_instance = new Checkout_Upsell_Public();
        $manual_upsell_prices = $public_instance->getManualUpsellPrices();

        if (isset($_POST['upsell_price']) && is_array($_POST['upsell_prices'])) {
            foreach ($_POST['upsell_price'] as $product_id => $price) {
                $manual_upsell_prices[$product_id] = floatval($price);
            }
            update_option('manual_upsell_prices', $manual_upsell_prices);
            add_action('admin_notices', array($this, 'admin_notice_prices_updated'));
        }
    }

    private function get_product_category($product) {
        $product_cats = wp_get_post_terms($product->get_id(), 'product_cat');
        foreach ($product_cats as $cat) {
            $cat_name = strtolower($cat->name);
            if ($cat_name === 'dog' || $cat_name === 'cat') {
                return ucfirst($cat_name); // Return 'Dog' or 'Cat'
            }
        }
        // Fallback to tag-based category if no product category matches
        $product_tags = wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'slugs']);
        $dog_tags = ['dog', 'adult-dog', 'puppy'];
        $cat_tags = ['cat', 'adult-cat', 'kitten'];
        if (array_intersect($product_tags, $dog_tags)) return 'Dog';
        if (array_intersect($product_tags, $cat_tags)) return 'Cat';
        return 'Unknown';
    }

    private function init_tracking() {
        add_action('woocommerce_checkout_order_processed', array($this, 'track_upsell_purchase'));
        add_action('woocommerce_checkout_order_processed', array($this, 'track_upsell_in_order'), 10, 1);
    }
    
    public function track_upsell_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $impressions = get_option('checkout_upsell_impressions', array());
        $current_timestamp = current_time('timestamp');

        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_is_upsell')) {
                $product_id = $item->get_product_id();
                if (!isset($impressions[$product_id])) {
                    $impressions[$product_id] = array(
                        'total_shown' => 0,
                        'total_purchased' => 0,
                        'show_timestamps' => array(),
                        'purchase_timestamps' => array(),
                        'purchase_data' => array(),
                        'cart_types' => array('dog' => 0, 'cat' => 0)
                    );
                }

                $impressions[$product_id]['total_purchased'] = ($impressions[$product_id]['total_purchased'] ?? 0) + 1;
                $impressions[$product_id]['purchase_timestamps'][] = $current_timestamp;

                $impressions[$product_id]['purchase_data'] = $impressions[$product_id]['purchase_data'] ?? [];
                $original_cart_total = $item->get_meta('_original_cart_total');
                if ($original_cart_total) {
                    $impressions[$product_id]['purchase_data'][] = array(
                        'timestamp' => $current_timestamp,
                        'original_cart_total' => $original_cart_total,
                        'quantity_purchased' => $item->get_quantity()
                    );
                }

                if (count($impressions[$product_id]['purchase_timestamps']) > 100) {
                    $impressions[$product_id]['purchase_timestamps'] = array_slice(
                        $impressions[$product_id]['purchase_timestamps'],
                        -100
                    );
                }
                if (count($impressions[$product_id]['purchase_data']) > 100) {
                    $impressions[$product_id]['purchase_data'] = array_slice(
                        $impressions[$product_id]['purchase_data'],
                        -100
                    );
                }

                $category = $this->get_product_category(wc_get_product($product_id));
                if ($category === 'Dog') {
                    $impressions[$product_id]['cart_types']['dog'] = ($impressions[$product_id]['cart_types']['dog'] ?? 0) + 1;
                } elseif ($category === 'Cat') {
                    $impressions[$product_id]['cart_types']['cat'] = ($impressions[$product_id]['cart_types']['cat'] ?? 0) + 1;
                }
            }
        }
        update_option('checkout_upsell_impressions', $impressions);
        error_log('Updated Impressions: ' . print_r($impressions, true)); // Debug log
    }

    public function get_upsell_metrics() {
        $impressions = get_option('checkout_upsell_impressions', array());
        $metrics = array();
        
        foreach ($impressions as $product_id => $data) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            
            $category = $this->get_product_category($product);
            $total_shown = isset($data['total_shown']) ? (int) $data['total_shown'] : 0;
            $total_purchased = isset($data['total_purchased']) ? (int) $data['total_purchased'] : 0;
            
            $conversion_rate = $total_shown > 0 
                ? round(($total_purchased / $total_shown) * 100, 2) 
                : 0;
            
            $thirty_days_ago = strtotime('-30 days');
            $recent_impressions = count(array_filter(
                $data['show_timestamps'] ?? [],
                function($timestamp) use ($thirty_days_ago) {
                    return $timestamp >= $thirty_days_ago;
                }
            ));
            
            $cart_types = isset($data['cart_types']) && is_array($data['cart_types']) 
                ? $data['cart_types'] 
                : array('dog' => 0, 'cat' => 0);
            
            $metrics[$product_id] = array(
                'name' => $product->get_name(),
                'total_shown' => $total_shown,
                'total_purchased' => $total_purchased,
                'category' => $category,
                'conversion_rate' => $conversion_rate,
                'recent_impressions' => $recent_impressions,
                'cart_type_distribution' => array(
                    'dog' => (int) ($cart_types['dog'] ?? 0),
                    'cat' => (int) ($cart_types['cat'] ?? 0)
                )
            );
        }
        
        error_log('Generated Metrics: ' . print_r($metrics, true)); // Debug log
        return $metrics;
    }

    public function track_upsell_in_order($order_id) {
        $order = wc_get_order($order_id);
        $upsell_products = array();

        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_is_upsell')) {
                $upsell_products[] = $item->get_product_id();
            }
        }

        if (!empty($upsell_products)) {
            $order->update_meta_data('_upsell_product_ids', $upsell_products);
            $order->save();
        }
    }
}

new Checkout_Upsell_Admin();