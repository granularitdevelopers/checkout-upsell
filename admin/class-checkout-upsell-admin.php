<?php
if (!defined('ABSPATH')) {
    exit;
}

class Checkout_Upsell_Admin {
        /**
     * @var array
     */
    // private $manual_upsell_prices;

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Initialize the manual_upsell_prices option
        add_option('manual_upsell_prices', array());

        // initialize AJAX handlers
        $this->init_ajax_handlers();
        $this->init_tracking();
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_checkout-upsell' !== $hook) {
            return;
        }

        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);

        wp_enqueue_script('checkout-upsell-admin-script', CHECKOUT_UPSELL_PLUGIN_URL . 'admin/js/admin-script.js', array('jquery'), CHECKOUT_UPSELL_VERSION, true);
        wp_enqueue_style('checkout-upsell-admin-style', CHECKOUT_UPSELL_PLUGIN_URL . 'admin/css/admin-style.css', array(), CHECKOUT_UPSELL_VERSION);

    }


    /**
     * Intialize AJAX handlers.
     */
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

    /**
     * AJAX handler to update upsell prices from the admin panel
     */
    public function ajax_update_upsell_price() {
        check_ajax_referer('update_upsell_prices', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $new_price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);

        if ($product_id && $new_price !== false) {
            $manual_upsell_prices = get_option('manual_upsell_prices', array());
            $manual_upsell_prices[$product_id] = $new_price;
            update_option('manual_upsell_prices', $manual_upsell_prices);
            wp_send_json_success(array('message' => 'Price updated successfully'));
        } else {
            wp_send_json_error('Invalid product ID or price');
        }
    }

    /**
     * AJAX handler to add upsell products on the admin panel
     */
    public function ajax_add_upsell_product() {
        // Verify nonce
        check_ajax_referer('add_upsell_product', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
    
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
    
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
            return;
        }
    
        try {
            $product = wc_get_product($product_id);
        
            if (!$product) {
                wp_send_json_error(array('message' => 'Product not found'));
                return;
            }
    
            // Get product tags
            $product_tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'slugs'));

            // Define tag mappings
            $dog_tags = ['dog', 'adult-dog', 'puppy'];
            $cat_tags = ['cat', 'adult-cat', 'kitten'];

            // Detrmine the tag
            $category = null;
            $dog_match = array_intersect($product_tags, $dog_tags);
            $cat_match = array_intersect($product_tags, $cat_tags);

            if (!empty($dog_match)) {
                $category = 'dog'; // Prioritize dog
            } elseif (!empty($cat_match)) {
                $category = 'cat';
            }

            if (!$category) {
                wp_send_json_error(array('message' => 'Product must be in either Dog or Cat category in order to be added as an upsell'));
                return;
            }
    
            // Add the upsell tag
            $tag_name = 'upsell-' . $category;
            $result = wp_set_post_terms($product_id, $tag_name, 'product_tag', true);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }
    
            // Add the product to manual_upsell_prices with its regular price as default
            $manual_upsell_prices = get_option('manual_upsell_prices', array());
            $manual_upsell_prices[$product_id] = $product->get_regular_price();
            update_option('manual_upsell_prices', $manual_upsell_prices);
    
            // Save the product
            $product->save();
    
            // Prepare response data
            $response_data = array(
                'success' => true,
                'message' => 'Product successfully added as upsell product',
                'product' => array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'regular_price' => wc_price($product->get_regular_price()),
                    'upsell_price' => $manual_upsell_prices[$product_id] ?? $product->get_price(),
                    'min_quantity' => $product->get_meta('minimum_allowed_quantity', true) ?: 1,
                    'category' => ucfirst($category)
                )
            );

            add_action('admin_notices', array($this, 'admin_notice_product_added'));
    
            wp_send_json_success($response_data);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX handler to remove product  from the upsell products
     */
    public function ajax_remove_upsell_product() {
        check_ajax_referer('remove_upsell_product', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                // Get all current tags associated with the product.
                $tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
    
                // Filter out the tags that contain "upsell" in their name.
                $tags_to_keep = array_filter($tags, function($tag) {
                    return strpos($tag, 'upsell') === false;
                });
    
                // Update the product with the remaining tags.
                wp_set_post_terms($product_id, $tags_to_keep, 'product_tag');
    
                // Save the product.
                $product->save();
    
                // Remove the product from manual_upsell_prices
                $manual_upsell_prices = get_option('manual_upsell_prices', array());
                unset($manual_upsell_prices[$product_id]);
                update_option('manual_upsell_prices', $manual_upsell_prices);
    
                // Send a success response.
                wp_send_json_success(array('message' => 'Product removed successfully'));
            } else {
                wp_send_json_error('Invalid product');
            }
        } else {
            wp_send_json_error('Invalid product ID');
        }
    }
    

    /**
     * Add menu item.
     */
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

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('checkout_upsell_options', 'checkout_upsell_enabled');
    }

    /**
     * Display the admin page
     */
    public function display_admin_page() {
        $admin_page_path = CHECKOUT_UPSELL_PLUGIN_DIR . 'admin/partials/admin-page.php';
        
        if (file_exists($admin_page_path)) {
            include $admin_page_path;
        } else {
            $error_message = sprintf(__('Admin page file not found: %s', 'checkout-upsell'), $admin_page_path);
            echo '<div class="error"><p>' . esc_html($error_message) . '</p></div>';
        }
    }

    /**
     * Function to get tag id for products
     */
    private function get_tag_id($slug) {
        $term = get_term_by('slug', $slug, 'product_tag');
        return $term ? $term->term_id : 0;
    }

    /**
     * Get the manula Upsell prices
     * 
     * @return array
     */
    public function get_upsell_data() {
        $upsell_data = array();
        $public_instance = new Checkout_Upsell_Public();
        $manual_upsell_prices = $public_instance->getManualUpsellPrices();

        $dog_upsells = $public_instance->call_get_upsell_products('upsell-dog');
        $cat_upsells = $public_instance->call_get_upsell_products('upsell-cat');

        foreach (array_merge($dog_upsells, $cat_upsells) as $product_id) {
            $product = wc_get_product($product_id);
            $upsell_data[] = array(
                'id' => $product_id,
                'name' => $product->get_name(),
                'regular_price' => $product->get_regular_price(),
                'upsell_price' => $manual_upsell_prices[$product_id] ?? $product->get_price(),
                'min_quantity' => $product->get_meta('minimum_allowed_quantity', true) ?: 1,
                'category' => in_array($this->get_tag_id('dog'), $product->get_tag_ids()) ? 'Dog' : 'Cat'
            );
        }

        return $upsell_data;
    }

    /**
     * Handle admin actions functions
     */
    public function handle_admin_actions() {
        if (isset($_POST['update_upsell_prices']) && check_admin_referer('update_upsell_prices', 'upsell_nonce')) {
            $this->update_upsell_prices();
        }

        if (isset($_POST['add_upsell_product_submit']) && check_admin_referer('add_upsell_nonce', 'add_upsell_nonce')) {
            $this->add_upsell_product();
        }

        if (isset($_POST['remove_upsell_product']) && check_admin_referer('remove_upsell_product', 'remove_upsell_nonce')) {
            $this->remove_upsell_product();
        }
    }

    /**
     * handle function for updating upsell on admin side
     * 
     * @return  void
     */
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

    /**
     * handle function for add upsell on admin side
     * 
     * @return  void
     */
    private function add_upsell_product() {
        // Verify nonce first
        // if (!check_ajax_referer('checkout_upsell_nonce', 'nonce', false)) {
        //     wp_send_json_error(array('message' => 'Invalid security token'));
        //     return;
        // }

        // Get and validate product ID
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
            return;
        }

        try {
            $product = wc_get_product($product_id);
        
            if (!$product) {
                wp_send_json_error(array('message' => 'Product not found'));
                return;
            }
    
            // Get product tags
            $product_tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'slugs'));

            // Define tag mappings
            $dog_tags = ['dog', 'puppy', 'adult-dog'];
            $cat_tags = ['cat', 'kitten', 'adult-cat'];

            // Determine category
            // Detrmine the tag
            $category = null;
            $dog_match = array_intersect($product_tags, $dog_tags);
            $cat_match = array_intersect($product_tags, $cat_tags);

            if (!empty($dog_match)) {
                $category = 'dog'; // Prioritize dog
            } elseif (!empty($cat_match)) {
                $category = 'cat';
            }

            if (!$category) {
                wp_send_json_error(array('message' => 'Product must be in either Dog or Cat category in order to be added as an upsell'));
                return;
            }

            // Add the upsell tag
            $tag_name = 'upsell-' . $category;
            $result = wp_set_post_terms($product_id, $tag_name, 'product_tag', true);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
                return;
            }

            // Add the product to manual_upsell_prices with its regular price as default
            $manual_upsell_prices = get_option('manual_upsell_prices', array());
            $manual_upsell_prices[$product_id] = $product->get_regular_price();
            update_option('manual_upsell_prices', $manual_upsell_prices);

            clean_post_cache($product_id);
            wp_cache_flush();
    
            // Prepare response data
            $response_data = array(
                'message' => 'Product successfully added as upsell',    
                'product' => array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'regular_price' => $product->get_regular_price(),
                    'upsell_price' => $manual_upsell_prices[$product_id] ?? $product->get_price(),
                    'min_quantity' => $product->get_meta('minimum_allowed_quantity', true) ?: 1,
                    'category' => ucfirst($category)
                )
            );
    
            wp_send_json_success($response_data);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * handle function for add upsell on admin side
     * 
     * @return  void
     */
    private function remove_upsell_product() {
        $public_instance = new Checkout_Upsell_Public();
        $manual_upsell_prices = $public_instance->getManualUpsellPrices();
        $product_id = intval($_POST['remove_upsell_product']);
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                // Get all current tags associated with the product.
                $tags = wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']);
    
                // Filter out tags containing "upsell".
                $tags_to_keep = array_filter($tags, function($tag) {
                    return strpos($tag, 'upsell') === false;
                });
    
                // Update the product with the remaining tags.
                wp_set_post_terms($product_id, $tags_to_keep, 'product_tag');
    
                // Save the product.
                $product->save();
    
                // Update the manual upsell prices and remove the product ID from the list.
                unset($manual_upsell_prices[$product_id]);
                update_option('manual_upsell_prices', $manual_upsell_prices);
    
                // Add an admin notice for feedback.
                add_action('admin_notices', array($this, 'admin_notice_product_removed'));
            }
        }
    }
    

    /**
     * Function to get product category
     */
    private function get_product_category($product) {
        $product_cats = wp_get_post_terms($product->get_id(), 'product_cat');
        foreach ($product_cats as $cat) {
            $cat_name = strtolower($cat->name);
            if ($cat_name === 'dog' || $cat_name === 'cat') {
                return $cat_name;
            }
        }
        return '';
    }

    // Upsell Conersion Rate Tracking
    private function init_tracking() {
        // Track when upsell is purchased
        add_action('woocommerce_checkout_order_processed', array($this, 'track_upsell_purchase'));
        add_action('woocommerce_checkout_order_processed', array($this, 'track_upsell_in_order'), 10, 1);
    }
    
    public function track_upsell_purchase($order_id) {
        $order = wc_get_order($order_id);
        $impressions = get_option('checkout_upsell_impressions', array());
        $current_timestamp = current_time('timestamp');
        
        // Get all upsell products from the order
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_is_upsell')) {
                $product_id = $item['product_id'];
                
                if (isset($impressions[$product_id])) {
                    $impressions[$product_id]['total_purchased']++;

                    // Track purchase timestamp
                    $impressions[$product_id]['purchase_timestamps'][] = $current_timestamp;
                    
                    // Track additional purchase data if needed
                    $original_cart_total = $item->get_meta('_original_cart_total');
                    if ($original_cart_total) {
                        if (!isset($impressions[$product_id]['purchase_data'])) {
                            $impressions[$product_id]['purchase_data'] = array();
                        }
                        $impressions[$product_id]['purchase_data'][] = array(
                            'timestamp' => $current_timestamp,
                            'original_cart_total' => $original_cart_total,
                            'quantity_purchased' => $item->get_quantity()
                        );
                    }

                    // Limit historical data
                    $impressions[$product_id]['purchase_timestamps'] = array_slice(
                        $impressions[$product_id]['purchase_timestamps'],
                        -100
                    );
                    $impressions[$product_id]['purchase_data'] = array_slice(
                        $impressions[$product_id]['purchase_data'],
                        -100
                    );
                }
            }
        }
        update_option('checkout_upsell_impressions', $impressions);
    }

    // Enhanced metrics collection
    public function get_upsell_metrics() {
        $impressions = get_option('checkout_upsell_impressions', array());
        $metrics = array();
        
        foreach ($impressions as $product_id => $data) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            
            $conversion_rate = $data['total_shown'] > 0 
                ? ($data['total_purchased'] / $data['total_shown']) * 100 
                : 0;
            
            // Calculate recent conversion rate (last 30 days)
            $thirty_days_ago = strtotime('-30 days');
            $recent_impressions = count(array_filter($data['show_timestamps'], function($timestamp) use ($thirty_days_ago) {
                return $timestamp >= $thirty_days_ago;
            }));
            
            $metrics[$product_id] = array(
                'name' => $product->get_name(),
                'total_shown' => $data['total_shown'],
                'total_purchased' => $data['total_purchased'],
                'conversion_rate' => round($conversion_rate, 2),
                'recent_impressions' => $recent_impressions,
                'cart_type_distribution' => array(
                    'dog' => isset($data['cart_types']['dog']) ? $data['cart_types']['dog'] : 0,
                    'cat' => isset($data['cart_types']['cat']) ? $data['cart_types']['cat'] : 0
                )
            );
        }
        return $metrics;
    }

    // Method to handle order tracking
    public function track_upsell_in_order($order_id) {
        $order = wc_get_order($order_id);
        $upsell_products = array();

        // Loop through order items to find upsells
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_is_upsell')) {
                $upsell_products[] = $item['product_id'];
            }
        }

        if (!empty($upsell_products)) {
            $order->update_meta_data('_upsell_product_ids', $upsell_products);
            $order->save();
        }
    }
}

new Checkout_Upsell_Admin();