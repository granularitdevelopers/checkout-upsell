<?php
/*
 * Plugin Name: Checkout Upsell
 * Description: Display Upsell offers to clients at Checkout
 * Version: 1.3
 * Author: Teddy Waweru and Jefferson Gakuya
 * Text Domain: checkout-upsell
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Checkout Upsell
 */

defined('ABSPATH') || exit;

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
