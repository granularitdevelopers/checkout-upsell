<?php
/**
 * Template for displaying upsell products popup
 *
 * @package Checkout Upsell
 */

defined('ABSPATH') || exit;

// Ensure we have the required variables
if (!isset($args) || !isset($args['upsell_products']) || !is_array($args['upsell_products'])) {
    error_log('Checkout Upsell: Missing or invalid upsell products data');
    return;
}

$upsell_products = $args['upsell_products'];

// Verify we have products
if (empty($upsell_products)) {
    error_log('Checkout Upsell: No upsell products available');
    return;
}

$cart_total = (WC()->cart && WC()->cart->get_cart_contents_count() > 0) 
    ? WC()->cart->get_cart_contents_total() 
    : 0;
    $manual_upsell_prices = isset($args['manual_upsell_prices']) ? $args['manual_upsell_prices'] : array();

// Get one random product from the array
$random_index = array_rand($upsell_products, 1);
$current_product = $upsell_products[$random_index];

// Verify we have a valid product
if (empty($current_product) || empty($current_product['id'])) {
    error_log('Checkout Upsell: Invalid product data');
    return;
}

// Add timer for the pop up
$timer_duration = 30;
?>

<div class="upsell-popup-wrapper" data-timer-duration="<?php echo esc_attr($timer_duration); ?>">
    <div class="upsell-popup">
            <div class="timer-container">
                <div class="countdown-timer">
                    <span class="seconds">30</span>
                </div>
            </div>
            <h3 class="popup-header-txt"><?php esc_html_e('Limited Time Offer!', 'checkout-upsell'); ?></h3>
        <div class="upsell-product-list">
                <div class="upsell-product">
                    <div class="card-item">
                        <div class="upsell-product-img">
                            <img src="<?php echo esc_url($current_product['image']); ?>" alt="<?php echo esc_attr($current_product['name']); ?>">
                        </div>
                        <h2 class="product-title">
                            <?php echo esc_html($current_product['name']); ?>
                        </h2>
                        <div class="product-price">
                            <?php 
                            $product_obj = wc_get_product($current_product['id']);
                            $regular_price = $product_obj->get_regular_price();
                            $sale_price = isset($manual_upsell_prices[$current_product['id']]) 
                                ? $manual_upsell_prices[$current_product['id']] 
                                : $current_product['price'];
                            
                            if ($regular_price > $sale_price) : ?>
                                <span class="original-price"><?php echo wc_price($regular_price); ?></span>
                                <span class="sale-price"><?php echo wc_price($sale_price); ?></span>
                            <?php else : ?>
                                <span class="regular-price"><?php echo wc_price($sale_price); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
        </div>
        <div class="upsell-actions">
            <button type="button" class="place-order-upsell" data-product-id="<?php echo esc_attr($current_product['id']); ?>" data-quantity="<?php echo esc_attr($current_product['min_quantity']); ?>">
                <?php esc_html_e('Add to Cart & Checkout', 'checkout-upsell'); ?>
            </button>
            <button type="button" class="decline-upsell">
                <?php esc_html_e('Do Not Add to Cart & Checkout', 'checkout-upsell'); ?>
            </button>
        </div>
    </div>
</div>