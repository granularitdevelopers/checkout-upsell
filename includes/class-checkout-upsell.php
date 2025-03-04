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
    private $loader;

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
        $this->init_hooks();
        $this->set_locale();
    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    private function load_dependencies() {
        require_once CHECKOUT_UPSELL_PLUGIN_DIR . 'includes/class-checkout-upsell-loader.php';
        require_once CHECKOUT_UPSELL_PLUGIN_DIR . 'includes/class-checkout-upsell-i18n.php';
        require_once CHECKOUT_UPSELL_PLUGIN_DIR . 'public/class-checkout-upsell-public.php';
        require_once CHECKOUT_UPSELL_PLUGIN_DIR . 'admin/class-checkout-upsell-admin.php';

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
     * Hook into actions and filters.
     *
     * @since 1.3
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'), 0);
    }

    /**
     * Init Checkout_Upsell when WordPress Initialises.
     */
    public function init() {
        // Init action.
        do_action('checkout_upsell_init');
    }

    /**
     * What type of request is this?
     *
     * @param  string $type admin, ajax, cron or frontend.
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
     * Get the plugin url.
     *
     * @return string
     */
    public function plugin_url() {
        return untrailingslashit(plugins_url('/', CHECKOUT_UPSELL_PLUGIN_DIR . '/checkout-upsell.php'));
    }

    /**
     * Get the plugin path.
     *
     * @return string
     */
    public function plugin_path() {
        return untrailingslashit(plugin_dir_path(CHECKOUT_UPSELL_PLUGIN_DIR . '/checkout-upsell.php'));
    }

    /**
     * Check if the plugin is enabled.
     *
     * @return bool
     */
    public function is_enabled() {
        return 'yes' === get_option('checkout_upsell_enabled', 'yes');
    }
}