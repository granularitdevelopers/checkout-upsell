<?php
/*
 * Plugin Name: Checkout Upsell
 * Description: Display Upsell offers to clients at Checkout
 * Version: 1.3
 * Author: Teddy Waweru, Jefferson Gakuya and Alex Kwoba
 * Text Domain: checkout-upsell
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Checkout Upsell
 */

defined('ABSPATH') || exit;

// Function to check if the user has a valid license
if (!function_exists('is_premium_user')) {
    function is_premium_user() {
        $license_key = get_option('checkout_upsell_license_key', '');
        if (empty($license_key)) {
            return false;
        }

        // Static check for now
        return $license_key === 'your-valid-license-key'; // Replace with actual logic
    }
}

// Register settings for license key
add_action('admin_init', function() {
    register_setting('checkout_upsell_license_settings', 'checkout_upsell_license_key', array(
        'sanitize_callback' => 'sanitize_text_field'
    ));
});

// Enqueue Chart.js for the admin page
add_action('admin_enqueue_scripts', function($hook) {
    // Ensure this runs only on the Checkout Upsell admin page
    if ($hook === 'toplevel_page_checkout-upsell') {
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    }
});

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Define plugin constants
define('CHECKOUT_UPSELL_VERSION', '1.3');
define('CHECKOUT_UPSELL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CHECKOUT_UPSELL_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include the main Checkout_Upsell class
require_once CHECKOUT_UPSELL_PLUGIN_DIR . 'includes/class-checkout-upsell.php';

/**
 * Main instance of Checkout_Upsell.
 * 
 * Returns the main instance of Checkout_Upsell to prevent the need to use globals
 * 
 * @since 1.3
 * @return Checkout_Upsell
 */
function checkout_upsell() {
    return Checkout_Upsell::instance();
}

$GLOBALS['checkout_upsell'] = checkout_upsell();