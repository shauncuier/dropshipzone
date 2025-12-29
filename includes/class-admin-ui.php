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
     * Order Handler instance
     * 
     * @var Order_Handler
     */
    private $order_handler;

    /**
     * Auto Importer instance
     * 
     * @var Auto_Importer
     */
    private $auto_importer;

    /**
     * Constructor
     */
    public function __construct(API_Client $api_client, Price_Sync $price_sync, Stock_Sync $stock_sync, Cron $cron, Logger $logger, Product_Mapper $product_mapper = null, Product_Importer $product_importer = null, Order_Handler $order_handler = null, Auto_Importer $auto_importer = null) {
        $this->api_client = $api_client;
        $this->price_sync = $price_sync;
        $this->stock_sync = $stock_sync;
        $this->cron = $cron;
        $this->logger = $logger;
        $this->product_mapper = $product_mapper;
        $this->product_importer = $product_importer;
        $this->order_handler = $order_handler;
        $this->auto_importer = $auto_importer;

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
        add_action('wp_ajax_dsz_resync_images', [$this, 'ajax_resync_images']);
        add_action('wp_ajax_dsz_resync_categories', [$this, 'ajax_resync_categories']);
        add_action('wp_ajax_dsz_resync_never_synced', [$this, 'ajax_resync_never_synced']);
        add_action('wp_ajax_dsz_scan_unmapped_products', [$this, 'ajax_scan_unmapped_products']);
        add_action('wp_ajax_dsz_get_categories', [$this, 'ajax_get_categories']);
        
        // Order AJAX handlers
        add_action('wp_ajax_dsz_submit_order', [$this, 'ajax_submit_order']);
        
        // Auto Import AJAX handlers
        add_action('wp_ajax_dsz_run_auto_import', [$this, 'ajax_run_auto_import']);
        add_action('wp_ajax_dsz_save_auto_import_settings', [$this, 'ajax_save_auto_import_settings']);
        
        // WooCommerce order integration
        add_action('add_meta_boxes', [$this, 'add_order_meta_box']);
    }

    /**
     * Register admin menu
     */
    public function register_menu() {
        // Main menu
        add_menu_page(
            __('Dropshipzone Sync', 'dropshipzone'),
            __('DSZ Sync', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync',
            [$this, 'render_dashboard'],
            'dashicons-update',
            56
        );

        // Dashboard (same as main)
        add_submenu_page(
            'dsz-sync',
            __('Dashboard', 'dropshipzone'),
            __('Dashboard', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync',
            [$this, 'render_dashboard']
        );

        // API Settings
        add_submenu_page(
            'dsz-sync',
            __('API Settings', 'dropshipzone'),
            __('API Settings', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-api',
            [$this, 'render_api_settings']
        );

        // Price Rules
        add_submenu_page(
            'dsz-sync',
            __('Price Rules', 'dropshipzone'),
            __('Price Rules', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-price',
            [$this, 'render_price_rules']
        );

        // Stock Rules
        add_submenu_page(
            'dsz-sync',
            __('Stock Rules', 'dropshipzone'),
            __('Stock Rules', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-stock',
            [$this, 'render_stock_rules']
        );

        // Sync Center (unified sync page)
        add_submenu_page(
            'dsz-sync',
            __('Sync Center', 'dropshipzone'),
            __('Sync Center', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-control',
            [$this, 'render_sync_center']
        );

        // Logs
        add_submenu_page(
            'dsz-sync',
            __('Logs', 'dropshipzone'),
            __('Logs', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-logs',
            [$this, 'render_logs']
        );

        // Product Mapping
        add_submenu_page(
            'dsz-sync',
            __('Product Mapping', 'dropshipzone'),
            __('Product Mapping', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-mapping',
            [$this, 'render_mapping']
        );

        // Product Import
        add_submenu_page(
            'dsz-sync',
            __('Product Import', 'dropshipzone'),
            __('Product Import', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-import',
            [$this, 'render_import']
        );

        // Auto Import Settings
        add_submenu_page(
            'dsz-sync',
            __('Auto Import', 'dropshipzone'),
            __('Auto Import', 'dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-auto-import',
            [$this, 'render_auto_import']
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
                'testing' => __('Testing connection...', 'dropshipzone'),
                'saving' => __('Saving...', 'dropshipzone'),
                'syncing' => __('Syncing...', 'dropshipzone'),
                'success' => __('Success!', 'dropshipzone'),
                'error' => __('Error occurred', 'dropshipzone'),
                'confirm_clear' => __('Are you sure you want to clear all logs?', 'dropshipzone'),
            ],
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('dsz_sync_api', 'dsz_sync_api_email', [
            'sanitize_callback' => 'sanitize_email'
        ]);
        register_setting('dsz_sync_api', 'dsz_sync_api_password', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('dsz_sync_settings', 'dsz_sync_price_rules', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
        register_setting('dsz_sync_settings', 'dsz_sync_stock_rules', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
        register_setting('dsz_sync_settings', 'dsz_sync_settings', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
        register_setting('dsz_sync_settings', 'dsz_sync_import_settings', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
    }

    /**
     * Sanitize array data
     *
     * @param array|string $input Input data
     * @return array|string Sanitized data
     */
    public function sanitize_array($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitize_array'], $input);
        }
        return sanitize_text_field($input);
    }

    /**
     * Render page header with navigation
     */
    private function render_header($title, $subtitle = '') {
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'dsz-sync';
        
        $nav_items = [
            // Overview
            'dsz-sync' => [
                'label' => __('Dashboard', 'dropshipzone'),
                'icon' => 'dashicons-dashboard'
            ],
            // 1. First: Configure API
            'dsz-sync-api' => [
                'label' => __('API Settings', 'dropshipzone'),
                'icon' => 'dashicons-admin-network'
            ],
            // 2. Products: Import first, then map
            'dsz-sync-import' => [
                'label' => __('Import Products', 'dropshipzone'),
                'icon' => 'dashicons-download'
            ],
            'dsz-sync-auto-import' => [
                'label' => __('Auto Import', 'dropshipzone'),
                'icon' => 'dashicons-controls-repeat'
            ],
            'dsz-sync-mapping' => [
                'label' => __('Product Mapping', 'dropshipzone'),
                'icon' => 'dashicons-admin-links'
            ],
            // 3. Rules: Configure pricing and stock
            'dsz-sync-price' => [
                'label' => __('Price Rules', 'dropshipzone'),
                'icon' => 'dashicons-money-alt'
            ],
            'dsz-sync-stock' => [
                'label' => __('Stock Rules', 'dropshipzone'),
                'icon' => 'dashicons-archive'
            ],
            // 4. Operations: Run sync and view logs
            'dsz-sync-control' => [
                'label' => __('Sync Center', 'dropshipzone'),
                'icon' => 'dashicons-update'
            ],
            'dsz-sync-logs' => [
                'label' => __('Logs', 'dropshipzone'),
                'icon' => 'dashicons-list-view'
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
                   class="dsz-nav-item <?php echo esc_attr($is_active ? 'dsz-nav-active' : ''); ?>"
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
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $sync_status = $this->cron->get_sync_status();
        $token_status = $this->api_client->get_token_status();
        $error_count = $this->logger->get_count('error');
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Dropshipzone Sync Dashboard', 'dropshipzone')); ?>

            <div class="dsz-dashboard">
                <!-- Status Cards -->
                <div class="dsz-cards">
                    <div class="dsz-card dsz-card-status <?php echo esc_attr($token_status['is_valid'] ? 'dsz-card-success' : 'dsz-card-error'); ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons <?php echo esc_attr($token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('API Status', 'dropshipzone'); ?></h3>
                            <p class="dsz-card-value">
                                <?php echo esc_html($token_status['is_valid'] ? __('Connected', 'dropshipzone') : __('Not Connected', 'dropshipzone')); ?>
                            </p>
                            <?php if ($token_status['is_valid'] && $token_status['expires_in'] > 0): ?>
                                <?php /* translators: %s: time difference */ ?>
                                <p class="dsz-card-meta"><?php printf(esc_html__('Expires in %s', 'dropshipzone'), esc_html(human_time_diff(time(), time() + $token_status['expires_in']))); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dsz-card">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('Last Sync', 'dropshipzone'); ?></h3>
                            <p class="dsz-card-value">
                                <?php echo $sync_status['last_sync'] ? esc_html(dsz_time_ago($sync_status['last_sync'])) : esc_html__('Never', 'dropshipzone'); ?>
                            </p>
                            <?php if ($sync_status['next_scheduled']): ?>
                                <?php /* translators: %s: date time */ ?>
                                <p class="dsz-card-meta"><?php printf(esc_html__('Next: %s', 'dropshipzone'), esc_html(dsz_format_datetime($sync_status['next_scheduled']))); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dsz-card">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('Products Updated', 'dropshipzone'); ?></h3>
                            <p class="dsz-card-value"><?php echo intval($sync_status['last_products_updated']); ?></p>
                            <p class="dsz-card-meta"><?php esc_html_e('Last sync run', 'dropshipzone'); ?></p>
                        </div>
                    </div>

                    <div class="dsz-card <?php echo esc_attr($error_count > 0 ? 'dsz-card-warning' : ''); ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-flag"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('Errors', 'dropshipzone'); ?></h3>
                            <p class="dsz-card-value"><?php echo intval($error_count); ?></p>
                            <p class="dsz-card-meta">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=error')); ?>"><?php esc_html_e('View Logs', 'dropshipzone'); ?></a>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dsz-section">
                    <h2><?php esc_html_e('Quick Actions', 'dropshipzone'); ?></h2>
                    <div class="dsz-quick-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-api')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php esc_html_e('Configure API', 'dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-control')); ?>" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Run Sync Now', 'dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-price')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-money-alt"></span>
                            <?php esc_html_e('Price Rules', 'dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-stock')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-archive"></span>
                            <?php esc_html_e('Stock Rules', 'dropshipzone'); ?>
                        </a>
                    </div>
                </div>

                <!-- Sync Status (if in progress) -->
                <?php if ($sync_status['in_progress']): ?>
                <div class="dsz-section dsz-sync-progress-section">
                    <h2><?php esc_html_e('Sync in Progress', 'dropshipzone'); ?></h2>
                    <div class="dsz-progress-wrapper">
                        <div class="dsz-progress-bar">
                            <div class="dsz-progress-fill" style="width: <?php echo esc_attr($this->cron->get_progress()); ?>%"></div>
                        </div>
                        <p class="dsz-progress-text">
                            <?php /* translators: %s: progress percentage */ ?>
                            <?php printf(esc_html__('Processing... %s%%', 'dropshipzone'), esc_html($this->cron->get_progress())); ?>
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
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $email = get_option('dsz_sync_api_email', '');
        $token_status = $this->api_client->get_token_status();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('API Settings', 'dropshipzone'), __('Configure your Dropshipzone API credentials', 'dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-api-form" class="dsz-form">
                    <?php wp_nonce_field('dsz_api_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('API Credentials', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dsz_api_email"><?php esc_html_e('API Email', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="dsz_api_email" name="dsz_api_email" value="<?php echo esc_attr($email); ?>" class="regular-text" />
                                    <p class="description"><?php esc_html_e('Your Dropshipzone account email', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dsz_api_password"><?php esc_html_e('API Password', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="dsz_api_password" name="dsz_api_password" value="" class="regular-text" placeholder="<?php echo esc_attr($email ? '••••••••' : ''); ?>" />
                                    <p class="description"><?php esc_html_e('Your Dropshipzone account password (stored securely)', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <div class="dsz-form-actions">
                            <button type="button" id="dsz-test-connection" class="button button-secondary">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php esc_html_e('Test Connection', 'dropshipzone'); ?>
                            </button>
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php esc_html_e('Save Settings', 'dropshipzone'); ?>
                            </button>
                        </div>

                        <div id="dsz-api-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Import Settings -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Import Settings', 'dropshipzone'); ?></h2>
                        <?php 
                        $import_settings = get_option('dsz_sync_import_settings', ['default_status' => 'publish']);
                        $default_status = isset($import_settings['default_status']) ? $import_settings['default_status'] : 'publish';
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dsz_import_status"><?php esc_html_e('Default Product Status', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <select id="dsz_import_status" name="dsz_import_status" class="dsz-import-status-select">
                                        <option value="publish" <?php selected($default_status, 'publish'); ?>><?php esc_html_e('Published', 'dropshipzone'); ?></option>
                                        <option value="draft" <?php selected($default_status, 'draft'); ?>><?php esc_html_e('Draft', 'dropshipzone'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('New products will be created with this status.', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Token Status -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Connection Status', 'dropshipzone'); ?></h2>
                        <div class="dsz-status-box <?php echo esc_attr($token_status['is_valid'] ? 'dsz-status-success' : 'dsz-status-warning'); ?>">
                            <span class="dashicons <?php echo esc_attr($token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
                            <div>
                                <strong><?php echo esc_html($token_status['is_valid'] ? __('Connected', 'dropshipzone') : __('Not Connected', 'dropshipzone')); ?></strong>
                                <?php if ($token_status['is_valid']): ?>
                                    <?php /* translators: %s: date time */ ?>
                                    <p><?php printf(esc_html__('Token expires: %s', 'dropshipzone'), esc_html(dsz_format_datetime($token_status['expires_at']))); ?></p>
                                <?php else: ?>
                                    <p><?php esc_html_e('Please enter your credentials and test the connection.', 'dropshipzone'); ?></p>
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
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $rules = $this->price_sync->get_rules();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Price Rules', 'dropshipzone'), __('Configure how prices are calculated from supplier cost', 'dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-price-form" class="dsz-form" data-type="price_rules">
                    <?php wp_nonce_field('dsz_price_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Markup Settings', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Markup Type', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="markup_type" value="percentage" <?php checked($rules['markup_type'], 'percentage'); ?> />
                                        <?php esc_html_e('Percentage', 'dropshipzone'); ?>
                                    </label>
                                    <label style="margin-left: 20px;">
                                        <input type="radio" name="markup_type" value="fixed" <?php checked($rules['markup_type'], 'fixed'); ?> />
                                        <?php esc_html_e('Fixed Amount', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="markup_value"><?php esc_html_e('Markup Value', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="markup_value" name="markup_value" value="<?php echo esc_attr($rules['markup_value']); ?>" step="0.01" min="0" class="small-text" />
                                    <span class="dsz-markup-symbol">%</span>
                                    <p class="description"><?php esc_html_e('Enter percentage (e.g., 30 for 30%) or fixed amount (e.g., 15 for $15)', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('GST Settings', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Apply GST', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gst_enabled" value="1" <?php checked($rules['gst_enabled'], true); ?> />
                                        <?php esc_html_e('Enable GST calculation (10%)', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('GST Mode', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="gst_type" value="include" <?php checked($rules['gst_type'], 'include'); ?> />
                                        <?php esc_html_e('Supplier price already includes GST', 'dropshipzone'); ?>
                                    </label><br/>
                                    <label>
                                        <input type="radio" name="gst_type" value="exclude" <?php checked($rules['gst_type'], 'exclude'); ?> />
                                        <?php esc_html_e('Add GST to calculated price', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Price Rounding', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Rounding', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rounding_enabled" value="1" <?php checked($rules['rounding_enabled'], true); ?> />
                                        <?php esc_html_e('Round prices for cleaner display', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Rounding Style', 'dropshipzone'); ?></th>
                                <td>
                                    <select name="rounding_type">
                                        <option value="99" <?php selected($rules['rounding_type'], '99'); ?>><?php esc_html_e('.99 (e.g., $29.99)', 'dropshipzone'); ?></option>
                                        <option value="95" <?php selected($rules['rounding_type'], '95'); ?>><?php esc_html_e('.95 (e.g., $29.95)', 'dropshipzone'); ?></option>
                                        <option value="nearest" <?php selected($rules['rounding_type'], 'nearest'); ?>><?php esc_html_e('Nearest dollar (e.g., $30)', 'dropshipzone'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Price Preview -->
                    <div class="dsz-form-section dsz-preview-section">
                        <h2><?php esc_html_e('Price Preview', 'dropshipzone'); ?></h2>
                        <div class="dsz-price-preview">
                            <div class="dsz-preview-input">
                                <label for="preview_price"><?php esc_html_e('Supplier Price:', 'dropshipzone'); ?></label>
                                <input type="number" id="preview_price" value="100" step="0.01" min="0" />
                            </div>
                            <div class="dsz-preview-result">
                                <span class="dsz-preview-arrow">→</span>
                                <div class="dsz-preview-final">
                                    <label><?php esc_html_e('Final Price:', 'dropshipzone'); ?></label>
                                    <strong id="calculated_price">$0.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Price Rules', 'dropshipzone'); ?>
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
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $rules = $this->stock_sync->get_rules();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Stock Rules', 'dropshipzone'), __('Configure how stock quantities are synced', 'dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-stock-form" class="dsz-form" data-type="stock_rules">
                    <?php wp_nonce_field('dsz_stock_settings', 'dsz_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Stock Buffer', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Stock Buffer', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="buffer_enabled" value="1" <?php checked($rules['buffer_enabled'], true); ?> />
                                        <?php esc_html_e('Subtract a buffer amount from supplier stock', 'dropshipzone'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('Useful to prevent overselling', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="buffer_amount"><?php esc_html_e('Buffer Amount', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="buffer_amount" name="buffer_amount" value="<?php echo esc_attr($rules['buffer_amount']); ?>" min="0" step="1" class="small-text" />
                                    <p class="description"><?php esc_html_e('Number of units to subtract from supplier stock (e.g., 2 means if supplier has 10, your store shows 8)', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Out of Stock Handling', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Zero Stock on Unavailable', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="zero_on_unavailable" value="1" <?php checked($rules['zero_on_unavailable'], true); ?> />
                                        <?php esc_html_e('Set stock to 0 if product is marked unavailable by supplier', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Auto Out of Stock', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="auto_out_of_stock" value="1" <?php checked($rules['auto_out_of_stock'], true); ?> />
                                        <?php esc_html_e('Automatically set product status to "Out of Stock" when quantity is 0', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Deactivate Missing Products', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="deactivate_if_not_found" value="1" <?php checked(isset($rules['deactivate_if_not_found']) ? $rules['deactivate_if_not_found'] : true, true); ?> />
                                        <?php esc_html_e('Set products to Draft if not found in Dropshipzone API (discontinued products)', 'dropshipzone'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('When a product SKU is no longer available in Dropshipzone, the product will be set to Draft status and stock set to 0.', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Auto-Republish on Restock', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="republish_on_restock" value="1" <?php checked(isset($rules['republish_on_restock']) ? $rules['republish_on_restock'] : true, true); ?> />
                                        <?php esc_html_e('Automatically republish Draft products when they come back in stock', 'dropshipzone'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('When a product that was set to Draft (due to being out of stock or discontinued) gets stock again, it will be automatically set back to Published.', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Stock Rules', 'dropshipzone'); ?>
                        </button>
                    </div>

                    <div id="dsz-stock-message" class="dsz-message hidden"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Render Sync Center page (unified sync actions)
     */
    public function render_sync_center() {
        if (!dsz_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $sync_status = $this->cron->get_sync_status();
        $frequencies = $this->cron->get_frequencies();
        $total_mapped = intval($this->product_mapper->get_count());
        $unmapped_count = $this->product_mapper->get_unmapped_count();
        $in_progress = !empty($sync_status['in_progress']);
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Sync Center', 'dropshipzone'), __('All sync actions in one place', 'dropshipzone')); ?>

            <div class="dsz-sync-dashboard">
                <!-- Status Cards Grid -->
                <div class="dsz-sync-cards">
                    <!-- Card: Current Status -->
                    <div class="dsz-sync-card <?php echo esc_attr($in_progress ? 'dsz-card-syncing' : 'dsz-card-idle'); ?>">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons <?php echo esc_attr($in_progress ? 'dashicons-update-alt dsz-spin' : 'dashicons-yes-alt'); ?>"></span>
                        </div>
                        <div class="dsz-sync-card-content">
                            <h3><?php esc_html_e('Sync State', 'dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value" id="sync-status-text">
                                <?php echo esc_html($in_progress ? __('Syncing...', 'dropshipzone') : __('Ready', 'dropshipzone')); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <?php if ($in_progress): ?>
                                    <span class="dsz-pulse-dot"></span> <?php esc_html_e('Processing...', 'dropshipzone'); ?>
                                <?php else: ?>
                                    <?php esc_html_e('All systems ready', 'dropshipzone'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Last Sync Results -->
                    <div class="dsz-sync-card">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons dashicons-calendar-alt"></span>
                        </div>
                        <div class="dsz-sync-card-content">
                            <h3><?php esc_html_e('Last Sync', 'dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value">
                                <?php echo $sync_status['last_sync'] ? esc_html(dsz_time_ago($sync_status['last_sync'])) : esc_html__('Never', 'dropshipzone'); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <span class="dsz-text-success"><?php echo intval($sync_status['last_products_updated']); ?> <?php esc_html_e('updated', 'dropshipzone'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Linked Products -->
                    <div class="dsz-sync-card">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons dashicons-admin-links"></span>
                        </div>
                        <div class="dsz-sync-card-content">
                            <h3><?php esc_html_e('Linked Products', 'dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value">
                                <?php echo number_format($total_mapped); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <?php if ($unmapped_count > 0): ?>
                                    <span class="dsz-text-warning"><?php echo intval($unmapped_count); ?> <?php esc_html_e('unlinked', 'dropshipzone'); ?></span>
                                <?php else: ?>
                                    <?php esc_html_e('All products linked', 'dropshipzone'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Next Schedule -->
                    <div class="dsz-sync-card">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="dsz-sync-card-content">
                            <h3><?php esc_html_e('Next Auto-Sync', 'dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value">
                                <?php echo $sync_status['next_scheduled'] ? esc_html(dsz_time_ago($sync_status['next_scheduled'])) : esc_html__('Not scheduled', 'dropshipzone'); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <?php 
                                $freq_labels = [
                                    'hourly' => __('Hourly', 'dropshipzone'),
                                    'twicedaily' => __('Twice Daily', 'dropshipzone'),
                                    'daily' => __('Daily', 'dropshipzone'),
                                ];
                                echo esc_html(isset($freq_labels[$sync_status['frequency']]) ? $freq_labels[$sync_status['frequency']] : ucfirst($sync_status['frequency']));
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Cards Grid -->
                <div class="dsz-sync-actions-grid">
                    <!-- Action Card 1: Link Products -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-admin-links"></span>
                            <h3><?php esc_html_e('Link Products', 'dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Scan your WooCommerce catalog and automatically link products to Dropshipzone using matching SKUs.', 'dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-auto-map" class="button button-secondary">
                                <span class="dashicons dashicons-admin-links"></span>
                                <?php esc_html_e('Link Products by SKU', 'dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-automap-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 2: Update Prices & Stock -->
                    <div class="dsz-action-card dsz-action-card-primary">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-money-alt"></span>
                            <h3><?php esc_html_e('Update Prices & Stock', 'dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Sync the latest prices and stock levels from Dropshipzone API for all linked products.', 'dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-run-sync" class="button button-primary" <?php echo esc_attr($in_progress ? 'disabled' : ''); ?>>
                                <span class="dashicons dashicons-update-alt"></span>
                                <?php esc_html_e('Update Now', 'dropshipzone'); ?>
                            </button>
                        </div>
                        <!-- Progress Section -->
                        <div id="dsz-progress-container" class="dsz-progress-console <?php echo esc_attr($in_progress ? '' : 'hidden'); ?>">
                            <div class="dsz-progress-stats">
                                <span class="dsz-progress-label"><?php esc_html_e('Progress', 'dropshipzone'); ?></span>
                                <span id="dsz-progress-percent" class="dsz-progress-value"><?php echo esc_html($this->cron->get_progress()); ?>%</span>
                            </div>
                            <div class="dsz-progress-bar-wrapper">
                                <div id="dsz-progress-fill" class="dsz-progress-fill" style="width: <?php echo esc_attr($this->cron->get_progress()); ?>%">
                                    <div class="dsz-progress-glow"></div>
                                </div>
                            </div>
                            <div id="dsz-progress-text" class="dsz-progress-status-text"></div>
                        </div>
                        <div id="dsz-sync-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 3: Refresh Images -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-format-image"></span>
                            <h3><?php esc_html_e('Refresh Images', 'dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Re-download product images and gallery from Dropshipzone for linked products.', 'dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-resync-images" class="button button-secondary">
                                <span class="dashicons dashicons-format-image"></span>
                                <?php esc_html_e('Refresh Images', 'dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-resync-images-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 4: Refresh Categories -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-category"></span>
                            <h3><?php esc_html_e('Refresh Categories', 'dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Update product categories from Dropshipzone for linked products.', 'dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-resync-categories" class="button button-secondary">
                                <span class="dashicons dashicons-category"></span>
                                <?php esc_html_e('Refresh Categories', 'dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-resync-categories-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 5: Refresh All Product Data -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-download"></span>
                            <h3><?php esc_html_e('Refresh All Data', 'dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Re-download everything: images, descriptions, categories, price, stock for linked products.', 'dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-resync-all" class="button button-secondary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Refresh All Data', 'dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-resync-all-progress" class="dsz-progress-wrapper hidden">
                            <div class="dsz-progress-bar">
                                <div id="dsz-resync-all-progress-fill" class="dsz-progress-fill" style="width: 0%"></div>
                            </div>
                            <p id="dsz-resync-all-progress-text" class="dsz-progress-text"></p>
                        </div>
                        <div id="dsz-resync-all-message" class="dsz-message hidden"></div>
                    </div>
                </div>

                <!-- Schedule Settings -->
                <div class="dsz-content dsz-sync-settings-full">
                    <form id="dsz-schedule-form" class="dsz-form" data-type="sync_settings">
                        <?php wp_nonce_field('dsz_sync_settings', 'dsz_nonce'); ?>
                        
                        <div class="dsz-form-section">
                            <div class="dsz-section-header">
                                <h2><?php esc_html_e('Auto-Sync Schedule', 'dropshipzone'); ?></h2>
                                <p class="description"><?php esc_html_e('Configure automatic price & stock updates.', 'dropshipzone'); ?></p>
                            </div>
                            
                            <div class="dsz-inline-settings">
                                <div class="dsz-form-group">
                                    <label for="frequency"><?php esc_html_e('Interval', 'dropshipzone'); ?></label>
                                    <select id="frequency" name="frequency" class="dsz-select">
                                        <?php foreach ($frequencies as $value => $label): ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($sync_status['frequency'], $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="dsz-form-group">
                                    <label for="batch_size"><?php esc_html_e('Batch Size', 'dropshipzone'); ?></label>
                                    <input type="number" id="batch_size" name="batch_size" value="<?php echo esc_attr($sync_status['batch_size']); ?>" min="10" max="200" step="10" />
                                </div>

                                <button type="submit" class="button button-primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Save', 'dropshipzone'); ?>
                                </button>
                                <div id="dsz-schedule-message" class="dsz-message hidden"></div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Logs page
     */
    public function render_logs() {
        if (!dsz_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $level = isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 50;

        $logs = $this->logger->get_logs([
            'level' => $level,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        $total = $this->logger->get_count($level);
        $total_pages = ceil($total / $per_page);

        // Get counts by level for stats cards
        $total_all = $this->logger->get_count('');
        $total_info = $this->logger->get_count('info');
        $total_warning = $this->logger->get_count('warning');
        $total_error = $this->logger->get_count('error');
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Activity Logs', 'dropshipzone'), __('Monitor sync activity and troubleshoot issues', 'dropshipzone')); ?>

            <!-- Log Stats Cards -->
            <div class="dsz-log-stats">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs')); ?>" class="dsz-log-stat-card <?php echo empty($level) ? 'active' : ''; ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-list-view"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_all); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Total Logs', 'dropshipzone'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=info')); ?>" class="dsz-log-stat-card dsz-log-stat-info <?php echo esc_attr($level === 'info' ? 'active' : ''); ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-info"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_info); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Info', 'dropshipzone'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=warning')); ?>" class="dsz-log-stat-card dsz-log-stat-warning <?php echo esc_attr($level === 'warning' ? 'active' : ''); ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-warning"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_warning); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Warnings', 'dropshipzone'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=error')); ?>" class="dsz-log-stat-card dsz-log-stat-error <?php echo esc_attr($level === 'error' ? 'active' : ''); ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-dismiss"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_error); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Errors', 'dropshipzone'); ?></span>
                    </div>
                </a>
            </div>

            <div class="dsz-content">
                <!-- Toolbar -->
                <div class="dsz-logs-toolbar">
                    <div class="dsz-logs-summary">
                        <?php if ($level): ?>
                            <?php 
                            /* translators: %1$d: count, %2$s: level type */
                            printf(esc_html__('Showing %1$d %2$s logs', 'dropshipzone'), $total, ucfirst($level)); 
                            ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs')); ?>" class="dsz-clear-filter">
                                <?php esc_html_e('Clear filter', 'dropshipzone'); ?>
                            </a>
                        <?php else: ?>
                            <?php 
                            /* translators: %d: total log count */
                            printf(esc_html__('Showing all %d logs', 'dropshipzone'), $total); 
                            ?>
                        <?php endif; ?>
                    </div>
                    <div class="dsz-logs-actions">
                        <button type="button" id="dsz-export-logs" class="button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Export CSV', 'dropshipzone'); ?>
                        </button>
                        <button type="button" id="dsz-clear-logs" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear All', 'dropshipzone'); ?>
                        </button>
                    </div>
                </div>

                <!-- Logs List -->
                <?php if (empty($logs)): ?>
                    <div class="dsz-logs-empty">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <h3><?php esc_html_e('No logs found', 'dropshipzone'); ?></h3>
                        <p><?php esc_html_e('Activity logs will appear here as sync operations run.', 'dropshipzone'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="dsz-logs-list">
                        <?php foreach ($logs as $log): ?>
                            <div class="dsz-log-item dsz-log-<?php echo esc_attr($log['level']); ?>">
                                <div class="dsz-log-indicator"></div>
                                <div class="dsz-log-content">
                                    <div class="dsz-log-header">
                                        <?php echo wp_kses_post(Logger::get_level_badge($log['level'])); ?>
                                        <span class="dsz-log-time" title="<?php echo esc_attr(dsz_format_datetime($log['created_at'])); ?>">
                                            <?php echo esc_html(dsz_time_ago($log['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="dsz-log-message"><?php echo esc_html($log['message']); ?></div>
                                    <?php if (!empty($log['context'])): ?>
                                        <details class="dsz-log-context">
                                            <summary><?php esc_html_e('View details', 'dropshipzone'); ?></summary>
                                            <pre><?php echo esc_html(wp_json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="dsz-pagination">
                        <?php
                        $base_url = admin_url('admin.php?page=dsz-sync-logs' . ($level ? '&level=' . $level : ''));
                        
                        if ($page > 1): ?>
                            <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="button">&laquo; <?php esc_html_e('Previous', 'dropshipzone'); ?></a>
                        <?php endif; ?>
                        
                        <span class="dsz-pagination-info">
                            <?php
                            /* translators: %1$d: current page, %2$d: total pages */
                            echo esc_html(sprintf(__('Page %1$d of %2$d', 'dropshipzone'), $page, $total_pages));
                            ?>
                        </span>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="button"><?php esc_html_e('Next', 'dropshipzone'); ?> &raquo;</a>
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        $settings = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : [];

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
                    'republish_on_restock' => !empty($settings['republish_on_restock']),
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
                wp_send_json_error(['message' => __('Invalid settings type', 'dropshipzone')]);
        }

        wp_send_json_success(['message' => __('Settings saved successfully', 'dropshipzone')]);
    }

    /**
     * AJAX: Run sync
     */
    public function ajax_run_sync() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $this->logger->clear_logs();

        wp_send_json_success(['message' => __('Logs cleared successfully', 'dropshipzone')]);
    }

    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $level = isset($_POST['level']) ? sanitize_text_field(wp_unslash($_POST['level'])) : '';
        $csv = $this->logger->export_csv(['level' => $level]);

        wp_send_json_success([
            'csv' => base64_encode($csv),
            'filename' => 'dsz-sync-logs-' . gmdate('Y-m-d') . '.csv',
        ]);
    }

    /**
     * Render Product Mapping page
     */
    public function render_mapping() {
        if (!dsz_current_user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'dropshipzone'));
        }

        if (!$this->product_mapper) {
            echo '<div class="notice notice-error"><p>' . __('Product Mapper not initialized.', 'dropshipzone') . '</p></div>';
            return;
        }

        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $resync_filter = isset($_GET['resync_filter']) ? sanitize_text_field(wp_unslash($_GET['resync_filter'])) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 30;

        $mappings = $this->product_mapper->get_mappings([
            'search' => $search,
            'resync_filter' => $resync_filter,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ]);

        $total = $this->product_mapper->get_count(['search' => $search, 'resync_filter' => $resync_filter]);
        $total_pages = ceil($total / $per_page);
        $unmapped_count = $this->product_mapper->get_unmapped_count();
        $never_synced_count = $this->product_mapper->get_count(['resync_filter' => 'never']);
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Product Mapping', 'dropshipzone'), __('Map your WooCommerce products to Dropshipzone SKUs', 'dropshipzone')); ?>

            <div class="dsz-content">
                <!-- Mapping Stats -->
                <div class="dsz-form-section">
                    <div class="dsz-mapping-stats">
                        <div class="dsz-stat">
                            <strong><?php echo intval($total); ?></strong>
                            <span><?php esc_html_e('Mapped Products', 'dropshipzone'); ?></span>
                        </div>
                        <div class="dsz-stat dsz-stat-warning">
                            <strong><?php echo intval($unmapped_count); ?></strong>
                            <span><?php esc_html_e('Unmapped Products', 'dropshipzone'); ?></span>
                        </div>
                        <div class="dsz-stat dsz-stat-info">
                            <strong><?php echo intval($never_synced_count); ?></strong>
                            <span><?php esc_html_e('Never Resynced', 'dropshipzone'); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($never_synced_count > 0): ?>
                    <div style="margin-top: 15px;">
                        <button type="button" id="dsz-resync-never-synced" class="button button-primary">
                            <span class="dashicons dashicons-update" style="line-height: 1.4;"></span>
                            <?php 
                            /* translators: %d: number of products */
                            echo esc_html(sprintf(__('Resync %d Never Synced Products', 'dropshipzone'), $never_synced_count)); 
                            ?>
                        </button>
                        <span id="dsz-resync-never-synced-status" style="margin-left: 10px;"></span>
                    </div>
                    <script>
                    jQuery(function($) {
                        $('#dsz-resync-never-synced').on('click', function() {
                            var $btn = $(this);
                            var $status = $('#dsz-resync-never-synced-status');
                            
                            if (!confirm('<?php echo esc_js(__('This will resync all never-synced products. Continue?', 'dropshipzone')); ?>')) {
                                return;
                            }
                            
                            $btn.prop('disabled', true);
                            $status.html('<span style="color:#666;"><span class="dashicons dashicons-update spin"></span> Processing...</span>');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'dsz_resync_never_synced',
                                    nonce: dsz_admin.nonce
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $status.html('<span style="color:green;">✓ ' + response.data.message + '</span>');
                                        setTimeout(function() { location.reload(); }, 2000);
                                    } else {
                                        $status.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                                        $btn.prop('disabled', false);
                                    }
                                },
                                error: function() {
                                    $status.html('<span style="color:red;">Request failed</span>');
                                    $btn.prop('disabled', false);
                                }
                            });
                        });
                    });
                    </script>
                    <?php endif; ?>
                    
                    <?php if ($unmapped_count > 0): ?>
                    <div style="margin-top: 15px;">
                        <button type="button" id="dsz-scan-unmapped" class="button button-secondary">
                            <span class="dashicons dashicons-search" style="line-height: 1.4;"></span>
                            <?php 
                            /* translators: %d: number of products */
                            echo esc_html(sprintf(__('Scan %d Unmapped Products', 'dropshipzone'), $unmapped_count)); 
                            ?>
                        </button>
                        <span id="dsz-scan-unmapped-status" style="margin-left: 10px;"></span>
                        <p class="description" style="margin-top: 5px;">
                            <?php esc_html_e('Checks if unmapped products exist in Dropshipzone. Found products will be linked; not-found products will be marked as Non-DSZ.', 'dropshipzone'); ?>
                        </p>
                    </div>
                    <script>
                    jQuery(function($) {
                        $('#dsz-scan-unmapped').on('click', function() {
                            var $btn = $(this);
                            var $status = $('#dsz-scan-unmapped-status');
                            
                            if (!confirm('<?php echo esc_js(__('This will check all unmapped products against Dropshipzone API. Products found will be linked; products not found will be marked as Non-DSZ. Continue?', 'dropshipzone')); ?>')) {
                                return;
                            }
                            
                            $btn.prop('disabled', true);
                            $status.html('<span style="color:#666;"><span class="dashicons dashicons-update spin"></span> Scanning products...</span>');
                            
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'dsz_scan_unmapped_products',
                                    nonce: dsz_admin.nonce
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $status.html('<span style="color:green;">✓ ' + response.data.message + '</span>');
                                        setTimeout(function() { location.reload(); }, 2000);
                                    } else {
                                        $status.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                                        $btn.prop('disabled', false);
                                    }
                                },
                                error: function() {
                                    $status.html('<span style="color:red;">Request failed</span>');
                                    $btn.prop('disabled', false);
                                }
                            });
                        });
                    });
                    </script>
                    <?php endif; ?>
                </div>

                <!-- Sync Actions Callout -->
                <div class="dsz-form-section">
                    <div class="dsz-callout dsz-callout-info">
                        <span class="dashicons dashicons-info"></span>
                        <div>
                            <strong><?php esc_html_e('Looking for sync actions?', 'dropshipzone'); ?></strong>
                            <p><?php 
                            /* translators: %s: Link to Sync Center page */
                            printf(
                                esc_html__('Link products by SKU, update prices & stock, and refresh product data from the %s.', 'dropshipzone'),
                                '<a href="' . esc_url(admin_url('admin.php?page=dsz-sync-control')) . '">' . esc_html__('Sync Center', 'dropshipzone') . '</a>'
                            ); 
                            ?></p>
                        </div>
                    </div>
                </div>

                <!-- Search and Add New -->
                <div class="dsz-form-section">
                    <h2><?php esc_html_e('Add New Mapping', 'dropshipzone'); ?></h2>
                    <div class="dsz-mapping-add">
                        <div class="dsz-mapping-field">
                            <label><?php esc_html_e('WooCommerce Product:', 'dropshipzone'); ?></label>
                            <input type="text" id="dsz-wc-search" placeholder="<?php esc_attr_e('Search by name or SKU...', 'dropshipzone'); ?>" />
                            <div id="dsz-wc-results" class="dsz-search-results hidden"></div>
                            <input type="hidden" id="dsz-wc-product-id" value="" />
                        </div>
                        <div class="dsz-mapping-arrow">→</div>
                        <div class="dsz-mapping-field">
                            <label><?php esc_html_e('Dropshipzone SKU:', 'dropshipzone'); ?></label>
                            <input type="text" id="dsz-dsz-sku" placeholder="<?php esc_attr_e('Enter DSZ SKU or search...', 'dropshipzone'); ?>" />
                            <div id="dsz-dsz-results" class="dsz-search-results hidden"></div>
                        </div>
                        <button type="button" id="dsz-create-mapping" class="button button-primary" disabled>
                            <?php esc_html_e('Create Mapping', 'dropshipzone'); ?>
                        </button>
                    </div>
                    <div id="dsz-mapping-message" class="dsz-message hidden"></div>
                </div>

                <!-- Existing Mappings -->
                <div class="dsz-form-section">
                    <h2><?php esc_html_e('Existing Mappings', 'dropshipzone'); ?></h2>
                    
                    <!-- Search and Filter -->
                    <form method="get" class="dsz-mapping-search" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="hidden" name="page" value="dsz-sync-mapping" />
                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search mappings...', 'dropshipzone'); ?>" style="min-width: 200px;" />
                        <select name="resync_filter" style="min-width: 150px;">
                            <option value=""><?php esc_html_e('All Resync Status', 'dropshipzone'); ?></option>
                            <option value="never" <?php selected($resync_filter, 'never'); ?>><?php esc_html_e('Never Resynced', 'dropshipzone'); ?></option>
                            <option value="today" <?php selected($resync_filter, 'today'); ?>><?php esc_html_e('Resynced Today', 'dropshipzone'); ?></option>
                            <option value="week" <?php selected($resync_filter, 'week'); ?>><?php esc_html_e('Last 7 Days', 'dropshipzone'); ?></option>
                            <option value="month" <?php selected($resync_filter, 'month'); ?>><?php esc_html_e('Last 30 Days', 'dropshipzone'); ?></option>
                            <option value="older" <?php selected($resync_filter, 'older'); ?>><?php esc_html_e('Older than 30 Days', 'dropshipzone'); ?></option>
                        </select>
                        <button type="submit" class="button"><?php esc_html_e('Filter', 'dropshipzone'); ?></button>
                        <?php if ($search || $resync_filter): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-mapping')); ?>" class="button"><?php esc_html_e('Clear', 'dropshipzone'); ?></a>
                        <?php endif; ?>
                    </form>

                    <!-- Mappings Table -->
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('WooCommerce Product', 'dropshipzone'); ?></th>
                                <th><?php esc_html_e('Dropshipzone SKU', 'dropshipzone'); ?></th>
                                <th><?php esc_html_e('Last Resynced', 'dropshipzone'); ?></th>
                                <th class="column-actions"><?php esc_html_e('Actions', 'dropshipzone'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mappings)): ?>
                                <tr>
                                    <td colspan="4" class="dsz-no-logs"><?php esc_html_e('No mappings found.', 'dropshipzone'); ?></td>
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
                                        <td><?php echo isset($mapping['last_resynced']) && $mapping['last_resynced'] ? esc_html(dsz_format_datetime($mapping['last_resynced'])) : esc_html__('Never', 'dropshipzone'); ?></td>
                                        <td class="column-actions">
                                            <button type="button" class="button button-small dsz-resync-btn" data-product-id="<?php echo esc_attr($mapping['wc_product_id']); ?>" data-sku="<?php echo esc_attr($mapping['dsz_sku']); ?>">
                                                <span class="dashicons dashicons-update"></span>
                                                <?php esc_html_e('Resync', 'dropshipzone'); ?>
                                            </button>
                                            <button type="button" class="button button-small dsz-unmap-btn" data-wc-id="<?php echo esc_attr($mapping['wc_product_id']); ?>">
                                                <span class="dashicons dashicons-no-alt"></span>
                                                <?php esc_html_e('Unmap', 'dropshipzone'); ?>
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
                            $base_url = admin_url('admin.php?page=dsz-sync-mapping' . ($search ? '&search=' . urlencode($search) : '') . ($resync_filter ? '&resync_filter=' . urlencode($resync_filter) : ''));
                            
                            if ($page > 1): ?>
                                <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="button">&laquo; <?php esc_html_e('Previous', 'dropshipzone'); ?></a>
                            <?php endif; ?>
                            
                            <span class="dsz-pagination-info">
                                <?php
                                /* translators: %1$d: current page, %2$d: total pages */
                                echo esc_html(sprintf(__('Page %1$d of %2$d', 'dropshipzone'), $page, $total_pages));
                                ?>
                            </span>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="button"><?php esc_html_e('Next', 'dropshipzone'); ?> &raquo;</a>
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $wc_product_id = isset($_POST['wc_product_id']) ? intval($_POST['wc_product_id']) : 0;
        $dsz_sku = isset($_POST['dsz_sku']) ? sanitize_text_field($_POST['dsz_sku']) : '';

        if (!$wc_product_id || !$dsz_sku) {
            wp_send_json_error(['message' => __('Product ID and SKU are required', 'dropshipzone')]);
        }

        $result = $this->product_mapper->map($wc_product_id, $dsz_sku);

        if ($result) {
            wp_send_json_success(['message' => __('Mapping created successfully', 'dropshipzone')]);
        } else {
            wp_send_json_error(['message' => __('Failed to create mapping', 'dropshipzone')]);
        }
    }

    /**
     * AJAX: Unmap product
     */
    public function ajax_unmap_product() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $wc_product_id = isset($_POST['wc_product_id']) ? intval($_POST['wc_product_id']) : 0;

        if (!$wc_product_id) {
            wp_send_json_error(['message' => __('Product ID is required', 'dropshipzone')]);
        }

        $result = $this->product_mapper->unmap($wc_product_id);

        if ($result) {
            wp_send_json_success(['message' => __('Mapping removed successfully', 'dropshipzone')]);
        } else {
            wp_send_json_error(['message' => __('Failed to remove mapping', 'dropshipzone')]);
        }
    }

    /**
     * AJAX: Auto-map products
     */
    public function ajax_auto_map() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $results = $this->product_mapper->auto_map_by_sku();

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$d: mapped count, %2$d: skipped count */
                __('Auto-mapping complete! %1$d products mapped, %2$d skipped.', 'dropshipzone'),
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
            wp_die(__('You do not have permission to access this page.', 'dropshipzone'));
        }
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Product Import', 'dropshipzone'), __('Discover and import products from Dropshipzone catalog', 'dropshipzone')); ?>

            <!-- Hero Search Section -->
            <div class="dsz-import-hero">
                <div class="dsz-import-hero-content">
                    <h2><?php esc_html_e('Find Products to Import', 'dropshipzone'); ?></h2>
                    <p><?php esc_html_e('Search by keywords, SKU, or browse categories to find products for your store.', 'dropshipzone'); ?></p>
                    
                    <div class="dsz-import-search-wrapper">
                        <div class="dsz-import-search-box">
                            <span class="dashicons dashicons-search"></span>
                            <input type="text" id="dsz-import-search" placeholder="<?php esc_attr_e('Enter keywords, SKU, or product name...', 'dropshipzone'); ?>" />
                            <button type="button" id="dsz-import-search-btn" class="button button-primary button-hero">
                                <?php esc_html_e('Search Products', 'dropshipzone'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Filter Cards -->
            <div class="dsz-import-quick-filters">
                <div class="dsz-quick-filter-card" data-filter="in_stock">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('In Stock', 'dropshipzone'); ?></span>
                </div>
                <div class="dsz-quick-filter-card" data-filter="on_promotion">
                    <span class="dashicons dashicons-tag"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('On Sale', 'dropshipzone'); ?></span>
                </div>
                <div class="dsz-quick-filter-card" data-filter="free_shipping">
                    <span class="dashicons dashicons-car"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('Free Shipping', 'dropshipzone'); ?></span>
                </div>
                <div class="dsz-quick-filter-card" data-filter="new_arrival">
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('New Arrivals', 'dropshipzone'); ?></span>
                </div>
            </div>

            <div class="dsz-content">
                <!-- Advanced Filters (Collapsible) -->
                <div class="dsz-import-filters-section">
                    <button type="button" id="dsz-toggle-filters" class="dsz-toggle-filters-btn">
                        <span class="dashicons dashicons-filter"></span>
                        <?php esc_html_e('Advanced Filters', 'dropshipzone'); ?>
                        <span class="dashicons dashicons-arrow-down-alt2 dsz-toggle-arrow"></span>
                    </button>
                    
                    <div id="dsz-filters-panel" class="dsz-filters-panel hidden">
                        <div class="dsz-filters-grid">
                            <!-- Category Filter -->
                            <div class="dsz-filter-item">
                                <label for="dsz-filter-category"><?php esc_html_e('Category', 'dropshipzone'); ?></label>
                                <div class="dsz-filter-select-wrapper">
                                    <select id="dsz-filter-category">
                                        <option value=""><?php esc_html_e('All Categories', 'dropshipzone'); ?></option>
                                    </select>
                                    <button type="button" id="dsz-load-categories" class="button button-small" title="<?php esc_attr_e('Load categories from API', 'dropshipzone'); ?>">
                                        <span class="dashicons dashicons-update"></span>
                                    </button>
                                </div>
                            </div>

                            <!-- Sort Options -->
                            <div class="dsz-filter-item">
                                <label for="dsz-filter-sort"><?php esc_html_e('Sort By', 'dropshipzone'); ?></label>
                                <select id="dsz-filter-sort">
                                    <option value=""><?php esc_html_e('Default', 'dropshipzone'); ?></option>
                                    <option value="price_asc"><?php esc_html_e('Price: Low to High', 'dropshipzone'); ?></option>
                                    <option value="price_desc"><?php esc_html_e('Price: High to Low', 'dropshipzone'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="dsz-filters-actions">
                            <button type="button" id="dsz-apply-filters" class="button button-primary">
                                <span class="dashicons dashicons-yes"></span>
                                <?php esc_html_e('Apply Filters', 'dropshipzone'); ?>
                            </button>
                            <button type="button" id="dsz-clear-filters" class="button button-secondary">
                                <span class="dashicons dashicons-no-alt"></span>
                                <?php esc_html_e('Clear All', 'dropshipzone'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Hidden checkboxes for quick filters (JS interacts with these) -->
                <div style="display:none;">
                    <input type="checkbox" id="dsz-filter-instock" value="1">
                    <input type="checkbox" id="dsz-filter-freeship" value="1">
                    <input type="checkbox" id="dsz-filter-promotion" value="1">
                    <input type="checkbox" id="dsz-filter-newarrivals" value="1">
                </div>

                <!-- Search Results Info -->
                <div id="dsz-search-info" class="dsz-search-info hidden">
                    <span id="dsz-result-count"></span>
                    <span id="dsz-active-filters"></span>
                </div>

                <div id="dsz-import-results" class="dsz-import-results-container">
                    <div class="dsz-import-empty">
                        <span class="dashicons dashicons-products"></span>
                        <h3><?php esc_html_e('Ready to Import', 'dropshipzone'); ?></h3>
                        <p><?php esc_html_e('Search for products or click a quick filter above to browse the Dropshipzone catalog.', 'dropshipzone'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Search API products (Advanced)
     * Supports keywords, category, stock status, promotions, free shipping, new arrivals, and sorting
     */
    public function ajax_search_api_products() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        // Get search parameters
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $in_stock = isset($_POST['in_stock']) && $_POST['in_stock'] === 'true';
        $free_shipping = isset($_POST['free_shipping']) && $_POST['free_shipping'] === 'true';
        $on_promotion = isset($_POST['on_promotion']) && $_POST['on_promotion'] === 'true';
        $new_arrival = isset($_POST['new_arrival']) && $_POST['new_arrival'] === 'true';
        $sort = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : '';

        // Validate: need at least a search term, category, or one filter
        $has_filters = $category_id > 0 || $in_stock || $free_shipping || $on_promotion || $new_arrival;
        if (strlen($search) < 2 && !$has_filters) {
            wp_send_json_error(['message' => __('Enter at least 2 characters or select a filter.', 'dropshipzone')]);
        }

        // Build API query parameters
        $api_params = [
            'limit' => 100,
        ];

        // Add filters
        if ($category_id > 0) $api_params['category_id'] = $category_id;
        if ($free_shipping) $api_params['au_free_shipping'] = true;
        if ($on_promotion) $api_params['on_promotion'] = true;
        if ($new_arrival) $api_params['new_arrival'] = true;
        if (!empty($sort)) {
            if ($sort === 'price_asc') {
                $api_params['sort_by'] = 'price';
                $api_params['sort_order'] = 'asc';
            } elseif ($sort === 'price_desc') {
                $api_params['sort_by'] = 'price';
                $api_params['sort_order'] = 'desc';
            }
        }

        $products = [];
        $last_error = null;
        
        // If we have a search term, try it as both SKU and keywords
        if (!empty($search)) {
            // 1. Try exact SKU match with ALL filters applied
            $sku_params = $api_params;
            $sku_params['skus'] = $search;
            $sku_params['limit'] = 10;
            
            $sku_response = $this->api_client->get_products($sku_params);
            if (is_wp_error($sku_response)) {
                $last_error = $sku_response;
            } elseif (!empty($sku_response['result'])) {
                $products = $sku_response['result'];
            }
            
            // 2. If no SKU results (or we want to find similar items via keywords), use keyword search
            if (empty($products)) {
                $keyword_params = $api_params;
                $keyword_params['keywords'] = str_replace([' ', '+'], ',', trim($search));
                
                $response = $this->api_client->get_products($keyword_params);
                if (is_wp_error($response)) {
                    $last_error = $response;
                } elseif (!empty($response['result'])) {
                    $products = $response['result'];
                }
            }
        } else {
            // No search term, just use filters (browse mode)
            $response = $this->api_client->get_products($api_params);
            if (is_wp_error($response)) {
                $last_error = $response;
            } elseif (!empty($response['result'])) {
                $products = $response['result'];
            }
        }

        // PHP-side filter: Remove products with 0 stock (API filter may not be reliable)
        if (!empty($products)) {
            $products = array_filter($products, function($product) {
                $stock_qty = isset($product['stock_qty']) ? intval($product['stock_qty']) : 0;
                return $stock_qty > 0;
            });
            $products = array_values($products); // Re-index array
        }

        if (empty($products)) {
            if ($last_error) {
                wp_send_json_error(['message' => $last_error->get_error_message()]);
            }
            
            $message = __('No products found.', 'dropshipzone');
            if (!empty($search)) {
                $message .= ' ' . __('Try different keywords or adjust filters.', 'dropshipzone');
            } else {
                $message .= ' ' . __('Try adjusting your filters.', 'dropshipzone');
            }
            wp_send_json_error(['message' => $message]);
        }
        
        // Pre-check if products are already mapped/imported
        foreach ($products as &$product) {
            $wc_product_id = wc_get_product_id_by_sku($product['sku']);
            $product['is_imported'] = !empty($wc_product_id);
            $product['wc_product_id'] = $wc_product_id ? $wc_product_id : null;
        }

        // Build response with metadata
        $response_data = [
            'products' => $products,
            'total' => count($products),
            'filters_applied' => array_filter([
                'search' => $search ?: null,
                'category_id' => $category_id ?: null,
                'in_stock' => $in_stock ?: null,
                'free_shipping' => $free_shipping ?: null,
                'on_promotion' => $on_promotion ?: null,
                'new_arrival' => $new_arrival ?: null,
                'sort' => $sort ?: null,
            ]),
        ];

        wp_send_json_success($response_data);
    }

    /**
     * AJAX: Get categories from API
     */
    public function ajax_get_categories() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $response = $this->api_client->make_request('GET', '/v2/categories', [], true);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to fetch categories', [
                'error' => $response->get_error_message(),
            ]);
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        // Handle both direct array and result-wrapped response
        $categories = [];
        if (is_array($response)) {
            if (isset($response['result']) && is_array($response['result'])) {
                $categories = $response['result'];
            } elseif (isset($response['categories']) && is_array($response['categories'])) {
                $categories = $response['categories'];
            } elseif (!isset($response['result']) && !isset($response['categories'])) {
                // Response might be the categories array directly
                $categories = $response;
            }
        }

        if (empty($categories)) {
            $this->logger->warning('No categories returned from API');
            wp_send_json_error(['message' => __('No categories found from API.', 'dropshipzone')]);
        }

        $this->logger->info('Categories loaded', ['count' => count($categories)]);

        // Return the flat list of categories
        wp_send_json_success(['categories' => $categories]);
    }

    /**
     * AJAX: Import product
     */
    public function ajax_import_product() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        if (!$sku) {
            wp_send_json_error(['message' => __('SKU is required', 'dropshipzone')]);
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
            'message' => __('Product imported successfully!', 'dropshipzone'),
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
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        
        if (!$product_id && !$sku) {
            wp_send_json_error(['message' => __('Product ID or SKU is required', 'dropshipzone')]);
        }

        // If we only have SKU, try to find the product ID
        if (!$product_id && $sku) {
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                wp_send_json_error(['message' => __('Product not found in WooCommerce', 'dropshipzone')]);
            }
        }

        // Get resync options from request
        $options = [];
        if (isset($_POST['update_images'])) {
            $options['update_images'] = filter_var(wp_unslash($_POST['update_images']), FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_description'])) {
            $options['update_description'] = filter_var(wp_unslash($_POST['update_description']), FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_price'])) {
            $options['update_price'] = filter_var(wp_unslash($_POST['update_price']), FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_stock'])) {
            $options['update_stock'] = filter_var(wp_unslash($_POST['update_stock']), FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_POST['update_title'])) {
            $options['update_title'] = filter_var(wp_unslash($_POST['update_title']), FILTER_VALIDATE_BOOLEAN);
        }

        // Check if product data was passed from search results
        $product_data = null;
        if (isset($_POST['product_data']) && !empty($_POST['product_data'])) {
            $product_data = json_decode(wp_unslash($_POST['product_data']), true);
        }

        // Perform resync
        $result = $this->product_importer->resync_product($product_id, $product_data, $options);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Product resynced successfully!', 'dropshipzone'),
            'product_id' => $result,
            'edit_url' => get_edit_post_link($result, 'url')
        ]);
    }

    /**
     * AJAX: Resync all mapped products
     * 
     * Skips products that are already inactive (draft + out of stock)
     * to optimize performance and avoid unnecessary updates.
     */
    public function ajax_resync_all() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        // Get all mappings
        $mappings = $this->product_mapper->get_mappings(['limit' => 1000]);
        
        if (empty($mappings)) {
            wp_send_json_error(['message' => __('No mapped products found to resync.', 'dropshipzone')]);
        }

        $total = count($mappings);
        $success_count = 0;
        $error_count = 0;
        $skipped_inactive = 0;
        $errors = [];

        // First pass: Filter out inactive products (draft + out of stock)
        // and build list of SKUs that actually need syncing
        $active_mappings = [];
        $all_skus = [];
        
        foreach ($mappings as $mapping) {
            $product_id = intval($mapping['wc_product_id']);
            $sku = $mapping['dsz_sku'];
            
            // Load the WooCommerce product to check its status
            $product = wc_get_product($product_id);
            
            if (!$product) {
                // Product doesn't exist in WooCommerce, skip
                $this->logger->debug('Skipped resync - product not found in WooCommerce', [
                    'wc_product_id' => $product_id,
                    'dsz_sku' => $sku,
                ]);
                continue;
            }
            
            $product_status = $product->get_status();
            $stock_qty = $product->get_stock_quantity();
            $stock_status = $product->get_stock_status();
            
            // Skip products that are already inactive (draft + out of stock)
            // These products don't need updating since they're already inactive
            if ($product_status === 'draft' && ($stock_qty <= 0 || $stock_status === 'outofstock')) {
                $skipped_inactive++;
                $this->logger->debug('Skipped resync - product already inactive (draft + out of stock)', [
                    'wc_product_id' => $product_id,
                    'dsz_sku' => $sku,
                    'product_name' => $product->get_name(),
                    'status' => $product_status,
                    'stock_qty' => $stock_qty,
                ]);
                continue;
            }
            
            // This product needs syncing
            $active_mappings[] = $mapping;
            $all_skus[] = $sku;
        }
        
        // Log the filtering results
        $this->logger->info('Resync filtering complete', [
            'total_mapped' => $total,
            'active_to_sync' => count($active_mappings),
            'skipped_inactive' => $skipped_inactive,
        ]);
        
        // If no active products to sync, return early
        if (empty($active_mappings)) {
            $message = sprintf(
                /* translators: %d: number of skipped products */
                __('No products need resyncing. %d products skipped (already draft + out of stock).', 'dropshipzone'),
                $skipped_inactive
            );
            wp_send_json_success([
                'message' => $message,
                'total' => $total,
                'success' => 0,
                'errors' => 0,
                'skipped_inactive' => $skipped_inactive,
                'error_details' => [],
            ]);
            return; // Prevent further execution
        }

        // Batch fetch product data from API (100 SKUs per request, processed sequentially)
        $api_products = [];
        $sku_chunks = array_chunk($all_skus, 100);
        
        $this->logger->info('Starting batch resync - API fetch', [
            'products_to_sync' => count($active_mappings),
            'api_batches' => count($sku_chunks),
            'skipped_inactive' => $skipped_inactive,
        ]);

        // Process API requests SEQUENTIALLY (one batch at a time)
        foreach ($sku_chunks as $chunk_index => $chunk) {
            $this->logger->debug('Fetching API batch', [
                'batch' => $chunk_index + 1,
                'total_batches' => count($sku_chunks),
                'skus_in_batch' => count($chunk),
            ]);
            
            $response = $this->api_client->get_products_by_skus($chunk);
            
            if (is_wp_error($response)) {
                $this->logger->error('Batch fetch failed', [
                    'batch' => $chunk_index + 1,
                    'error' => $response->get_error_message(),
                ]);
                continue;
            }

            if (!empty($response['result'])) {
                foreach ($response['result'] as $product_data) {
                    if (!empty($product_data['sku'])) {
                        $api_products[$product_data['sku']] = $product_data;
                    }
                }
            }
            
            $this->logger->debug('API batch complete', [
                'batch' => $chunk_index + 1,
                'products_fetched' => isset($response['result']) ? count($response['result']) : 0,
            ]);
        }

        $this->logger->info('API batch fetch complete', [
            'requested_skus' => count($all_skus),
            'found_products' => count($api_products),
        ]);

        // Now process each active mapping with pre-fetched data (sequentially)
        foreach ($active_mappings as $mapping) {
            $product_id = $mapping['wc_product_id'];
            $sku = $mapping['dsz_sku'];

            // Check if we have API data for this SKU
            if (!isset($api_products[$sku])) {
                $error_count++;
                $errors[] = sprintf('%s: %s', $sku, __('Not found in Dropshipzone API', 'dropshipzone'));
                continue;
            }

            $api_data = $api_products[$sku];

            // Resync the product with pre-fetched data
            $result = $this->product_importer->resync_product($product_id, $api_data, [
                'update_price' => true,
                'update_stock' => true,
                'update_images' => true,
                'update_description' => true,
                'update_title' => true,
                'update_categories' => true,
            ]);

            if (is_wp_error($result)) {
                $error_count++;
                $errors[] = sprintf('%s: %s', $sku, $result->get_error_message());
            } else {
                $success_count++;
            }

            // Memory check
            if (dsz_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping resync early', [
                    'processed' => $success_count + $error_count,
                    'total' => count($active_mappings),
                ]);
                break;
            }
        }

        // Build success message
        $message = sprintf(
            /* translators: %1$d: success count, %2$d: total active count */
            __('Resync complete! %1$d of %2$d products updated successfully.', 'dropshipzone'),
            $success_count,
            count($active_mappings)
        );

        if ($skipped_inactive > 0) {
            /* translators: %d: number of skipped products */
            $message .= ' ' . sprintf(__('%d inactive products skipped.', 'dropshipzone'), $skipped_inactive);
        }

        if ($error_count > 0) {
            /* translators: %d: error count */
            $message .= ' ' . sprintf(__('%d errors occurred.', 'dropshipzone'), $error_count);
        }

        $this->logger->info('Resync all complete', [
            'total_mapped' => $total,
            'active_synced' => count($active_mappings),
            'success' => $success_count,
            'errors' => $error_count,
            'skipped_inactive' => $skipped_inactive,
        ]);

        wp_send_json_success([
            'message' => $message,
            'total' => $total,
            'active' => count($active_mappings),
            'success' => $success_count,
            'errors' => $error_count,
            'skipped_inactive' => $skipped_inactive,
            'error_details' => array_slice($errors, 0, 10), // Return first 10 errors
        ]);
    }

    /**
     * AJAX handler for resyncing ONLY product images
     */
    public function ajax_resync_images() {
        $this->resync_specific('images');
    }

    /**
     * AJAX handler for resyncing ONLY product categories
     */
    public function ajax_resync_categories() {
        $this->resync_specific('categories');
    }

    /**
     * Shared handler for specific resync types (images or categories)
     * 
     * @param string $type 'images' or 'categories'
     */
    private function resync_specific($type) {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        // Get all mappings
        $mappings = $this->product_mapper->get_mappings(['limit' => 1000]);
        
        if (empty($mappings)) {
            wp_send_json_error(['message' => __('No mapped products found.', 'dropshipzone')]);
        }

        $total = count($mappings);
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        // Collect all SKUs for batch API lookup
        $all_skus = [];
        $sku_to_mapping = [];
        foreach ($mappings as $mapping) {
            $sku = $mapping['dsz_sku'];
            $all_skus[] = $sku;
            $sku_to_mapping[$sku] = $mapping;
        }

        // Batch fetch from API
        $api_products = [];
        $sku_chunks = array_chunk($all_skus, 100);
        
        $this->logger->info('Starting ' . $type . ' resync - API fetch', [
            'products_to_sync' => count($mappings),
            'api_batches' => count($sku_chunks),
        ]);

        foreach ($sku_chunks as $chunk_index => $chunk) {
            $response = $this->api_client->get_products_by_skus($chunk);
            
            if (is_wp_error($response)) {
                $this->logger->error($type . ' resync batch fetch failed', [
                    'batch' => $chunk_index + 1,
                    'error' => $response->get_error_message(),
                ]);
                continue;
            }

            if (!empty($response['result'])) {
                foreach ($response['result'] as $product_data) {
                    if (!empty($product_data['sku'])) {
                        $api_products[$product_data['sku']] = $product_data;
                    }
                }
            }
        }

        // Define which options to update based on type
        $options = [
            'update_images' => ($type === 'images'),
            'update_categories' => ($type === 'categories'),
            'update_description' => false,
            'update_price' => false,
            'update_stock' => false,
            'update_title' => false,
        ];

        // Process each product
        foreach ($mappings as $mapping) {
            $product_id = $mapping['wc_product_id'];
            $sku = $mapping['dsz_sku'];

            if (!isset($api_products[$sku])) {
                $error_count++;
                $errors[] = sprintf('%s: %s', $sku, __('Not found in API', 'dropshipzone'));
                continue;
            }

            $api_data = $api_products[$sku];
            $result = $this->product_importer->resync_product($product_id, $api_data, $options);

            if (is_wp_error($result)) {
                $error_count++;
                $errors[] = sprintf('%s: %s', $sku, $result->get_error_message());
            } else {
                $success_count++;
            }

            // Memory check
            if (dsz_is_memory_near_limit(85)) {
                $this->logger->warning($type . ' resync stopped early due to memory limit');
                break;
            }
        }

        $type_label = ($type === 'images') ? __('images', 'dropshipzone') : __('categories', 'dropshipzone');
        
        /* translators: %1$d: success count, %2$d: total count, %3$s: type label */
        $message = sprintf(
            __('Refreshed %3$s for %1$d of %2$d products.', 'dropshipzone'),
            $success_count,
            $total,
            $type_label
        );

        if ($error_count > 0) {
            /* translators: %d: error count */
            $message .= ' ' . sprintf(__('%d errors.', 'dropshipzone'), $error_count);
        }

        $this->logger->info($type . ' resync complete', [
            'success' => $success_count,
            'errors' => $error_count,
        ]);

        wp_send_json_success([
            'message' => $message,
            'success' => $success_count,
            'errors' => $error_count,
            'error_details' => array_slice($errors, 0, 10),
        ]);
    }

    /**
     * AJAX handler for resyncing products that have never been synced
     * 
     * Uses batch API fetching to reduce API calls (similar to ajax_resync_all)
     */
    public function ajax_resync_never_synced() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        // Get all mappings where last_resynced is NULL
        $never_synced = $this->product_mapper->get_mappings([
            'resync_filter' => 'never',
            'limit' => 500,
            'offset' => 0,
        ]);

        if (empty($never_synced)) {
            wp_send_json_success([
                'message' => __('No products to resync.', 'dropshipzone'),
                'total' => 0,
                'success' => 0,
                'errors' => 0,
            ]);
            return; // Prevent further execution
        }

        $success_count = 0;
        $error_count = 0;
        $errors = [];

        // Collect all SKUs for batch fetching
        $all_skus = [];
        foreach ($never_synced as $mapping) {
            $all_skus[] = $mapping['dsz_sku'];
        }

        // Batch fetch product data from API (100 SKUs per request)
        $api_products = [];
        $sku_chunks = array_chunk($all_skus, 100);
        
        $this->logger->info('Starting batch resync for never-synced products - API fetch', [
            'products_to_sync' => count($never_synced),
            'api_batches' => count($sku_chunks),
        ]);

        // Process API requests sequentially (one batch at a time)
        foreach ($sku_chunks as $chunk_index => $chunk) {
            $this->logger->debug('Fetching API batch for never-synced', [
                'batch' => $chunk_index + 1,
                'total_batches' => count($sku_chunks),
                'skus_in_batch' => count($chunk),
            ]);
            
            $response = $this->api_client->get_products_by_skus($chunk);
            
            if (is_wp_error($response)) {
                $this->logger->error('Batch fetch failed for never-synced', [
                    'batch' => $chunk_index + 1,
                    'error' => $response->get_error_message(),
                ]);
                continue;
            }

            if (!empty($response['result'])) {
                foreach ($response['result'] as $product_data) {
                    if (!empty($product_data['sku'])) {
                        $api_products[$product_data['sku']] = $product_data;
                    }
                }
            }
        }

        $this->logger->info('API batch fetch complete for never-synced', [
            'requested_skus' => count($all_skus),
            'found_products' => count($api_products),
        ]);

        // Now process each product with pre-fetched data
        foreach ($never_synced as $mapping) {
            $product_id = $mapping['wc_product_id'];
            $sku = $mapping['dsz_sku'];

            // Check if we have API data for this SKU
            if (!isset($api_products[$sku])) {
                $error_count++;
                $errors[] = [
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'error' => __('Not found in Dropshipzone API', 'dropshipzone'),
                ];
                continue;
            }

            $api_data = $api_products[$sku];

            // Resync with pre-fetched data (price and stock only for never-synced)
            $result = $this->product_importer->resync_product($product_id, $api_data, [
                'update_title' => false,
                'update_description' => false,
                'update_images' => false,
                'update_price' => true,
                'update_stock' => true,
            ]);

            if (is_wp_error($result)) {
                $error_count++;
                $errors[] = [
                    'product_id' => $product_id,
                    'sku' => $sku,
                    'error' => $result->get_error_message(),
                ];
            } else {
                $success_count++;
            }

            // Memory check
            if (dsz_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping never-synced resync early', [
                    'processed' => $success_count + $error_count,
                    'total' => count($never_synced),
                ]);
                break;
            }
        }

        /* translators: %1$d: success count, %2$d: total count */
        $message = sprintf(__('Resynced %1$d of %2$d never-synced products.', 'dropshipzone'), $success_count, count($never_synced));

        if ($error_count > 0) {
            /* translators: %d: error count */
            $message .= ' ' . sprintf(__('%d errors occurred.', 'dropshipzone'), $error_count);
        }

        wp_send_json_success([
            'message' => $message,
            'total' => count($never_synced),
            'success' => $success_count,
            'errors' => $error_count,
            'error_details' => array_slice($errors, 0, 10),
        ]);
    }

    /**
     * AJAX handler for scanning unmapped products against Dropshipzone API
     * 
     * Checks if unmapped WC products exist in DSZ:
     * - Found: creates mapping and resyncs
     * - Not found: marks as non-DSZ product (_dsz_not_available meta)
     */
    public function ajax_scan_unmapped_products() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        // Get all WC products that are NOT in our mapping table
        global $wpdb;
        $mapping_table = $wpdb->prefix . 'dsz_product_mapping';
        
        // Get products with SKUs that are not already mapped
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $unmapped_products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_value as sku 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                LEFT JOIN {$mapping_table} m ON p.ID = m.wc_product_id
                WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status != 'trash'
                AND pm.meta_value != ''
                AND m.id IS NULL
                ORDER BY p.ID DESC
                LIMIT %d",
                500
            ),
            ARRAY_A
        );

        if (empty($unmapped_products)) {
            wp_send_json_success([
                'message' => __('No unmapped products to scan.', 'dropshipzone'),
                'found' => 0,
                'not_found' => 0,
            ]);
            return;
        }

        $this->logger->info('Starting unmapped product scan', [
            'products_to_scan' => count($unmapped_products),
        ]);

        // Collect all SKUs for batch API lookup
        $all_skus = [];
        $sku_to_product = [];
        foreach ($unmapped_products as $product) {
            $sku = trim($product['sku']);
            if (!empty($sku)) {
                $all_skus[] = $sku;
                $sku_to_product[$sku] = intval($product['ID']);
            }
        }

        // Batch fetch from API (100 SKUs at a time)
        $api_products = [];
        $sku_chunks = array_chunk($all_skus, 100);
        
        foreach ($sku_chunks as $chunk_index => $chunk) {
            $this->logger->debug('Scanning API batch', [
                'batch' => $chunk_index + 1,
                'total_batches' => count($sku_chunks),
                'skus_in_batch' => count($chunk),
            ]);
            
            $response = $this->api_client->get_products_by_skus($chunk);
            
            if (is_wp_error($response)) {
                $this->logger->error('Scan batch fetch failed', [
                    'batch' => $chunk_index + 1,
                    'error' => $response->get_error_message(),
                ]);
                continue;
            }

            if (!empty($response['result'])) {
                foreach ($response['result'] as $product_data) {
                    if (!empty($product_data['sku'])) {
                        $api_products[$product_data['sku']] = $product_data;
                    }
                }
            }
        }

        $this->logger->info('Scan API fetch complete', [
            'requested_skus' => count($all_skus),
            'found_in_dsz' => count($api_products),
        ]);

        $found_count = 0;
        $not_found_count = 0;
        $errors = [];

        // Process each unmapped product
        foreach ($all_skus as $sku) {
            $product_id = $sku_to_product[$sku];
            
            if (isset($api_products[$sku])) {
                // Product FOUND in Dropshipzone - create mapping and resync
                $api_data = $api_products[$sku];
                
                // Create mapping
                $title = isset($api_data['title']) ? $api_data['title'] : '';
                $mapping_result = $this->product_mapper->map($product_id, $sku, $title);
                
                if ($mapping_result) {
                    // Clear any previous "not available" flag
                    delete_post_meta($product_id, '_dsz_not_available');
                    
                    // Resync the product with DSZ data
                    $this->product_importer->resync_product($product_id, $api_data, [
                        'update_price' => true,
                        'update_stock' => true,
                        'update_images' => false, // Don't replace user's images
                        'update_description' => false, // Don't replace user's description
                        'update_title' => false, // Don't replace user's title
                    ]);
                    
                    $found_count++;
                    $this->logger->info('Unmapped product linked to DSZ', [
                        'product_id' => $product_id,
                        'sku' => $sku,
                    ]);
                } else {
                    $errors[] = sprintf('%s: %s', $sku, __('Failed to create mapping', 'dropshipzone'));
                }
            } else {
                // Product NOT FOUND in Dropshipzone - mark as non-DSZ product
                update_post_meta($product_id, '_dsz_not_available', '1');
                $not_found_count++;
                
                $this->logger->debug('Product marked as non-DSZ', [
                    'product_id' => $product_id,
                    'sku' => $sku,
                ]);
            }

            // Memory check
            if (dsz_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping scan early', [
                    'processed' => $found_count + $not_found_count,
                    'total' => count($all_skus),
                ]);
                break;
            }
        }

        /* translators: %1$d: found count, %2$d: not found count */
        $message = sprintf(
            __('Scan complete! %1$d products linked to Dropshipzone, %2$d marked as non-DSZ products.', 'dropshipzone'),
            $found_count,
            $not_found_count
        );

        $this->logger->info('Unmapped product scan complete', [
            'total_scanned' => count($all_skus),
            'found' => $found_count,
            'not_found' => $not_found_count,
        ]);

        wp_send_json_success([
            'message' => $message,
            'found' => $found_count,
            'not_found' => $not_found_count,
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    /**
     * AJAX handler for submitting order to Dropshipzone
     */
    public function ajax_submit_order() {
        check_ajax_referer('dsz_sync_nonce', 'nonce');

        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied.', 'dropshipzone')]);
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('Order ID required.', 'dropshipzone')]);
        }

        if (!$this->order_handler) {
            wp_send_json_error(['message' => __('Order handler not initialized.', 'dropshipzone')]);
        }

        $result = $this->order_handler->submit_order($order_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Add meta box to WooCommerce order page
     */
    public function add_order_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'dsz-order-meta-box',
            __('Dropshipzone Order', 'dropshipzone'),
            [$this, 'render_order_meta_box'],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render order meta box content
     *
     * @param WP_Post|WC_Order $post_or_order Post or Order object
     */
    public function render_order_meta_box($post_or_order) {
        // Get order object (HPOS compatible)
        $order = $post_or_order instanceof \WP_Post ? wc_get_order($post_or_order->ID) : $post_or_order;
        
        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'dropshipzone') . '</p>';
            return;
        }

        $order_id = $order->get_id();

        // Check if order has DSZ products
        if (!$this->order_handler) {
            echo '<p>' . esc_html__('Order handler not available.', 'dropshipzone') . '</p>';
            return;
        }

        $has_dsz_products = $this->order_handler->order_has_dsz_products($order_id);

        if (!$has_dsz_products) {
            echo '<p style="color: #666;">' . esc_html__('No Dropshipzone products in this order.', 'dropshipzone') . '</p>';
            return;
        }

        // Get DSZ order info
        $dsz_order = $this->order_handler->get_dsz_order($order_id);
        $dsz_serial = $order->get_meta('_dsz_serial_number');

        wp_nonce_field('dsz_sync_nonce', 'dsz_order_nonce');
        ?>
        <div class="dsz-order-box">
            <?php if ($dsz_serial || ($dsz_order && !empty($dsz_order['dsz_serial_number']))): 
                $serial = $dsz_serial ?: $dsz_order['dsz_serial_number'];
                $status = $dsz_order ? $dsz_order['dsz_status'] : 'not_submitted';
            ?>
                <p>
                    <strong><?php esc_html_e('DSZ Serial:', 'dropshipzone'); ?></strong><br>
                    <code><?php echo esc_html($serial); ?></code>
                </p>
                <p>
                    <strong><?php esc_html_e('Status:', 'dropshipzone'); ?></strong><br>
                    <span class="dsz-status dsz-status-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?>
                    </span>
                </p>
                <?php if ($dsz_order && !empty($dsz_order['submitted_at'])): ?>
                <p>
                    <strong><?php esc_html_e('Submitted:', 'dropshipzone'); ?></strong><br>
                    <?php echo esc_html(dsz_format_datetime($dsz_order['submitted_at'])); ?>
                </p>
                <?php endif; ?>
            <?php elseif ($dsz_order && !empty($dsz_order['error_message'])): ?>
                <p class="dsz-error">
                    <strong><?php esc_html_e('Last Error:', 'dropshipzone'); ?></strong><br>
                    <?php echo esc_html($dsz_order['error_message']); ?>
                </p>
                <button type="button" class="button button-primary dsz-submit-order-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Retry Submit', 'dropshipzone'); ?>
                </button>
            <?php else: ?>
                <p><?php esc_html_e('This order has Dropshipzone products and can be submitted.', 'dropshipzone'); ?></p>
                <button type="button" class="button button-primary dsz-submit-order-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e('Submit to Dropshipzone', 'dropshipzone'); ?>
                </button>
            <?php endif; ?>
            <div class="dsz-order-message" style="margin-top: 10px;"></div>
        </div>
        <style>
            .dsz-order-box { padding: 5px 0; }
            .dsz-order-box p { margin: 8px 0; }
            .dsz-status { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; }
            .dsz-status-not_submitted { background: #fff3cd; color: #856404; }
            .dsz-status-processing { background: #cce5ff; color: #004085; }
            .dsz-status-complete { background: #d4edda; color: #155724; }
            .dsz-status-error { background: #f8d7da; color: #721c24; }
            .dsz-error { color: #dc3545; }
            .dsz-submit-order-btn .dashicons { vertical-align: middle; margin-right: 3px; }
        </style>
        <script>
        jQuery(function($) {
            $('.dsz-submit-order-btn').on('click', function() {
                var $btn = $(this);
                var orderId = $btn.data('order-id');
                var $message = $btn.siblings('.dsz-order-message');
                
                $btn.prop('disabled', true).find('.dashicons').addClass('spin');
                $message.html('<span style="color:#666;">Submitting...</span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dsz_submit_order',
                        nonce: $('#dsz_order_nonce').val(),
                        order_id: orderId
                    },
                    success: function(response) {
                        if (response.success) {
                            $message.html('<span style="color:green;">✓ ' + response.data.message + '</span>');
                            setTimeout(function() { location.reload(); }, 1500);
                        } else {
                            $message.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                            $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                        }
                    },
                    error: function() {
                        $message.html('<span style="color:red;">Request failed</span>');
                        $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Auto Import settings page
     */
    public function render_auto_import() {
        if (!dsz_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'dropshipzone'));
        }

        $settings = $this->auto_importer ? $this->auto_importer->get_settings() : [];
        $status = $this->auto_importer ? $this->auto_importer->get_status() : [];
        $next_scheduled = $this->auto_importer ? $this->auto_importer->get_next_scheduled() : false;
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Auto Import', 'dropshipzone'), __('Automatically import new products from Dropshipzone', 'dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-auto-import-form" class="dsz-form">
                    <?php wp_nonce_field('dsz_auto_import_settings', 'dsz_nonce'); ?>
                    
                    <!-- Status Section -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Auto Import Status', 'dropshipzone'); ?></h2>
                        <div class="dsz-cards" style="margin-bottom: 20px;">
                            <div class="dsz-card <?php echo esc_attr($settings['enabled'] ? 'dsz-card-success' : 'dsz-card-warning'); ?>">
                                <div class="dsz-card-icon">
                                    <span class="dashicons <?php echo esc_attr($settings['enabled'] ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Status', 'dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo esc_html($settings['enabled'] ? __('Enabled', 'dropshipzone') : __('Disabled', 'dropshipzone')); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Next Run', 'dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo $next_scheduled ? esc_html(dsz_time_ago($next_scheduled)) : esc_html__('Not Scheduled', 'dropshipzone'); ?></p>
                                </div>
                            </div>
                            <?php if (!empty($status['last_results'])): ?>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-download"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Last Run', 'dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($status['last_results']['imported']); ?> <?php esc_html_e('imported', 'dropshipzone'); ?></p>
                                    <?php if ($status['last_completed']): ?>
                                        <p class="dsz-card-meta"><?php echo esc_html(dsz_time_ago($status['last_completed'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="dsz-form-actions" style="margin-bottom: 30px;">
                            <button type="button" id="dsz-run-auto-import" class="button button-secondary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Run Import Now', 'dropshipzone'); ?>
                            </button>
                            <span id="dsz-auto-import-result" style="margin-left: 15px;"></span>
                        </div>
                    </div>

                    <!-- Import Metrics Section -->
                    <?php 
                    $stats = $this->auto_importer ? $this->auto_importer->get_stats() : [];
                    $history = $this->auto_importer ? $this->auto_importer->get_history(10) : [];
                    ?>
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Import Metrics', 'dropshipzone'); ?></h2>
                        <div class="dsz-cards" style="margin-bottom: 20px;">
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-chart-bar"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Total Imported', 'dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($stats['total_imported']); ?></p>
                                    <p class="dsz-card-meta"><?php echo intval($stats['total_runs']); ?> <?php esc_html_e('runs', 'dropshipzone'); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Last 7 Days', 'dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($stats['last_7_days']['imported']); ?></p>
                                    <p class="dsz-card-meta"><?php echo intval($stats['last_7_days']['runs']); ?> <?php esc_html_e('runs', 'dropshipzone'); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-calendar"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Last 30 Days', 'dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($stats['last_30_days']['imported']); ?></p>
                                    <p class="dsz-card-meta"><?php echo intval($stats['last_30_days']['runs']); ?> <?php esc_html_e('runs', 'dropshipzone'); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($history)): ?>
                        <h3 style="margin-bottom: 10px;"><?php esc_html_e('Recent Import History', 'dropshipzone'); ?></h3>
                        <table class="widefat striped" style="margin-bottom: 20px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', 'dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Imported', 'dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Skipped', 'dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Errors', 'dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Status', 'dropshipzone'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history as $entry): ?>
                                <tr>
                                    <td><?php echo esc_html(wp_date('M j, Y g:i a', $entry['timestamp'])); ?></td>
                                    <td><strong><?php echo intval($entry['imported']); ?></strong></td>
                                    <td><?php echo intval($entry['skipped']); ?></td>
                                    <td><?php echo intval($entry['errors']); ?></td>
                                    <td>
                                        <?php if ($entry['status'] === 'complete'): ?>
                                            <span style="color: var(--dsz-success);">✓ <?php esc_html_e('Complete', 'dropshipzone'); ?></span>
                                        <?php elseif ($entry['status'] === 'error'): ?>
                                            <span style="color: var(--dsz-error);">✗ <?php esc_html_e('Error', 'dropshipzone'); ?></span>
                                        <?php else: ?>
                                            <?php echo esc_html($entry['status']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p style="color: var(--dsz-gray-500);"><?php esc_html_e('No import history yet. Run an import to see metrics.', 'dropshipzone'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Settings Section -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Auto Import Settings', 'dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Auto Import', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'], true); ?> />
                                        <?php esc_html_e('Automatically import new products on schedule', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="frequency"><?php esc_html_e('Import Frequency', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <select name="frequency" id="frequency">
                                        <option value="hourly" <?php selected($settings['frequency'], 'hourly'); ?>><?php esc_html_e('Every Hour', 'dropshipzone'); ?></option>
                                        <option value="twicedaily" <?php selected($settings['frequency'], 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'dropshipzone'); ?></option>
                                        <option value="daily" <?php selected($settings['frequency'], 'daily'); ?>><?php esc_html_e('Once Daily', 'dropshipzone'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="max_products_per_run"><?php esc_html_e('Max Products Per Run', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="max_products_per_run" name="max_products_per_run" value="<?php echo esc_attr($settings['max_products_per_run']); ?>" min="1" max="200" class="small-text" />
                                    <p class="description"><?php esc_html_e('Maximum number of products to import per scheduled run (1-200)', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="min_stock_qty"><?php esc_html_e('Minimum Stock Quantity', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="min_stock_qty" name="min_stock_qty" value="<?php echo esc_attr(isset($settings['min_stock_qty']) ? $settings['min_stock_qty'] : 100); ?>" min="0" max="10000" class="small-text" />
                                    <p class="description"><?php esc_html_e('Only import products with at least this many units in stock (default: 100)', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="default_product_status"><?php esc_html_e('Default Product Status', 'dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <select name="default_product_status" id="default_product_status">
                                        <option value="publish" <?php selected($settings['default_product_status'], 'publish'); ?>><?php esc_html_e('Published', 'dropshipzone'); ?></option>
                                        <option value="draft" <?php selected($settings['default_product_status'], 'draft'); ?>><?php esc_html_e('Draft', 'dropshipzone'); ?></option>
                                        <option value="pending" <?php selected($settings['default_product_status'], 'pending'); ?>><?php esc_html_e('Pending Review', 'dropshipzone'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Status for newly imported products', 'dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Filters Section -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Import Filters', 'dropshipzone'); ?></h2>
                        <p class="description"><?php esc_html_e('Only products matching these filters will be imported.', 'dropshipzone'); ?></p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('New Arrivals Only', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_new_arrival" value="1" <?php checked($settings['filter_new_arrival'], true); ?> />
                                        <?php esc_html_e('Only import products marked as new arrivals', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('In Stock Only', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_in_stock" value="1" <?php checked($settings['filter_in_stock'], true); ?> />
                                        <?php esc_html_e('Only import products that are currently in stock', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Free Shipping Only', 'dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_free_shipping" value="1" <?php checked($settings['filter_free_shipping'], true); ?> />
                                        <?php esc_html_e('Only import products with free shipping in Australia', 'dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Settings', 'dropshipzone'); ?>
                        </button>
                    </div>

                    <div id="dsz-auto-import-message" class="dsz-message hidden"></div>
                </form>
            </div>
        </div>

        <script>
        jQuery(function($) {
            // Save settings
            $('#dsz-auto-import-form').on('submit', function(e) {
                e.preventDefault();
                var $form = $(this);
                var $btn = $form.find('button[type="submit"]');
                var $message = $('#dsz-auto-import-message');
                
                $btn.prop('disabled', true);
                
                $.ajax({
                    url: dsz_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dsz_save_auto_import_settings',
                        nonce: dsz_admin.nonce,
                        enabled: $form.find('input[name="enabled"]').is(':checked') ? 1 : 0,
                        frequency: $form.find('select[name="frequency"]').val(),
                        max_products_per_run: $form.find('input[name="max_products_per_run"]').val(),
                        min_stock_qty: $form.find('input[name="min_stock_qty"]').val(),
                        default_product_status: $form.find('select[name="default_product_status"]').val(),
                        filter_new_arrival: $form.find('input[name="filter_new_arrival"]').is(':checked') ? 1 : 0,
                        filter_in_stock: $form.find('input[name="filter_in_stock"]').is(':checked') ? 1 : 0,
                        filter_free_shipping: $form.find('input[name="filter_free_shipping"]').is(':checked') ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            $message.removeClass('hidden dsz-message-error').addClass('dsz-message-success').html('<span class="dashicons dashicons-yes"></span> ' + response.data.message);
                        } else {
                            $message.removeClass('hidden dsz-message-success').addClass('dsz-message-error').html('<span class="dashicons dashicons-no"></span> ' + response.data.message);
                        }
                        $btn.prop('disabled', false);
                    },
                    error: function() {
                        $message.removeClass('hidden dsz-message-success').addClass('dsz-message-error').text('Request failed');
                        $btn.prop('disabled', false);
                    }
                });
            });
            
            // Run import now
            $('#dsz-run-auto-import').on('click', function() {
                var $btn = $(this);
                var $result = $('#dsz-auto-import-result');
                
                $btn.prop('disabled', true);
                $result.html('<span class="dashicons dashicons-update spin"></span> <?php echo esc_js(__('Importing...', 'dropshipzone')); ?>');
                
                $.ajax({
                    url: dsz_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'dsz_run_auto_import',
                        nonce: dsz_admin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color:green;">✓ ' + response.data.message + '</span>');
                        } else {
                            $result.html('<span style="color:red;">✗ ' + response.data.message + '</span>');
                        }
                        $btn.prop('disabled', false);
                    },
                    error: function() {
                        $result.html('<span style="color:red;">Request failed</span>');
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler: Run auto import manually
     */
    public function ajax_run_auto_import() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');
        
        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        if (!$this->auto_importer) {
            wp_send_json_error(['message' => __('Auto importer not initialized', 'dropshipzone')]);
        }

        // Temporarily enable for manual run
        $settings = $this->auto_importer->get_settings();
        $was_enabled = $settings['enabled'];
        
        if (!$was_enabled) {
            $settings['enabled'] = true;
            $this->auto_importer->save_settings($settings);
        }

        $result = $this->auto_importer->run_import();

        // Restore original setting
        if (!$was_enabled) {
            $settings['enabled'] = false;
            $this->auto_importer->save_settings($settings);
        }

        wp_send_json_success([
            'message' => $result['message'],
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * AJAX handler: Save auto import settings
     */
    public function ajax_save_auto_import_settings() {
        check_ajax_referer('dsz_admin_nonce', 'nonce');
        
        if (!dsz_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', 'dropshipzone')]);
        }

        if (!$this->auto_importer) {
            wp_send_json_error(['message' => __('Auto importer not initialized', 'dropshipzone')]);
        }

        $settings = [
            'enabled'               => !empty($_POST['enabled']),
            'frequency'             => isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'daily',
            'max_products_per_run'  => isset($_POST['max_products_per_run']) ? intval($_POST['max_products_per_run']) : 50,
            'min_stock_qty'         => isset($_POST['min_stock_qty']) ? intval($_POST['min_stock_qty']) : 100,
            'default_product_status'=> isset($_POST['default_product_status']) ? sanitize_text_field(wp_unslash($_POST['default_product_status'])) : 'publish',
            'filter_new_arrival'    => !empty($_POST['filter_new_arrival']),
            'filter_in_stock'       => !empty($_POST['filter_in_stock']),
            'filter_free_shipping'  => !empty($_POST['filter_free_shipping']),
        ];

        $this->auto_importer->save_settings($settings);

        // Update cron schedule
        if ($settings['enabled']) {
            $this->auto_importer->schedule_import($settings['frequency']);
        } else {
            $this->auto_importer->unschedule_import();
        }

        wp_send_json_success(['message' => __('Settings saved successfully', 'dropshipzone')]);
    }
}
