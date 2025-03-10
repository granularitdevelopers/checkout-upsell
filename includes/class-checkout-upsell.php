<?php
if (!defined('ABSPATH')) {
    exit;
}

class Checkout_Upsell {
    /**
     * The single instance of the class.
     *
     * @var Checkout_Upsell
     * @since 1.3
     */
    protected static $_instance = null;

    /**
     * Instances of loader, admin, and public classes.
     *
     * @var Checkout_Upsell_Loader
     * @var Checkout_Upsell_Admin
     * @var Checkout_Upsell_Public
     */
    private $loader;
    private $admin_instance;
    private $public_instance;

    /**
     * Main Checkout_Upsell Instance.
     *
     * Ensures only one instance of Checkout_Upsell is loaded or can be loaded.
     *
     * @since 1.3
     * @static
     * @return Checkout_Upsell - Main instance.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Checkout_Upsell Constructor.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->set_locale();
    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    private function load_dependencies() {
        // Ensure all required files exist before including
        $required_files = [
            CHECKOUT_UPSELL_PLUGIN_DIR . 'includes/class-checkout-upsell-loader.php',
            CHECKOUT_UPSELL_PLUGIN_DIR . 'includes/class-checkout-upsell-i18n.php',
            CHECKOUT_UPSELL_PLUGIN_DIR . 'public/class-checkout-upsell-public.php',
            CHECKOUT_UPSELL_PLUGIN_DIR . 'admin/class-checkout-upsell-admin.php',
        ];

        foreach ($required_files as $file) {
            if (file_exists($file)) {
                require_once $file;
            } else {
                trigger_error("Required file $file not found.", E_USER_WARNING);
            }
        }

        $this->loader = new Checkout_Upsell_Loader();
    }

    /**
     * Localize plugin textdomain
     */
    private function set_locale() {
        $plugin_i18n = new Checkout_Upsell_i18n();
        $this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all hooks for the admin area.
     */
    private function define_admin_hooks() {
        if ($this->is_request('admin')) {
            $this->admin_instance = new Checkout_Upsell_Admin();
            $this->loader->add_action('admin_init', $this->admin_instance, 'init');
            $this->loader->add_action('wp_ajax_add_upsell_product', $this->admin_instance, 'ajax_add_upsell_product');
            $this->loader->add_action('wp_ajax_remove_upsell_product', $this->admin_instance, 'ajax_remove_upsell_product');
            $this->loader->add_action('woocommerce_order_status_completed', $this->admin_instance, 'track_upsell_purchase');
        }
    }

    /**
     * Register all hooks for the public area.
     */
    private function define_public_hooks() {
        if ($this->is_request('frontend')) {
            $this->public_instance = new Checkout_Upsell_Public();
            $this->loader->add_action('woocommerce_after_checkout_form', $this->public_instance, 'display_upsell_offers');
        }
    }

    /**
     * Hook into actions and filters.
     *
     * @since 1.3
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
        $this->loader->run(); // Run the loader to register all hooks
    }

    /**
     * Init Checkout_Upsell when WordPress Initialises.
     */
    public function init() {
        // Init action
        do_action('checkout_upsell_init');

        // Enable plugin only if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return;
        }
    }

    /**
     * What type of request is this?
     *
     * @param string $type admin, ajax, cron, or frontend.
     * @return bool
     */
    private function is_request($type) {
        switch ($type) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined('DOING_AJAX');
            case 'cron':
                return defined('DOING_CRON');
            case 'frontend':
                return (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON');
        }
    }

    /**
     * Get the plugin URL.
     *
     * @return string
     */
    public function plugin_url() {
        return untrailingslashit(plugins_url('/', __FILE__));
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path() {
        return untrailingslashit(plugin_dir_path(__FILE__));
    }

    /**
     * Check if the plugin is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return 'yes' === get_option('checkout_upsell_enabled', 'yes');
    }

    /**
     * Get the admin instance (for testing or external use).
     *
     * @return Checkout_Upsell_Admin|null
     */
    public function get_admin_instance() {
        return $this->admin_instance;
    }

    /**
     * Get the public instance (for testing or external use).
     *
     * @return Checkout_Upsell_Public|null
     */
    public function get_public_instance() {
        return $this->public_instance;
    }
}

// Initialize the plugin
if (!function_exists('checkout_upsell')) {
    function checkout_upsell() {
        return Checkout_Upsell::instance();
    }
}

$GLOBALS['checkout_upsell'] = checkout_upsell();