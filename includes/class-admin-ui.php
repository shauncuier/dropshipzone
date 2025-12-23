<?php
/**
 * Admin UI Class
 *
 * Handles all admin interface elements
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin UI Manager
 */
class Admin_UI {

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Price Sync instance
     */
    private $price_sync;

    /**
     * Stock Sync instance
     */
    private $stock_sync;

    /**
     * Cron instance
     */
    private $cron;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Product Mapper instance
     */
    private $product_mapper;

    /**
     * Product Importer instance
     * 
     * @var Product_Importer
     */
    private $product_importer;

    /**
     * Constructor
     */
    public function __construct(API_Client $api_client, Price_Sync $price_sync, Stock_Sync $stock_sync, Cron $cron, Logger $logger, Product_Mapper $product_mapper = null, Product_Importer $product_importer = null) {
        $this->api_client = $api_client;
        $this->price_sync = $price_sync;
        $this->stock_sync = $stock_sync;
        $this->cron = $cron;
        $this->logger = $logger;
        $this->product_mapper = $product_mapper;
        $this->product_importer = $product_importer;

        // Admin hooks
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);

        // AJAX handlers
        add_action('wp_ajax_dsz_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_dsz_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_dsz_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_dsz_get_sync_status', [$this, 'ajax_get_sync_status']);
        add_action('wp_ajax_dsz_continue_sync', [$this, 'ajax_continue_sync']);
        add_action('wp_ajax_dsz_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_dsz_export_logs', [$this, 'ajax_export_logs']);
        
        // Mapping AJAX handlers
        add_action('wp_ajax_dsz_search_wc_products', [$this, 'ajax_search_wc_products']);
        add_action('wp_ajax_dsz_search_dsz_products', [$this, 'ajax_search_dsz_products']);
        add_action('wp_ajax_dsz_map_product', [$this, 'ajax_map_product']);
        add_action('wp_ajax_dsz_unmap_product', [$this, 'ajax_unmap_product']);
        add_action('wp_ajax_dsz_auto_map', [$this, 'ajax_auto_map']);
        
