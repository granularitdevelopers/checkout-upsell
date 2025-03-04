<?php
if (!defined('ABSPATH')) {
    exit;
}

class Checkout_Upsell_Public {
    /**
     * @var array
     */
    private $manual_upsell_prices;

    /**
     * Import manual upsell prices globally
     * 
     * @return array
     */
    public function getManualUpsellPrices() {
        if (empty($this->manual_upsell_prices)) {
            $this->manual_upsell_prices = get_option('manual_upsell_prices', array());
        }
        return $this->manual_upsell_prices;
    }

    /**
     * @var bool
     */
    private $has_upsell_products = false;

    /**
     * Constructor.
     * 
     * @since 1.3
     */
    public function __construct()
    {
        if (checkout_upsell()->is_enabled()) {
            $this->init_hooks();
        }
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Initialize manual upsell prices
        $this->manual_upsell_prices = $this->get_manual_upsell_prices();

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_add_to_cart', array($this, 'recalculate_cart_total'), 20, 3);
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_upsell_discount'));
        add_action('woocommerce_before_checkout_form', array($this, 'check_for_upsell_products'), 10);
        add_filter('woocommerce_payment_successful_result', array($this, 'get_products_to_upsell'), 10, 1);
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_upsell_cart_item_to_order'), 10, 4);
        add_action('wp_footer', array($this, 'add_upsell_popup_markup'));
        // Add hook to clear upsell products when returning from payment
        add_action('wp', array($this, 'maybe_clear_upsell_products'));
        add_action('woocommerce_thankyou', array($this, 'remove_all_upsell_products'));

        $this->init_ajax_handlers();
    }

    /**
     * Initialize AJAX handlers.
     */
    private function init_ajax_handlers() {
        $ajax_actions = array(
            'check_for_upsell_products',
            'check_cart_contents_handler',
            'get_upsell_products',
            'add_upsell_to_cart',
            'remove_upsell_product_from_cart',
            'remove_upsell_from_cart',
            'check_product_in_cart',
        );

        foreach ($ajax_actions as $action) {
            add_action('wp_ajax_' . $action, array($this, 'ajax_' . $action));
            add_action('wp_ajax_nopriv_' . $action, array($this, 'ajax_' . $action));
        }
    }

