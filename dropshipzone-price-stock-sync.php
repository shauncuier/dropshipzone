<?php
/**
 * Plugin Name: Dropshipzone Price & Stock Sync
 * Plugin URI: https://dropshipzone.com.au
 * Description: Syncs product prices and stock levels from Dropshipzone API to WooCommerce using SKU matching.
 * Version: 2.0.5
 * Author: 3s-Soft
 * Author URI: https://3s-soft.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dropshipzone-sync
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.4
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('DSZ_SYNC_VERSION', '2.0.5');
define('DSZ_SYNC_PLUGIN_FILE', __FILE__);
define('DSZ_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DSZ_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DSZ_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
final class Dropshipzone_Sync {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $api_client;
    public $price_sync;
    public $stock_sync;
    public $cron;
    public $admin_ui;
    public $logger;
    public $product_mapper;
    public $product_importer;

    /**
     * Get single instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_requirements();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        // Check if WooCommerce is active
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Dropshipzone Price & Stock Sync requires WooCommerce to be installed and active.', 'dropshipzone-sync'); ?></p>
        </div>
        <?php
    }

    /**
     * Include required files
     */
    private function includes() {
        // Helpers
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/helpers.php';
        
        // Core classes
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-logger.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-rate-limiter.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-price-sync.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-stock-sync.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-cron.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-product-mapper.php';
        require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-product-importer.php';
        
        // Admin UI
        if (is_admin()) {
            require_once DSZ_SYNC_PLUGIN_DIR . 'includes/class-admin-ui.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(DSZ_SYNC_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(DSZ_SYNC_PLUGIN_FILE, [$this, 'deactivate']);

        // Initialize components after plugins loaded
        add_action('plugins_loaded', [$this, 'init_components'], 20);
        
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);
        
        // Declare HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);

        // Clean up mapping when product is deleted
        add_action('deleted_post', [$this, 'cleanup_product_mapping'], 10, 2);
    }

    /**
     * Cleanup mapping when a product is deleted
     * 
     * @param int      $post_id Post ID
     * @param \WP_Post $post    Post object
     */
    public function cleanup_product_mapping($post_id, $post) {
        if ($post->post_type !== 'product' && $post->post_type !== 'product_variation') {
            return;
        }

        if ($this->product_mapper) {
            $this->product_mapper->unmap($post_id);
        }
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Check WooCommerce again
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Initialize components
        $this->logger = new Logger();
        $this->api_client = new API_Client($this->logger);
        $this->price_sync = new Price_Sync($this->api_client, $this->logger);
        $this->stock_sync = new Stock_Sync($this->api_client, $this->logger);
        $this->cron = new Cron($this->price_sync, $this->stock_sync, $this->logger);
        $this->product_mapper = new Product_Mapper($this->logger);
        $this->product_importer = new Product_Importer($this->api_client, $this->price_sync, $this->stock_sync, $this->product_mapper, $this->logger);
        
        // Ensure mapping table exists (for upgrades from older versions)
        $this->maybe_create_mapping_table();
        
        if (is_admin()) {
            $this->admin_ui = new Admin_UI($this->api_client, $this->price_sync, $this->stock_sync, $this->cron, $this->logger, $this->product_mapper, $this->product_importer);
        }
    }

    /**
     * Create mapping table if it doesn't exist
     */
    private function maybe_create_mapping_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dsz_product_mapping';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ));
        
        if (!$table_exists) {
            Product_Mapper::create_table();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create log table
        $this->create_log_table();
        
        // Create mapping table
        Product_Mapper::create_table();
        
        // Create sync status option
        $this->create_default_options();
        
        // Schedule cron
        if (!wp_next_scheduled('dsz_sync_cron_hook')) {
            wp_schedule_event(time(), 'hourly', 'dsz_sync_cron_hook');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron
        wp_clear_scheduled_hook('dsz_sync_cron_hook');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create log table
     */
    private function create_log_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'dsz_sync_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create default options
     */
    private function create_default_options() {
        // API Settings
        if (!get_option('dsz_sync_api_email')) {
            add_option('dsz_sync_api_email', '');
        }
        if (!get_option('dsz_sync_api_password')) {
            add_option('dsz_sync_api_password', '');
        }
        if (!get_option('dsz_sync_api_token')) {
            add_option('dsz_sync_api_token', '');
        }
        if (!get_option('dsz_sync_token_expiry')) {
            add_option('dsz_sync_token_expiry', 0);
        }

        // Price Rules
        $price_defaults = [
            'markup_type' => 'percentage', // percentage or fixed
            'markup_value' => 30,
            'rounding_enabled' => true,
            'rounding_type' => '99', // 99, 95, nearest
            'gst_enabled' => true,
            'gst_type' => 'include', // include or exclude
        ];
        if (!get_option('dsz_sync_price_rules')) {
            add_option('dsz_sync_price_rules', $price_defaults);
        }

        // Stock Rules
        $stock_defaults = [
            'buffer_enabled' => false,
            'buffer_amount' => 0,
            'zero_on_unavailable' => true,
            'auto_out_of_stock' => true,
        ];
        if (!get_option('dsz_sync_stock_rules')) {
            add_option('dsz_sync_stock_rules', $stock_defaults);
        }

        // Sync Settings
        $sync_defaults = [
            'frequency' => 'hourly', // hourly, twicedaily, daily
            'batch_size' => 100,
            'last_sync' => null,
            'sync_in_progress' => false,
            'current_offset' => 0,
            'products_updated' => 0,
            'errors_count' => 0,
        ];
        if (!get_option('dsz_sync_settings')) {
            add_option('dsz_sync_settings', $sync_defaults);
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('dropshipzone-sync', false, dirname(DSZ_SYNC_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DSZ_SYNC_PLUGIN_FILE, true);
        }
    }
}

/**
 * Initialize the plugin
 */
function dsz_sync() {
    return Dropshipzone_Sync::instance();
}

// Start the plugin
dsz_sync();