        // Import AJAX handlers
        add_action('wp_ajax_dsz_search_api_products', [$this, 'ajax_search_api_products']);
        add_action('wp_ajax_dsz_import_product', [$this, 'ajax_import_product']);
        add_action('wp_ajax_dsz_resync_product', [$this, 'ajax_resync_product']);
        add_action('wp_ajax_dsz_resync_all', [$this, 'ajax_resync_all']);
    }

    /**
     * Register admin menu
     */
    public function register_menu() {
        // Main menu
        add_menu_page(
            __('Dropshipzone Sync', 'dropshipzone-sync'),
            __('DSZ Sync', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync',
            [$this, 'render_dashboard'],
            'dashicons-update',
            56
        );

        // Dashboard (same as main)
        add_submenu_page(
            'dsz-sync',
            __('Dashboard', 'dropshipzone-sync'),
            __('Dashboard', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync',
            [$this, 'render_dashboard']
        );

        // API Settings
        add_submenu_page(
            'dsz-sync',
            __('API Settings', 'dropshipzone-sync'),
            __('API Settings', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-api',
            [$this, 'render_api_settings']
        );

        // Price Rules
        add_submenu_page(
            'dsz-sync',
            __('Price Rules', 'dropshipzone-sync'),
            __('Price Rules', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-price',
            [$this, 'render_price_rules']
        );

        // Stock Rules
        add_submenu_page(
            'dsz-sync',
            __('Stock Rules', 'dropshipzone-sync'),
            __('Stock Rules', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-stock',
            [$this, 'render_stock_rules']
        );

        // Sync Control
        add_submenu_page(
            'dsz-sync',
            __('Sync Control', 'dropshipzone-sync'),
            __('Sync Control', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-control',
            [$this, 'render_sync_control']
        );

        // Logs
        add_submenu_page(
            'dsz-sync',
            __('Logs', 'dropshipzone-sync'),
            __('Logs', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-logs',
            [$this, 'render_logs']
        );

        // Product Mapping
        add_submenu_page(
            'dsz-sync',
            __('Product Mapping', 'dropshipzone-sync'),
            __('Product Mapping', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-mapping',
            [$this, 'render_mapping']
        );

        // Product Import
        add_submenu_page(
            'dsz-sync',
            __('Product Import', 'dropshipzone-sync'),
            __('Product Import', 'dropshipzone-sync'),
            'manage_woocommerce',
            'dsz-sync-import',
            [$this, 'render_import']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Only load on our pages
        if (strpos($hook, 'dsz-sync') === false) {
            return;
        }

        wp_enqueue_style(
            'dsz-admin-css',
            DSZ_SYNC_PLUGIN_URL . 'assets/admin.css',
            [],
            DSZ_SYNC_VERSION
        );

        wp_enqueue_script(
            'dsz-admin-js',
            DSZ_SYNC_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            DSZ_SYNC_VERSION,
            true
        );

        wp_localize_script('dsz-admin-js', 'dsz_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dsz_admin_nonce'),
            'strings' => [
                'testing' => __('Testing connection...', 'dropshipzone-sync'),
                'saving' => __('Saving...', 'dropshipzone-sync'),
                'syncing' => __('Syncing...', 'dropshipzone-sync'),
                'success' => __('Success!', 'dropshipzone-sync'),
                'error' => __('Error occurred', 'dropshipzone-sync'),
                'confirm_clear' => __('Are you sure you want to clear all logs?', 'dropshipzone-sync'),
            ],
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('dsz_sync_api', 'dsz_sync_api_email');
        register_setting('dsz_sync_api', 'dsz_sync_api_password');
        register_setting('dsz_sync_settings', 'dsz_sync_price_rules');
        register_setting('dsz_sync_settings', 'dsz_sync_stock_rules');
        register_setting('dsz_sync_settings', 'dsz_sync_settings');
        register_setting('dsz_sync_settings', 'dsz_sync_import_settings');
    }

    /**
     * Render page header with navigation
     */
    private function render_header($title, $subtitle = '') {
        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'dsz-sync';
        
        $nav_items = [
            'dsz-sync' => [
                'label' => __('Dashboard', 'dropshipzone-sync'),
                'icon' => 'dashicons-dashboard'
            ],
            'dsz-sync-api' => [
                'label' => __('API Settings', 'dropshipzone-sync'),
                'icon' => 'dashicons-admin-network'
            ],
            'dsz-sync-price' => [
                'label' => __('Price Rules', 'dropshipzone-sync'),
                'icon' => 'dashicons-money-alt'
            ],
            'dsz-sync-stock' => [
                'label' => __('Stock Rules', 'dropshipzone-sync'),
                'icon' => 'dashicons-archive'
            ],
            'dsz-sync-control' => [
                'label' => __('Sync Control', 'dropshipzone-sync'),
                'icon' => 'dashicons-update'
            ],
            'dsz-sync-logs' => [
                'label' => __('Logs', 'dropshipzone-sync'),
                'icon' => 'dashicons-list-view'
            ],
            'dsz-sync-mapping' => [
                'label' => __('Product Mapping', 'dropshipzone-sync'),
                'icon' => 'dashicons-admin-links'
            ],
            'dsz-sync-import' => [
                'label' => __('Product Import', 'dropshipzone-sync'),
                'icon' => 'dashicons-plus'
            ],
        ];
        ?>
        <div class="dsz-header">
            <div class="dsz-header-content">
                <h1><?php echo esc_html($title); ?></h1>
                <?php if ($subtitle): ?>
                    <p class="dsz-subtitle"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <nav class="dsz-nav">
            <?php foreach ($nav_items as $page_slug => $item): 
                $is_active = ($current_page === $page_slug);
                $url = admin_url('admin.php?page=' . $page_slug);
            ?>
                <a href="<?php echo esc_url($url); ?>" 
                   class="dsz-nav-item <?php echo $is_active ? 'dsz-nav-active' : ''; ?>"
                   title="<?php echo esc_attr($item['label']); ?>">
                    <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                    <span class="dsz-nav-label"><?php echo esc_html($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }

    /**
     * Render Dashboard page
     */
    public function render_dashboard() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        $sync_status = $this->cron->get_sync_status();
        $token_status = $this->api_client->get_token_status();
        $error_count = $this->logger->get_count('error');
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Dropshipzone Sync Dashboard', 'dropshipzone-sync')); ?>

            <div class="dsz-dashboard">
                <!-- Status Cards -->
                <div class="dsz-cards">
                    <div class="dsz-card dsz-card-status <?php echo $token_status['is_valid'] ? 'dsz-card-success' : 'dsz-card-error'; ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons <?php echo $token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php _e('API Status', 'dropshipzone-sync'); ?></h3>
                            <p class="dsz-card-value">
                                <?php echo $token_status['is_valid'] ? __('Connected', 'dropshipzone-sync') : __('Not Connected', 'dropshipzone-sync'); ?>
                            </p>
                            <?php if ($token_status['is_valid'] && $token_status['expires_in'] > 0): ?>
                                <p class="dsz-card-meta"><?php printf(__('Expires in %s', 'dropshipzone-sync'), human_time_diff(time(), time() + $token_status['expires_in'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dsz-card">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php _e('Last Sync', 'dropshipzone-sync'); ?></h3>
                            <p class="dsz-card-value">
                                <?php echo $sync_status['last_sync'] ? dsz_time_ago($sync_status['last_sync']) : __('Never', 'dropshipzone-sync'); ?>
                            </p>
                            <?php if ($sync_status['next_scheduled']): ?>
                                <p class="dsz-card-meta"><?php printf(__('Next: %s', 'dropshipzone-sync'), dsz_format_datetime($sync_status['next_scheduled'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dsz-card">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php _e('Products Updated', 'dropshipzone-sync'); ?></h3>
                            <p class="dsz-card-value"><?php echo intval($sync_status['last_products_updated']); ?></p>
                            <p class="dsz-card-meta"><?php _e('Last sync run', 'dropshipzone-sync'); ?></p>
                        </div>
                    </div>

                    <div class="dsz-card <?php echo $error_count > 0 ? 'dsz-card-warning' : ''; ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-flag"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php _e('Errors', 'dropshipzone-sync'); ?></h3>
                            <p class="dsz-card-value"><?php echo intval($error_count); ?></p>
                            <p class="dsz-card-meta">
                                <a href="<?php echo admin_url('admin.php?page=dsz-sync-logs&level=error'); ?>"><?php _e('View Logs', 'dropshipzone-sync'); ?></a>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dsz-section">
                    <h2><?php _e('Quick Actions', 'dropshipzone-sync'); ?></h2>
                    <div class="dsz-quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-api'); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php _e('Configure API', 'dropshipzone-sync'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-control'); ?>" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Run Sync Now', 'dropshipzone-sync'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-price'); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-money-alt"></span>
                            <?php _e('Price Rules', 'dropshipzone-sync'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-stock'); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-archive"></span>
                            <?php _e('Stock Rules', 'dropshipzone-sync'); ?>
                        </a>
                    </div>
                </div>

                <!-- Sync Status (if in progress) -->
                <?php if ($sync_status['in_progress']): ?>
                <div class="dsz-section dsz-sync-progress-section">
                    <h2><?php _e('Sync in Progress', 'dropshipzone-sync'); ?></h2>
                    <div class="dsz-progress-wrapper">
                        <div class="dsz-progress-bar">
                            <div class="dsz-progress-fill" style="width: <?php echo $this->cron->get_progress(); ?>%"></div>
                        </div>
                        <p class="dsz-progress-text">
                            <?php printf(__('Processing... %d%%', 'dropshipzone-sync'), $this->cron->get_progress()); ?>
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render API Settings page
     */
    public function render_api_settings() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        $email = get_option('dsz_sync_api_email', '');
        $token_status = $this->api_client->get_token_status();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('API Settings', 'dropshipzone-sync'), __('Configure your Dropshipzone API credentials', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <form id="dsz-api-form" class="dsz-form">
                    <?php wp_nonce_field('dsz_api_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php _e('API Credentials', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dsz_api_email"><?php _e('API Email', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="dsz_api_email" name="dsz_api_email" value="<?php echo esc_attr($email); ?>" class="regular-text" />
                                    <p class="description"><?php _e('Your Dropshipzone account email', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dsz_api_password"><?php _e('API Password', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="dsz_api_password" name="dsz_api_password" value="" class="regular-text" placeholder="<?php echo $email ? '••••••••' : ''; ?>" />
                                    <p class="description"><?php _e('Your Dropshipzone account password (stored securely)', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <div class="dsz-form-actions">
                            <button type="button" id="dsz-test-connection" class="button button-secondary">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php _e('Test Connection', 'dropshipzone-sync'); ?>
                            </button>
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php _e('Save Settings', 'dropshipzone-sync'); ?>
                            </button>
                        </div>

                        <div id="dsz-api-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Import Settings -->
                    <div class="dsz-form-section">
                        <h2><?php _e('Import Settings', 'dropshipzone-sync'); ?></h2>
                        <?php 
                        $import_settings = get_option('dsz_sync_import_settings', ['default_status' => 'publish']);
                        $default_status = isset($import_settings['default_status']) ? $import_settings['default_status'] : 'publish';
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dsz_import_status"><?php _e('Default Product Status', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="dsz_import_status" name="dsz_import_status" class="dsz-import-status-select">
                                        <option value="publish" <?php selected($default_status, 'publish'); ?>><?php _e('Published', 'dropshipzone-sync'); ?></option>
                                        <option value="draft" <?php selected($default_status, 'draft'); ?>><?php _e('Draft', 'dropshipzone-sync'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('New products will be created with this status.', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Token Status -->
                    <div class="dsz-form-section">
                        <h2><?php _e('Connection Status', 'dropshipzone-sync'); ?></h2>
                        <div class="dsz-status-box <?php echo $token_status['is_valid'] ? 'dsz-status-success' : 'dsz-status-warning'; ?>">
                            <span class="dashicons <?php echo $token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                            <div>
                                <strong><?php echo $token_status['is_valid'] ? __('Connected', 'dropshipzone-sync') : __('Not Connected', 'dropshipzone-sync'); ?></strong>
                                <?php if ($token_status['is_valid']): ?>
                                    <p><?php printf(__('Token expires: %s', 'dropshipzone-sync'), dsz_format_datetime($token_status['expires_at'])); ?></p>
                                <?php else: ?>
                                    <p><?php _e('Please enter your credentials and test the connection.', 'dropshipzone-sync'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Price Rules page
     */
    public function render_price_rules() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        $rules = $this->price_sync->get_rules();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Price Rules', 'dropshipzone-sync'), __('Configure how prices are calculated from supplier cost', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <form id="dsz-price-form" class="dsz-form" data-type="price_rules">
                    <?php wp_nonce_field('dsz_price_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php _e('Markup Settings', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Markup Type', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="markup_type" value="percentage" <?php checked($rules['markup_type'], 'percentage'); ?> />
                                        <?php _e('Percentage', 'dropshipzone-sync'); ?>
                                    </label>
                                    <label style="margin-left: 20px;">
                                        <input type="radio" name="markup_type" value="fixed" <?php checked($rules['markup_type'], 'fixed'); ?> />
                                        <?php _e('Fixed Amount', 'dropshipzone-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="markup_value"><?php _e('Markup Value', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="markup_value" name="markup_value" value="<?php echo esc_attr($rules['markup_value']); ?>" step="0.01" min="0" class="small-text" />
                                    <span class="dsz-markup-symbol">%</span>
                                    <p class="description"><?php _e('Enter percentage (e.g., 30 for 30%) or fixed amount (e.g., 15 for $15)', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php _e('GST Settings', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Apply GST', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gst_enabled" value="1" <?php checked($rules['gst_enabled'], true); ?> />
                                        <?php _e('Enable GST calculation (10%)', 'dropshipzone-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('GST Mode', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="gst_type" value="include" <?php checked($rules['gst_type'], 'include'); ?> />
                                        <?php _e('Supplier price already includes GST', 'dropshipzone-sync'); ?>
                                    </label><br/>
                                    <label>
                                        <input type="radio" name="gst_type" value="exclude" <?php checked($rules['gst_type'], 'exclude'); ?> />
                                        <?php _e('Add GST to calculated price', 'dropshipzone-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php _e('Price Rounding', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Rounding', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rounding_enabled" value="1" <?php checked($rules['rounding_enabled'], true); ?> />
                                        <?php _e('Round prices for cleaner display', 'dropshipzone-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Rounding Style', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <select name="rounding_type">
                                        <option value="99" <?php selected($rules['rounding_type'], '99'); ?>><?php _e('.99 (e.g., $29.99)', 'dropshipzone-sync'); ?></option>
                                        <option value="95" <?php selected($rules['rounding_type'], '95'); ?>><?php _e('.95 (e.g., $29.95)', 'dropshipzone-sync'); ?></option>
                                        <option value="nearest" <?php selected($rules['rounding_type'], 'nearest'); ?>><?php _e('Nearest dollar (e.g., $30)', 'dropshipzone-sync'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Price Preview -->
                    <div class="dsz-form-section dsz-preview-section">
                        <h2><?php _e('Price Preview', 'dropshipzone-sync'); ?></h2>
                        <div class="dsz-price-preview">
                            <div class="dsz-preview-input">
                                <label for="preview_price"><?php _e('Supplier Price:', 'dropshipzone-sync'); ?></label>
                                <input type="number" id="preview_price" value="100" step="0.01" min="0" />
                            </div>
                            <div class="dsz-preview-result">
                                <span class="dsz-preview-arrow">→</span>
                                <div class="dsz-preview-final">
                                    <label><?php _e('Final Price:', 'dropshipzone-sync'); ?></label>
                                    <strong id="calculated_price">$0.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save Price Rules', 'dropshipzone-sync'); ?>
                        </button>
                    </div>

                    <div id="dsz-price-message" class="dsz-message hidden"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Stock Rules page
     */
    public function render_stock_rules() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        $rules = $this->stock_sync->get_rules();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Stock Rules', 'dropshipzone-sync'), __('Configure how stock quantities are synced', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <form id="dsz-stock-form" class="dsz-form" data-type="stock_rules">
                    <?php wp_nonce_field('dsz_stock_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php _e('Stock Buffer', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Stock Buffer', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="buffer_enabled" value="1" <?php checked($rules['buffer_enabled'], true); ?> />
                                        <?php _e('Subtract a buffer amount from supplier stock', 'dropshipzone-sync'); ?>
                                    </label>
                                    <p class="description"><?php _e('Useful to prevent overselling', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="buffer_amount"><?php _e('Buffer Amount', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="buffer_amount" name="buffer_amount" value="<?php echo esc_attr($rules['buffer_amount']); ?>" min="0" step="1" class="small-text" />
                                    <p class="description"><?php _e('Number of units to subtract from supplier stock (e.g., 2 means if supplier has 10, your store shows 8)', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php _e('Out of Stock Handling', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Zero Stock on Unavailable', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="zero_on_unavailable" value="1" <?php checked($rules['zero_on_unavailable'], true); ?> />
                                        <?php _e('Set stock to 0 if product is marked unavailable by supplier', 'dropshipzone-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Auto Out of Stock', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="auto_out_of_stock" value="1" <?php checked($rules['auto_out_of_stock'], true); ?> />
                                        <?php _e('Automatically set product status to "Out of Stock" when quantity is 0', 'dropshipzone-sync'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Deactivate Missing Products', 'dropshipzone-sync'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="deactivate_if_not_found" value="1" <?php checked(isset($rules['deactivate_if_not_found']) ? $rules['deactivate_if_not_found'] : true, true); ?> />
                                        <?php _e('Set products to Draft if not found in Dropshipzone API (discontinued products)', 'dropshipzone-sync'); ?>
                                    </label>
                                    <p class="description"><?php _e('When a product SKU is no longer available in Dropshipzone, the product will be set to Draft status and stock set to 0.', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save Stock Rules', 'dropshipzone-sync'); ?>
                        </button>
                    </div>

                    <div id="dsz-stock-message" class="dsz-message hidden"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Sync Control page
     */
    public function render_sync_control() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        $sync_status = $this->cron->get_sync_status();
        $frequencies = $this->cron->get_frequencies();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Sync Control', 'dropshipzone-sync'), __('Manage sync schedule and run manual syncs', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <!-- Sync Status -->
                <div class="dsz-form-section">
                    <h2><?php _e('Current Status', 'dropshipzone-sync'); ?></h2>
                    <div id="dsz-sync-status" class="dsz-sync-status">
                        <div class="dsz-status-grid">
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Status:', 'dropshipzone-sync'); ?></span>
                                <span id="sync-status-text" class="dsz-status-value <?php echo $sync_status['in_progress'] ? 'dsz-status-active' : ''; ?>">
                                    <?php echo $sync_status['in_progress'] ? __('Syncing...', 'dropshipzone-sync') : __('Idle', 'dropshipzone-sync'); ?>
                                </span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Sync Frequency:', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value">
                                    <?php 
                                    $freq_labels = [
                                        'hourly' => __('Every Hour', 'dropshipzone-sync'),
                                        'every_six_hours' => __('Every 6 Hours', 'dropshipzone-sync'),
                                        'twicedaily' => __('Twice Daily', 'dropshipzone-sync'),
                                        'daily' => __('Daily', 'dropshipzone-sync'),
                                        'disabled' => __('Disabled', 'dropshipzone-sync'),
                                    ];
                                    echo isset($freq_labels[$sync_status['frequency']]) ? $freq_labels[$sync_status['frequency']] : ucfirst($sync_status['frequency']);
                                    ?>
                                </span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Last Sync:', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value"><?php echo $sync_status['last_sync'] ? dsz_format_datetime($sync_status['last_sync']) : __('Never', 'dropshipzone-sync'); ?></span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Next Scheduled:', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value"><?php echo $sync_status['next_scheduled'] ? dsz_format_datetime($sync_status['next_scheduled']) : __('Not scheduled', 'dropshipzone-sync'); ?></span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Products Updated (Last Run):', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value"><?php echo intval($sync_status['last_products_updated']); ?></span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Errors (Last Run):', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value <?php echo intval($sync_status['last_errors_count']) > 0 ? 'dsz-status-error' : 'dsz-status-success'; ?>">
                                    <?php echo intval($sync_status['last_errors_count']); ?>
                                </span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Batch Size:', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value"><?php echo intval($sync_status['batch_size']); ?> <?php _e('products', 'dropshipzone-sync'); ?></span>
                            </div>
                            <div class="dsz-status-item">
                                <span class="dsz-status-label"><?php _e('Total Mapped:', 'dropshipzone-sync'); ?></span>
                                <span class="dsz-status-value"><?php echo intval($this->product_mapper->get_count()); ?> <?php _e('products', 'dropshipzone-sync'); ?></span>
                            </div>
                        </div>

                        <!-- Progress bar (shown during sync) -->
                        <div id="dsz-progress-container" class="dsz-progress-wrapper <?php echo $sync_status['in_progress'] ? '' : 'hidden'; ?>">
                            <div class="dsz-progress-bar">
                                <div id="dsz-progress-fill" class="dsz-progress-fill" style="width: <?php echo $this->cron->get_progress(); ?>%"></div>
                            </div>
                            <p id="dsz-progress-text" class="dsz-progress-text">
                                <?php printf(__('Processing... %d%%', 'dropshipzone-sync'), $this->cron->get_progress()); ?>
                            </p>
                        </div>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="button" id="dsz-run-sync" class="button button-primary button-hero" <?php echo $sync_status['in_progress'] ? 'disabled' : ''; ?>>
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Run Sync Now', 'dropshipzone-sync'); ?>
                        </button>
                    </div>

                    <div id="dsz-sync-message" class="dsz-message hidden"></div>
                </div>

                <!-- Schedule Settings -->
                <form id="dsz-schedule-form" class="dsz-form" data-type="sync_settings">
                    <?php wp_nonce_field('dsz_sync_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php _e('Schedule Settings', 'dropshipzone-sync'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="frequency"><?php _e('Sync Frequency', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <select id="frequency" name="frequency">
                                        <?php foreach ($frequencies as $value => $label): ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($sync_status['frequency'], $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="batch_size"><?php _e('Batch Size', 'dropshipzone-sync'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="batch_size" name="batch_size" value="<?php echo esc_attr($sync_status['batch_size']); ?>" min="10" max="200" step="10" class="small-text" />
                                    <p class="description"><?php _e('Number of products to process per batch (10-200). Higher values are faster but use more memory.', 'dropshipzone-sync'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php _e('Save Schedule', 'dropshipzone-sync'); ?>
                        </button>
                    </div>

                    <div id="dsz-schedule-message" class="dsz-message hidden"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Logs page
     */
    public function render_logs() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        $level = isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;

        $logs = $this->logger->get_logs([
            'level' => $level,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        $total = $this->logger->get_count($level);
        $total_pages = ceil($total / $per_page);
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Sync Logs', 'dropshipzone-sync'), __('View sync activity and error logs', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <!-- Filters -->
                <div class="dsz-logs-toolbar">
                    <div class="dsz-logs-filters">
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-logs'); ?>" class="button <?php echo empty($level) ? 'button-primary' : ''; ?>">
                            <?php _e('All', 'dropshipzone-sync'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-logs&level=info'); ?>" class="button <?php echo $level === 'info' ? 'button-primary' : ''; ?>">
                            <?php _e('Info', 'dropshipzone-sync'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-logs&level=warning'); ?>" class="button <?php echo $level === 'warning' ? 'button-primary' : ''; ?>">
                            <?php _e('Warnings', 'dropshipzone-sync'); ?>
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=dsz-sync-logs&level=error'); ?>" class="button <?php echo $level === 'error' ? 'button-primary' : ''; ?>">
                            <?php _e('Errors', 'dropshipzone-sync'); ?>
                        </a>
                    </div>
                    <div class="dsz-logs-actions">
                        <button type="button" id="dsz-export-logs" class="button">
                            <span class="dashicons dashicons-download"></span>
                            <?php _e('Export CSV', 'dropshipzone-sync'); ?>
                        </button>
                        <button type="button" id="dsz-clear-logs" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span>
                            <?php _e('Clear All', 'dropshipzone-sync'); ?>
                        </button>
                    </div>
                </div>

                <!-- Logs Table -->
                <table class="wp-list-table widefat fixed striped dsz-logs-table">
                    <thead>
                        <tr>
                            <th class="column-level"><?php _e('Level', 'dropshipzone-sync'); ?></th>
                            <th class="column-message"><?php _e('Message', 'dropshipzone-sync'); ?></th>
                            <th class="column-context"><?php _e('Context', 'dropshipzone-sync'); ?></th>
                            <th class="column-date"><?php _e('Date', 'dropshipzone-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="4" class="dsz-no-logs"><?php _e('No logs found.', 'dropshipzone-sync'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="column-level"><?php echo Logger::get_level_badge($log['level']); ?></td>
                                    <td class="column-message"><?php echo esc_html($log['message']); ?></td>
                                    <td class="column-context">
                                        <?php if (!empty($log['context'])): ?>
                                            <code class="dsz-context-code"><?php echo esc_html(wp_json_encode($log['context'], JSON_PRETTY_PRINT)); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-date"><?php echo esc_html(dsz_format_datetime($log['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="dsz-pagination">
                        <?php
                        $base_url = admin_url('admin.php?page=dsz-sync-logs' . ($level ? '&level=' . $level : ''));
                        
                        if ($page > 1): ?>
                            <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="button">&laquo; <?php _e('Previous', 'dropshipzone-sync'); ?></a>
                        <?php endif; ?>
                        
                        <span class="dsz-pagination-info">
                            <?php printf(__('Page %d of %d', 'dropshipzone-sync'), $page, $total_pages); ?>
                        </span>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="button"><?php _e('Next', 'dropshipzone-sync'); ?> &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';

        // If password is empty, try to use stored password
        if (empty($password)) {
            $encrypted = get_option('dsz_sync_api_password', '');
            $password = dsz_decrypt($encrypted);
        }

        $result = $this->api_client->test_connection($email, $password);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => $result['message'],
            'products' => $result['products_available'],
        ]);
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $settings = isset($_POST['settings']) ? $_POST['settings'] : [];

        switch ($type) {
            case 'api':
                $email = isset($settings['email']) ? sanitize_email($settings['email']) : '';
                $password = isset($settings['password']) ? $settings['password'] : '';

                update_option('dsz_sync_api_email', $email);
                
                if (!empty($password)) {
                    update_option('dsz_sync_api_password', dsz_encrypt($password));
                }
                break;

            case 'price_rules':
                $rules = [
                    'markup_type' => isset($settings['markup_type']) ? sanitize_text_field($settings['markup_type']) : 'percentage',
                    'markup_value' => isset($settings['markup_value']) ? floatval($settings['markup_value']) : 30,
                    'rounding_enabled' => !empty($settings['rounding_enabled']),
                    'rounding_type' => isset($settings['rounding_type']) ? sanitize_text_field($settings['rounding_type']) : '99',
                    'gst_enabled' => !empty($settings['gst_enabled']),
                    'gst_type' => isset($settings['gst_type']) ? sanitize_text_field($settings['gst_type']) : 'include',
                ];
                update_option('dsz_sync_price_rules', $rules);
                $this->price_sync->reload_rules();
                break;

            case 'stock_rules':
                $rules = [
                    'buffer_enabled' => !empty($settings['buffer_enabled']),
                    'buffer_amount' => isset($settings['buffer_amount']) ? intval($settings['buffer_amount']) : 0,
                    'zero_on_unavailable' => !empty($settings['zero_on_unavailable']),
                    'auto_out_of_stock' => !empty($settings['auto_out_of_stock']),
                    'deactivate_if_not_found' => !empty($settings['deactivate_if_not_found']),
                ];
                update_option('dsz_sync_stock_rules', $rules);
                $this->stock_sync->reload_rules();
                break;

            case 'sync_settings':
                $current = get_option('dsz_sync_settings', []);
                $current['frequency'] = isset($settings['frequency']) ? sanitize_text_field($settings['frequency']) : 'hourly';
                $current['batch_size'] = isset($settings['batch_size']) ? max(10, min(200, intval($settings['batch_size']))) : 100;
                update_option('dsz_sync_settings', $current);
                
                // Reschedule cron
                $this->cron->schedule_sync($current['frequency']);
                break;

            case 'import_settings':
                $import_settings = [
                    'default_status' => isset($settings['default_status']) ? sanitize_text_field($settings['default_status']) : 'publish',
                ];
                update_option('dsz_sync_import_settings', $import_settings);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid settings type', 'dropshipzone-sync')]);
        }

        wp_send_json_success(['message' => __('Settings saved successfully', 'dropshipzone-sync')]);
    }

    /**
     * AJAX: Run sync
     */
    public function ajax_run_sync() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $result = $this->cron->manual_sync();

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $status = $this->cron->get_sync_status();
        $status['progress'] = $this->cron->get_progress();

        wp_send_json_success($status);
    }

    /**
     * AJAX: Continue sync batch
     */
    public function ajax_continue_sync() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $result = $this->cron->continue_batch();

        wp_send_json_success($result);
    }

    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $this->logger->clear_logs();

        wp_send_json_success(['message' => __('Logs cleared successfully', 'dropshipzone-sync')]);
    }

    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $level = isset($_POST['level']) ? sanitize_text_field($_POST['level']) : '';
        $csv = $this->logger->export_csv(['level' => $level]);

        wp_send_json_success([
            'csv' => base64_encode($csv),
            'filename' => 'dsz-sync-logs-' . date('Y-m-d') . '.csv',
        ]);
    }

    /**
     * Render Product Mapping page
     */
    public function render_mapping() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }

        if (!$this->product_mapper) {
            echo '<div class="notice notice-error"><p>' . __('Product Mapper not initialized.', 'dropshipzone-sync') . '</p></div>';
            return;
        }

        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 30;

        $mappings = $this->product_mapper->get_mappings([
            'search' => $search,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        $total = $this->product_mapper->get_count(['search' => $search]);
        $total_pages = ceil($total / $per_page);
        $unmapped_count = $this->product_mapper->get_unmapped_count();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Product Mapping', 'dropshipzone-sync'), __('Map your WooCommerce products to Dropshipzone SKUs', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <!-- Mapping Stats -->
                <div class="dsz-form-section">
                    <div class="dsz-mapping-stats">
                        <div class="dsz-stat">
                            <strong><?php echo intval($total); ?></strong>
                            <span><?php _e('Mapped Products', 'dropshipzone-sync'); ?></span>
                        </div>
                        <div class="dsz-stat dsz-stat-warning">
                            <strong><?php echo intval($unmapped_count); ?></strong>
                            <span><?php _e('Unmapped Products', 'dropshipzone-sync'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dsz-form-section">
                    <h2><?php _e('Quick Actions', 'dropshipzone-sync'); ?></h2>
                    <div class="dsz-mapping-actions">
                        <button type="button" id="dsz-auto-map" class="button button-primary">
                            <span class="dashicons dashicons-admin-links"></span>
                            <?php _e('Auto-Map by SKU', 'dropshipzone-sync'); ?>
                        </button>
                        <p class="description"><?php _e('Automatically creates mappings for WooCommerce products that have SKUs matching their product SKU.', 'dropshipzone-sync'); ?></p>
                    </div>
                    <div id="dsz-automap-message" class="dsz-message hidden"></div>
                    
                    <div class="dsz-mapping-actions" style="margin-top: 15px;">
                        <button type="button" id="dsz-resync-all" class="button button-secondary">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Resync All Products', 'dropshipzone-sync'); ?>
                        </button>
                        <p class="description"><?php _e('Resync all mapped products with the latest data from Dropshipzone (price, stock, images, etc.).', 'dropshipzone-sync'); ?></p>
                    </div>
                    <div id="dsz-resync-all-message" class="dsz-message hidden"></div>
                    <div id="dsz-resync-all-progress" class="dsz-progress-wrapper hidden">
                        <div class="dsz-progress-bar">
                            <div id="dsz-resync-all-progress-fill" class="dsz-progress-fill" style="width: 0%"></div>
                        </div>
                        <p id="dsz-resync-all-progress-text" class="dsz-progress-text"></p>
                    </div>
                </div>

                <!-- Search and Add New -->
                <div class="dsz-form-section">
                    <h2><?php _e('Add New Mapping', 'dropshipzone-sync'); ?></h2>
                    <div class="dsz-mapping-add">
                        <div class="dsz-mapping-field">
                            <label><?php _e('WooCommerce Product:', 'dropshipzone-sync'); ?></label>
                            <input type="text" id="dsz-wc-search" placeholder="<?php _e('Search by name or SKU...', 'dropshipzone-sync'); ?>" />
                            <div id="dsz-wc-results" class="dsz-search-results hidden"></div>
                            <input type="hidden" id="dsz-wc-product-id" value="" />
                        </div>
                        <div class="dsz-mapping-arrow">→</div>
                        <div class="dsz-mapping-field">
                            <label><?php _e('Dropshipzone SKU:', 'dropshipzone-sync'); ?></label>
                            <input type="text" id="dsz-dsz-sku" placeholder="<?php _e('Enter DSZ SKU or search...', 'dropshipzone-sync'); ?>" />
                            <div id="dsz-dsz-results" class="dsz-search-results hidden"></div>
                        </div>
                        <button type="button" id="dsz-create-mapping" class="button button-primary" disabled>
                            <?php _e('Create Mapping', 'dropshipzone-sync'); ?>
                        </button>
                    </div>
                    <div id="dsz-mapping-message" class="dsz-message hidden"></div>
                </div>

                <!-- Existing Mappings -->
                <div class="dsz-form-section">
                    <h2><?php _e('Existing Mappings', 'dropshipzone-sync'); ?></h2>
                    
                    <!-- Search -->
                    <form method="get" class="dsz-mapping-search">
                        <input type="hidden" name="page" value="dsz-sync-mapping" />
                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search mappings...', 'dropshipzone-sync'); ?>" />
                        <button type="submit" class="button"><?php _e('Search', 'dropshipzone-sync'); ?></button>
                        <?php if ($search): ?>
                            <a href="<?php echo admin_url('admin.php?page=dsz-sync-mapping'); ?>" class="button"><?php _e('Clear', 'dropshipzone-sync'); ?></a>
                        <?php endif; ?>
                    </form>

                    <!-- Mappings Table -->
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('WooCommerce Product', 'dropshipzone-sync'); ?></th>
                                <th><?php _e('Dropshipzone SKU', 'dropshipzone-sync'); ?></th>
                                <th><?php _e('Last Synced', 'dropshipzone-sync'); ?></th>
                                <th class="column-actions"><?php _e('Actions', 'dropshipzone-sync'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mappings)): ?>
                                <tr>
                                    <td colspan="4" class="dsz-no-logs"><?php _e('No mappings found.', 'dropshipzone-sync'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mappings as $mapping): ?>
                                    <tr data-wc-id="<?php echo esc_attr($mapping['wc_product_id']); ?>">
                                        <td>
                                            <a href="<?php echo get_edit_post_link($mapping['wc_product_id']); ?>" target="_blank">
                                                <?php echo esc_html($mapping['wc_product_name'] ?: '#' . $mapping['wc_product_id']); ?>
                                            </a>
                                        </td>
                                        <td><code><?php echo esc_html($mapping['dsz_sku']); ?></code></td>
                                        <td><?php echo $mapping['last_synced'] ? dsz_format_datetime($mapping['last_synced']) : __('Never', 'dropshipzone-sync'); ?></td>
                                        <td class="column-actions">
                                            <button type="button" class="button button-small dsz-resync-btn" data-product-id="<?php echo esc_attr($mapping['wc_product_id']); ?>" data-sku="<?php echo esc_attr($mapping['dsz_sku']); ?>">
                                                <span class="dashicons dashicons-update"></span>
                                                <?php _e('Resync', 'dropshipzone-sync'); ?>
                                            </button>
                                            <button type="button" class="button button-small dsz-unmap-btn" data-wc-id="<?php echo esc_attr($mapping['wc_product_id']); ?>">
                                                <span class="dashicons dashicons-no-alt"></span>
                                                <?php _e('Unmap', 'dropshipzone-sync'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="dsz-pagination">
                            <?php
                            $base_url = admin_url('admin.php?page=dsz-sync-mapping' . ($search ? '&search=' . urlencode($search) : ''));
                            
                            if ($page > 1): ?>
                                <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="button">&laquo; <?php _e('Previous', 'dropshipzone-sync'); ?></a>
                            <?php endif; ?>
                            
                            <span class="dsz-pagination-info">
                                <?php printf(__('Page %d of %d', 'dropshipzone-sync'), $page, $total_pages); ?>
                            </span>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="button"><?php _e('Next', 'dropshipzone-sync'); ?> &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Search WooCommerce products
     */
    public function ajax_search_wc_products() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(['products' => []]);
        }

        $products = $this->product_mapper->search_wc_products($search, 20);
        wp_send_json_success(['products' => $products]);
    }

    /**
     * AJAX: Search Dropshipzone products
     */
    public function ajax_search_dsz_products() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(['products' => []]);
        }

        // Search Dropshipzone API by SKU
        $response = $this->api_client->get_products(['skus' => $search, 'limit' => 20]);
        
        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $products = isset($response['result']) ? $response['result'] : [];
        wp_send_json_success(['products' => $products]);
    }

    /**
     * AJAX: Map product
     */
    public function ajax_map_product() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $wc_product_id = isset($_POST['wc_product_id']) ? intval($_POST['wc_product_id']) : 0;
        $dsz_sku = isset($_POST['dsz_sku']) ? sanitize_text_field($_POST['dsz_sku']) : '';

        if (!$wc_product_id || !$dsz_sku) {
            wp_send_json_error(['message' => __('Product ID and SKU are required', 'dropshipzone-sync')]);
        }

        $result = $this->product_mapper->map($wc_product_id, $dsz_sku);

        if ($result) {
            wp_send_json_success(['message' => __('Mapping created successfully', 'dropshipzone-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to create mapping', 'dropshipzone-sync')]);
        }
    }

    /**
     * AJAX: Unmap product
     */
    public function ajax_unmap_product() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $wc_product_id = isset($_POST['wc_product_id']) ? intval($_POST['wc_product_id']) : 0;

        if (!$wc_product_id) {
            wp_send_json_error(['message' => __('Product ID is required', 'dropshipzone-sync')]);
        }

        $result = $this->product_mapper->unmap($wc_product_id);

        if ($result) {
            wp_send_json_success(['message' => __('Mapping removed successfully', 'dropshipzone-sync')]);
        } else {
            wp_send_json_error(['message' => __('Failed to remove mapping', 'dropshipzone-sync')]);
        }
    }

    /**
     * AJAX: Auto-map products
     */
    public function ajax_auto_map() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $results = $this->product_mapper->auto_map_by_sku();

        wp_send_json_success([
            'message' => sprintf(
                __('Auto-mapping complete! %d products mapped, %d skipped.', 'dropshipzone-sync'),
                $results['mapped'],
                $results['skipped']
            ),
            'mapped' => $results['mapped'],
            'skipped' => $results['skipped'],
        ]);
    }

    /**
     * Render Product Import page
     */
    public function render_import() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone-sync'));
        }
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Product Import', 'dropshipzone-sync'), __('Search and import new products from Dropshipzone', 'dropshipzone-sync')); ?>

            <div class="dsz-content">
                <div class="dsz-form-section">
                    <div class="dsz-import-search-bar">
                        <input type="text" id="dsz-import-search" placeholder="<?php _e('Search Dropshipzone products...', 'dropshipzone-sync'); ?>" />
                        <button type="button" id="dsz-import-search-btn" class="button button-primary">
                            <span class="dashicons dashicons-search"></span>
                            <?php _e('Search API', 'dropshipzone-sync'); ?>
                        </button>
                    </div>
                </div>

                <div id="dsz-import-results" class="dsz-import-results-container">
                    <div class="dsz-import-empty">
                        <span class="dashicons dashicons-search"></span>
                        <p><?php _e('Enter a keyword or SKU to search products from Dropshipzone.', 'dropshipzone-sync'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import Modal (Optional, but simple message is enough for now) -->
        <div id="dsz-import-modal" class="dsz-modal hidden">
            <div class="dsz-modal-content">
                <span class="dsz-modal-close">&times;</span>
                <div id="dsz-import-modal-body"></div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Search API products
     */
    public function ajax_search_api_products() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_error(['message' => __('Search term is too short.', 'dropshipzone-sync')]);
        }

        $search_lower = strtolower($search);
        $products = [];

        // First, try exact SKU search
        $response = $this->api_client->get_products(['skus' => $search, 'limit' => 50]);
        
        if (!is_wp_error($response) && !empty($response['result'])) {
            $products = $response['result'];
        }
        
        // If no results by SKU, fetch products and filter by title/name locally
        // (The Dropshipzone API doesn't support keyword search, only SKU filtering)
        if (empty($products)) {
            // Fetch a batch of products to search through
            $response = $this->api_client->get_products(['limit' => 200, 'page_no' => 1]);
            
            if (!is_wp_error($response) && !empty($response['result'])) {
                // Filter products by title/name matching the search term
                foreach ($response['result'] as $product) {
                    $title = isset($product['title']) ? strtolower($product['title']) : '';
                    $name = isset($product['name']) ? strtolower($product['name']) : '';
                    $sku = isset($product['sku']) ? strtolower($product['sku']) : '';
                    
                    // Check if search term is found in title, name, or SKU
                    if (strpos($title, $search_lower) !== false || 
                        strpos($name, $search_lower) !== false || 
                        strpos($sku, $search_lower) !== false) {
                        $products[] = $product;
                    }
                    
                    // Limit results to 50 matches
                    if (count($products) >= 50) {
                        break;
                    }
                }
                
                // If still no results, try fetching more pages
                if (empty($products) && isset($response['total_pages']) && $response['total_pages'] > 1) {
                    // Try page 2
                    $response2 = $this->api_client->get_products(['limit' => 200, 'page_no' => 2]);
                    
                    if (!is_wp_error($response2) && !empty($response2['result'])) {
                        foreach ($response2['result'] as $product) {
                            $title = isset($product['title']) ? strtolower($product['title']) : '';
                            $name = isset($product['name']) ? strtolower($product['name']) : '';
                            $sku = isset($product['sku']) ? strtolower($product['sku']) : '';
                            
                            if (strpos($title, $search_lower) !== false || 
                                strpos($name, $search_lower) !== false || 
                                strpos($sku, $search_lower) !== false) {
                                $products[] = $product;
                            }
                            
                            if (count($products) >= 50) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        if (empty($products)) {
            wp_send_json_error(['message' => __('No products found matching your search. Try a different keyword or enter an exact SKU.', 'dropshipzone-sync')]);
        }
        
        // Pre-check if products are already mapped/imported
        foreach ($products as &$product) {
            $wc_product_id = wc_get_product_id_by_sku($product['sku']);
            $product['is_imported'] = !empty($wc_product_id);
            $product['wc_product_id'] = $wc_product_id ? $wc_product_id : null;
        }

        wp_send_json_success(['products' => $products]);
    }

    /**
     * AJAX: Import product
     */
    public function ajax_import_product() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        if (!$sku) {
            wp_send_json_error(['message' => __('SKU is required', 'dropshipzone-sync')]);
        }

        // Check if product data was passed from search results
        $product_data = null;
        if (isset($_POST['product_data']) && !empty($_POST['product_data'])) {
            $product_data = json_decode(stripslashes($_POST['product_data']), true);
        }

        // If we have product data from search, use it directly; otherwise fetch by SKU
        if ($product_data && isset($product_data['sku']) && $product_data['sku'] === $sku) {
            $result = $this->product_importer->import_product($product_data);
        } else {
            $result = $this->product_importer->import_product($sku);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Product imported successfully!', 'dropshipzone-sync'),
            'product_id' => $result,
            'edit_url' => get_edit_post_link($result, 'url')
        ]);
    }

    /**
     * AJAX: Resync existing product from Dropshipzone API
     */
    public function ajax_resync_product() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        if (!$product_id && !$sku) {
            wp_send_json_error(['message' => __('Product ID or SKU is required', 'dropshipzone-sync')]);
        }

        // If we only have SKU, try to find the product ID
        if (!$product_id && $sku) {
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                wp_send_json_error(['message' => __('Product not found in WooCommerce', 'dropshipzone-sync')]);
            }
        }

        // Get resync options from request
        $options = [];
        if (isset($_POST['update_images'])) {
            $options['update_images'] = filter_var($_POST['update_images'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_description'])) {
            $options['update_description'] = filter_var($_POST['update_description'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_price'])) {
            $options['update_price'] = filter_var($_POST['update_price'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_stock'])) {
            $options['update_stock'] = filter_var($_POST['update_stock'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_title'])) {
            $options['update_title'] = filter_var($_POST['update_title'], FILTER_VALIDATE_BOOLEAN);
        }

        // Check if product data was passed from search results
        $product_data = null;
        if (isset($_POST['product_data']) && !empty($_POST['product_data'])) {
            $product_data = json_decode(stripslashes($_POST['product_data']), true);
        }

        // Perform resync
        $result = $this->product_importer->resync_product($product_id, $product_data, $options);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Product resynced successfully!', 'dropshipzone-sync'),
            'product_id' => $result,
            'edit_url' => get_edit_post_link($result, 'url')
        ]);
    }

    /**
     * AJAX: Resync all mapped products
     */
    public function ajax_resync_all() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone-sync')]);
        }

        // Get all mappings
        $mappings = $this->product_mapper->get_mappings(['limit' => 1000]);
        
        if (empty($mappings)) {
            wp_send_json_error(['message' => __('No mapped products found to resync.', 'dropshipzone-sync')]);
        }

        $total = count($mappings);
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        // Process each mapping
        foreach ($mappings as $mapping) {
            $product_id = $mapping['wc_product_id'];
            $sku = $mapping['dsz_sku'];

            // Resync the product
            $result = $this->product_importer->resync_product($product_id, null, [
                'update_price' => true,
                'update_stock' => true,
                'update_images' => true,
                'update_description' => true,
                'update_title' => true,
            ]);

            if (is_wp_error($result)) {
                $error_count++;
                $errors[] = sprintf('%s: %s', $sku, $result->get_error_message());
            } else {
                $success_count++;
            }
        }

        $message = sprintf(
            __('Resync complete! %d of %d products resynced successfully.', 'dropshipzone-sync'),
            $success_count,
            $total
        );

        if ($error_count > 0) {
            $message .= ' ' . sprintf(__('%d errors occurred.', 'dropshipzone-sync'), $error_count);
        }

        wp_send_json_success([
            'message' => $message,
            'total' => $total,
            'success' => $success_count,
            'errors' => $error_count,
            'error_details' => array_slice($errors, 0, 10), // Return first 10 errors
        ]);
    }
}