    /**
     * Enqueue public-facing scripts and styles.
     */
    public function enqueue_scripts() {
        if (is_checkout() || is_cart()) {
            wp_enqueue_script('checkout-upsell-script', CHECKOUT_UPSELL_PLUGIN_URL . 'public/js/checkout-upsell-public.js', array('jquery', 'wc-checkout'), CHECKOUT_UPSELL_VERSION, true);
            wp_localize_script('checkout-upsell-script', 'checkout_upsell_params', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('checkout-upsell-nonce'),
            ));
            wp_enqueue_style('checkout-upsell-style', CHECKOUT_UPSELL_PLUGIN_URL . 'public/css/checkout-upsell-public.css', array(), CHECKOUT_UPSELL_VERSION);
        }
    }

    /**
     * AJAX handler for getting upsell products.
     */
    public function ajax_get_upsell_products() {
        check_ajax_referer('checkout-upsell-nonce', 'nonce');

        $this->manual_upsell_prices = get_option('manual_upsell_prices', array());
        
        $upsell_products = $this->get_products_to_upsell(array());

        // Track impressions for each upsell product
        foreach ($upsell_products['upsell_products'] as $upsell_product) {
            $this->track_upsell_impression($upsell_product['id']);
        }
        
        ob_start();
        wc_get_template('upsell-popup.php', array(
            'upsell_products' => $upsell_products['upsell_products'],
            'manual_upsell_prices' => $this->manual_upsell_prices
        ), '', CHECKOUT_UPSELL_PLUGIN_DIR . 'templates/');
        $html = ob_get_clean();
        wp_send_json_success($html);
    }
    
    public function ajax_add_upsell_to_cart() {
        // Verify the nonce
        check_ajax_referer('checkout-upsell-nonce', 'nonce');
    
        // Retrieve product ID and quantity from the AJAX request
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
    
        if ($product_id) {
            // Get the product
            $product = wc_get_product($product_id);
    
            if (!$product) {
                wp_send_json_error(array('message' => __('Invalid product', 'checkout-upsell')));
                return;
            }
    
            // Get the minimum allowed quantity from the product meta
            $min_quantity = $product->get_meta('minimum_allowed_quantity', true);
            $min_quantity = !empty($min_quantity) ? intval($min_quantity) : 1;
    
            // Ensure the quantity is at least the minimum allowed
            if ($quantity < $min_quantity) {
                $quantity = $min_quantity;
            }

            // Add custom cart item data to identify this as an upsell product
            $cart_item_data = array(
                'upsell_data' => array(
                    'is_upsell' => true,
                    'added_from_popup' => true,
                    'original_cart_total' => WC()->cart->get_cart_total()
                )
            );

            // Track the impression before adding the product to the cart
            $this->track_upsell_impression($product_id, false);
    
            // Attempt to add the product to the cart with the correct quantity
            $added = WC()->cart->add_to_cart($product_id, $quantity, 0, array(), $cart_item_data);
    
            if ($added) {
                WC()->cart->calculate_totals();
                $cart_total = WC()->cart->get_cart_total();

                $fragments = apply_filters('woocommerce_add_to_cart_fragments', array());

                wp_send_json_success(array(
                    'cart_total' => $cart_total,
                    'fragments' => $fragments
                ));
            } else {
                wp_send_json_error(array('message' => __('Failed to add product to cart', 'checkout-upsell')));
            }
        } else {
            // Invalid product ID
            wp_send_json_error(array('message' => __('Invalid product ID', 'checkout-upsell')));
        }
    }
    
    public function ajax_remove_upsell_product_from_cart() {
        check_ajax_referer('checkout-upsell-nonce', 'nonce');

        $cart = WC()->cart;
        $items_removed = false;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['upsell']) && $cart_item['upsell']) {
                $cart->remove_cart_item($cart_item_key);
                $items_removed = true;
            }
        }

        if ($items_removed) {
            $cart->calculate_totals();
            wp_send_json_success(array(
                'message' => __('Upsell products removed from cart', 'checkout-upsell'),
                'cart_total' => $cart->get_cart_total()
            ));
        } else {
            wp_send_json_error(array('message' => __('No upsell products found in cart', 'checkout-upsell')));
        }
    }

    public function ajax_check_for_upsell_products() {
        check_ajax_referer('checkout-upsell-nonce', 'nonce');
        
        $this->check_for_upsell_products();
        
        wp_send_json(array(
            'has_upsell_products' => $this->has_upsell_products,
            'button_html' => $this->replace_place_order_button()
        ));
    }

    public function ajax_check_cart_contents_handler() {
        check_ajax_referer('checkout-upsell-nonce', 'nonce');

        $has_upsell_products = false;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['upsell']) && $cart_item['upsell']) {
                $has_upsell_products = true;
                break;
            }
        }
    
        wp_send_json(array(
            'item_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'has_upsell_products' => $has_upsell_products,
        ));
    }

    public function ajax_remove_upsell_from_cart() {
        check_ajax_referer('checkout-upsell-nonce', 'nonce');
    
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    
        if ($product_id) {
            WC()->cart->remove_cart_item(WC()->cart->generate_cart_id($product_id));
            WC()->cart->calculate_totals();
    
            wp_send_json_success(array(
                'cart_total' => WC()->cart->get_cart_total(),
                'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array())
            ));
        } else {
            wp_send_json_error(array('message' => __('Invalid product ID', 'checkout-upsell')));
        }
    }
    
    /**
     * Check if a product is already in cart
     */
    public function ajax_check_product_in_cart() {
        check_ajax_referer('checkout-upsell-nonce', 'nonce');
    
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    
        if ($product_id) {
            $in_cart = false;
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product_id) {
                    $in_cart = true;
                    break;
                }
            }
    
            // If product is in cart, get alternative upsell product
            if ($in_cart) {
                $alternative_product = $this->get_alternative_upsell_product($product_id);
                if ($alternative_product) {
                    wp_send_json_success(array(
                        'in_cart' => true,
                        'alternative_product' => $alternative_product
                    ));
                    return;
                }
            }
    
            wp_send_json_success(array('in_cart' => $in_cart));
        } else {
            wp_send_json_error(array('message' => __('Invalid product ID', 'checkout-upsell')));
        }
    }

    public function check_for_upsell_products() {
        $cart_products = WC()->cart->get_cart();

        foreach ($cart_products as $cart_item) {
            $product = $cart_item['data'];
            $tags = $product->get_tag_ids();
            
            $upsell_tags = array(
                $this->get_tag_id('dog'),
                $this->get_tag_id('adult-dog'),
                $this->get_tag_id('puppy'),
                $this->get_tag_id('adult-dog-food'),
                $this->get_tag_id('cat'),
                $this->get_tag_id('adult-cat'),
                $this->get_tag_id('kitten')
            );
            
            if (count(array_intersect($tags, $upsell_tags)) > 0) {
                $this->has_upsell_products = true;
                break;
            }
        }
    }

    /**
     * Get an alternative upsell product when current one is in cart
     */
    private function get_alternative_upsell_product($current_product_id) {
        $cart_products = array();
        foreach (WC()->cart->get_cart() as $cart_item) {
            $cart_products[] = $cart_item['product_id'];
        }

        // Get current product's tags
        $product = wc_get_product($current_product_id);
        if (!$product) return null;

        $product_tags = wp_get_object_terms($current_product_id, 'product_tag', array('fields' => 'slugs'));
        
        // Query for alternative products with same tags but not in cart
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 1,
            'orderby' => 'rand',
            'post__not_in' => $cart_products,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_tag',
                    'field' => 'slug',
                    'terms' => $product_tags
                )
            ),
            'meta_query' => array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock'
                )
            )
        );

        $query = new WP_Query($args);
        if ($query->have_posts()) {
            $alternative = $query->posts[0];
            $product = wc_get_product($alternative->ID);
            
            return array(
                'id' => $alternative->ID,
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'image' => wp_get_attachment_image_src($product->get_image_id(), 'thumbnail')[0],
                'min_quantity' => $product->get_meta('minimum_allowed_quantity', true) ?: 1
            );
        }

        return null;
    }

    private function replace_place_order_button() {
        ob_start();
        ?>
        <button type="button" id="upsell-place-order" class="button alt">
            <?php esc_html_e('Place Order', 'checkout-upsell'); ?>
        </button>
        <?php
        return ob_get_clean();
    }

    public function add_upsell_popup_markup() {
        if (is_checkout()) {
            echo '<div id="upsell-popup-container"></div>';
        }
    }

    public function get_products_to_upsell($result) {
        $cart_products = WC()->cart->get_cart();
        $cart_product_ids = array();  // Array to store products already in cart
        $has_cat_product = false;
        $has_dog_product = false;

        // Get all product IDs currently in cart
        foreach ($cart_products as $cart_item) {
            $cart_product_ids[] = $cart_item['product_id'];
            $product = $cart_item['data'];
            $tags = $product->get_tag_ids();

            if (in_array($this->get_tag_id('dog'), $tags) || 
                in_array($this->get_tag_id('adult-dog'), $tags) || 
                in_array($this->get_tag_id('puppy'), $tags) || 
                in_array($this->get_tag_id('adult-dog-food'), $tags)) {
                $has_dog_product = true;
            } elseif (in_array($this->get_tag_id('cat'), $tags) || 
                     in_array($this->get_tag_id('adult-cat'), $tags) || 
                     in_array($this->get_tag_id('kitten'), $tags)) {
                $has_cat_product = true;
            }
        }
        
        // Get potential upsell products
        $dog_upsells = $this->get_filtered_upsell_products('upsell-dog', $cart_product_ids);
        $cat_upsells = $this->get_filtered_upsell_products('upsell-cat', $cart_product_ids);

        // Combine applicable upsell products
        $upsell_products = array();
        if ($has_dog_product && $has_cat_product) {
            $upsell_products = array_merge($dog_upsells, $cat_upsells);
        } elseif ($has_dog_product) {
            $upsell_products = $dog_upsells;
        } elseif ($has_cat_product) {
            $upsell_products = $cat_upsells;
        }

        // If no valid upsells found, return empty result
        if (empty($upsell_products)) {
            $result['upsell_products'] = array();
            return $result;
        }

        // Shuffle and take one random product
        shuffle($upsell_products);
        $upsell_products = array_slice($upsell_products, 0, 1);

        // Prepare upsell products for display
        $upsells_data = array();
        foreach ($upsell_products as $upsell_id) {
            $product = wc_get_product($upsell_id);
            if (!$product) continue;

            $min_quantity = $product->get_meta('minimum_allowed_quantity', true);
            $min_quantity = !empty($min_quantity) ? intval($min_quantity) : 1;
            
            $upsells_data[] = array(
                'id' => $upsell_id,
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'image' => wp_get_attachment_image_src($product->get_image_id(), 'thumbnail')[0],
                'add_to_cart_url' => $product->add_to_cart_url(),
                'min_quantity' => $min_quantity,
            );
        }

        $result['upsell_products'] = $upsells_data;
        return $result;
    }

        /**
     * Get filtered upsell products excluding those already in cart
     * 
     * @param string $tag_slugs Tag slugs to query
     * @param array $exclude_ids Product IDs to exclude
     * @return array
     */
    private function get_filtered_upsell_products($tag_slugs, $exclude_ids = array()) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 5, // Increased to ensure we have options after filtering
            'post__not_in' => $exclude_ids, // Exclude products already in cart
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_tag',
                    'field' => 'slug',
                    'terms' => $tag_slugs,
                )
            ),
            'meta_query' => array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock'
                )
            )
        );
        
        $query = new WP_Query($args);
        return wp_list_pluck($query->posts, 'ID');
    }

    private function get_upsell_products($tag_slugs) {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_tag',
                    'field' => 'slug',
                    'terms' => $tag_slugs,
                )
            )
        );
        $query = new WP_Query($args);
        return wp_list_pluck($query->posts, 'ID');
    }

    public function call_get_upsell_products($tag_slugs) {
        return $this->get_upsell_products($tag_slugs);
    }

    private function get_tag_id($slug) {
        $term = get_term_by('slug', $slug, 'product_tag');
        return $term ? $term->term_id : 0;
    }

    public function recalculate_cart_total($cart_item_key, $product_id, $quantity) {
        WC()->cart->calculate_totals();
    }

    private function get_manual_upsell_prices() {
        return get_option('manual_upsell_prices', array());
    }

    public function apply_upsell_discount($cart) {
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['upsell'])) {
                $product_id = $cart_item['product_id'];
                $original_price = $cart_item['data']->get_price();

                if (isset($this->manual_upsell_prices[$product_id])) {
                    $upsell_price = $this->manual_upsell_prices[$product_id];
                    $discount = $original_price - $upsell_price;

                    if ($discount > 0) {
                        $cart->add_fee(
                            sprintf(__('One-time special discount %s', 'checkout-upsell'), $cart_item['data']->get_name()),
                            -$discount
                        );
                    }
                } else {
                    $discount = $original_price * 0.05;  // 5% discount
                    $cart->add_fee(
                        sprintf(__('%s Discount', 'checkout-upsell'), $cart_item['data']->get_name()),
                        -$discount
                    );
                }
            }
        }
    }

    public function maybe_clear_upsell_products() {
        // Check if we're NOT on the order-pay page
        if (!is_wc_endpoint_url('order-pay')) {
            $referer = wp_get_referer();
            // If the referrer contains order-pay, clear ipsell products
            if ($referer && strpos($referer, '/checkout/order-pay') !== false) {
                $this->remove_all_upsell_products();
                return;
            }
        }

        // Check fr common payment gateway return parameter
        $payment_return_params = array(
            'cancel_order',
            'cancelled',
            'payment_failed',
            'failed',
            'wc-api'
        );

        foreach ($payment_return_params as $param) {
            if (isset($_GET[$param])) {
                $this->remove_all_upsell_products();
                break;
            }
        }
    }

    private function remove_all_upsell_products() {
        if (!WC()->cart) {
            return;
        }
    
        $cart = WC()->cart;
        $items_removed = false;
    
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['upsell']) && $cart_item['upsell']) {
                $cart->remove_cart_item($cart_item_key);
                $items_removed = true;
            }
        }
    
        if ($items_removed) {
            $cart->calculate_totals();
        }
    }

    // Track which type of cart troggered this upsell and increment the counter
    public function track_cart_types($product_id, $impressions) {
        $cart_items = WC()->cart->get_cart();
        $found_dog = false;
        $found_cat = false;

        // First check if there are any dog or cat products in the cart
        foreach ($cart_items as $cart_item) {
            $cart_product_id = $cart_item['product_id'];
            $product_tags = wp_get_post_terms($cart_product_id, 'product_tag');

            foreach ($product_tags as $tag) {
                $tag_name = strtolower($tag->name);
                if (in_array($tag_name, array('dog', 'adult-dog', 'puppy'))) {
                    $found_dog = true;
                }
                
                if (in_array($tag_name, array('cat', 'adult-cat', 'kitten'))) {
                    $found_cat = true;
                }

                // If we found both types, no need to continue checking
                if ($found_dog && $found_cat) {
                    break 2;
                }
            }
        }

        // Initialize cart_types if not set
        if (!isset($impressions[$product_id]['cart_types'])) {
            $impressions[$product_id]['cart_types'] = array(
                'dog' => 0,
                'cat' => 0
            );
        }

        // Increment countes based on what was found
        if ($found_dog) {
            $impressions[$product_id]['cart_types']['dog']++;
        }

        if ($found_cat) {
            $impressions[$product_id]['cart_types']['cat']++;
        }

        return $impressions;
    }

    public function track_upsell_impression($product_id, $is_popup_show = true) {
        $current_timestamp = current_time('timestamp');
        $upsell_impression = get_option('checkout_upsell_impressions', array());

        // Get the specific upsell product ID that was shown in the popup
        // $shown_upsell_id = isset($_POST['upsell_product_id']) ? intval($_POST['upsell_product_id']) : 0;

        if ($product_id) {
            if (!isset($upsell_impression[$product_id])) {
                $upsell_impression[$product_id] = array(
                    'total_shown' => 0,
                    'total_purchased' => 0,
                    'show_timestamps' => [],
                    'purchase_timestamps' => [],
                    'purchase_data' => [],
                    'cart_types' => array(
                        'dog' => 0,
                        'cat' => 0
                    )
                );
            }

            // Increment the counter for this specific upsell product
            // Only track show timestaps it it'd a popup sjow
            if ($is_popup_show) {
                $upsell_impression[$product_id]['total_shown']++;
                $upsell_impression[$product_id]['show_timestamps'][] = $current_timestamp;
    
                // Kepp only the last 100 impressions for historical data
                $upsell_impression[$product_id]['show_timestamps'] = array_slice(
                    $upsell_impression[$product_id]['show_timestamps'], 
                    -100
                );
            }

            // Track which type of cart triggered this upsell
            $upsell_impression = $this->track_cart_types($product_id, $upsell_impression);

            update_option('checkout_upsell_impressions', $upsell_impression);
        }
    }

    // Method to transfer cart item data to order
    public function add_upsell_cart_item_to_order($item, $cart_item_key, $values, $order) {
        if (isset($values['upsell_data'])) {
            $item->add_meta_data('_is_upsell', true);
            $item->add_meta_data('_added_from_popup', $values['upsell_data']['added_from_popup']);
            $item->add_meta_data('_original_cart_total', $values['upsell_data']['original_cart_total']);
        }
    }
}

new Checkout_Upsell_Public();