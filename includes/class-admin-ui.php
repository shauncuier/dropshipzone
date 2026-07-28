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
    public function __construct(API_Client $api_client, Price_Sync $price_sync, Stock_Sync $stock_sync, Cron $cron, Logger $logger, ?Product_Mapper $product_mapper = null, ?Product_Importer $product_importer = null, ?Order_Handler $order_handler = null, ?Auto_Importer $auto_importer = null) {
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
        add_action('wp_ajax_dszsync_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_dszsync_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_dszsync_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_dszsync_get_sync_status', [$this, 'ajax_get_sync_status']);
        add_action('wp_ajax_dszsync_continue_sync', [$this, 'ajax_continue_sync']);
        add_action('wp_ajax_dszsync_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_dszsync_export_logs', [$this, 'ajax_export_logs']);
        
        // Mapping AJAX handlers
        add_action('wp_ajax_dszsync_search_wc_products', [$this, 'ajax_search_wc_products']);
        add_action('wp_ajax_dszsync_search_catalog_products', [$this, 'ajax_search_catalog_products']);
        add_action('wp_ajax_dszsync_map_product', [$this, 'ajax_map_product']);
        add_action('wp_ajax_dszsync_unmap_product', [$this, 'ajax_unmap_product']);
        add_action('wp_ajax_dszsync_auto_map', [$this, 'ajax_auto_map']);
        
        // Import AJAX handlers
        add_action('wp_ajax_dszsync_search_api_products', [$this, 'ajax_search_api_products']);
        add_action('wp_ajax_dszsync_import_product', [$this, 'ajax_import_product']);
        add_action('wp_ajax_dszsync_resync_product', [$this, 'ajax_resync_product']);
        add_action('wp_ajax_dszsync_resync_all', [$this, 'ajax_resync_all']);
        add_action('wp_ajax_dszsync_resync_images', [$this, 'ajax_resync_images']);
        add_action('wp_ajax_dszsync_resync_categories', [$this, 'ajax_resync_categories']);
        add_action('wp_ajax_dszsync_resync_never_synced', [$this, 'ajax_resync_never_synced']);
        add_action('wp_ajax_dszsync_scan_unmapped_products', [$this, 'ajax_scan_unmapped_products']);
        add_action('wp_ajax_dszsync_get_categories', [$this, 'ajax_get_categories']);
        add_action('wp_ajax_dszsync_save_advanced_price_rules', [$this, 'ajax_save_advanced_price_rules']);
        add_action('wp_ajax_dszsync_save_import_template', [$this, 'ajax_save_import_template']);
        add_action('wp_ajax_dszsync_delete_import_template', [$this, 'ajax_delete_import_template']);
        add_action('wp_ajax_dszsync_export_mappings', [$this, 'ajax_export_mappings']);

        // Order AJAX handlers
        add_action('wp_ajax_dszsync_submit_order', [$this, 'ajax_submit_order']);
        add_action('wp_ajax_dszsync_submit_pending_orders', [$this, 'ajax_submit_pending_orders']);
        add_action('wp_ajax_dszsync_run_tracking_sync', [$this, 'ajax_run_tracking_sync']);

        // Orders list bulk action (HPOS + legacy)
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'register_order_bulk_action']);
        add_filter('bulk_actions-edit-shop_order', [$this, 'register_order_bulk_action']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_order_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_order_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'order_bulk_action_notice']);

        // Product list margin column (from _dszsync_cost supplier cost meta)
        add_filter('manage_edit-product_columns', [$this, 'add_product_margin_column'], 20);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_margin_column'], 10, 2);
        
        // Auto Import AJAX handlers
        add_action('wp_ajax_dszsync_run_auto_import', [$this, 'ajax_run_auto_import']);
        add_action('wp_ajax_dszsync_save_auto_import_settings', [$this, 'ajax_save_auto_import_settings']);
        
        // WooCommerce order integration
        add_action('add_meta_boxes', [$this, 'add_order_meta_box']);
    }

    /**
     * Register admin menu
     */
    public function register_menu() {
        // Main menu
        add_menu_page(
            __('Dropshipzone Sync', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('DSZ Sync', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync',
            [$this, 'render_dashboard'],
            'dashicons-update',
            56
        );

        // Dashboard (same as main)
        add_submenu_page(
            'dsz-sync',
            __('Dashboard', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Dashboard', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync',
            [$this, 'render_dashboard']
        );

        // API Settings
        add_submenu_page(
            'dsz-sync',
            __('API Settings', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('API Settings', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-api',
            [$this, 'render_api_settings']
        );

        // Price Rules
        add_submenu_page(
            'dsz-sync',
            __('Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-price',
            [$this, 'render_price_rules']
        );

        // Stock Rules
        add_submenu_page(
            'dsz-sync',
            __('Stock Rules', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Stock Rules', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-stock',
            [$this, 'render_stock_rules']
        );

        // Sync Center (unified sync page)
        add_submenu_page(
            'dsz-sync',
            __('Sync Center', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Sync Center', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-control',
            [$this, 'render_sync_center']
        );

        // Logs
        add_submenu_page(
            'dsz-sync',
            __('Logs', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Logs', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-logs',
            [$this, 'render_logs']
        );

        // Product Mapping
        add_submenu_page(
            'dsz-sync',
            __('Product Mapping', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Product Mapping', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-mapping',
            [$this, 'render_mapping']
        );

        // Product Import
        add_submenu_page(
            'dsz-sync',
            __('Product Import', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Product Import', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-import',
            [$this, 'render_import']
        );

        // Auto Import Settings
        add_submenu_page(
            'dsz-sync',
            __('Auto Import', '3s-soft-price-stock-sync-for-dropshipzone'),
            __('Auto Import', '3s-soft-price-stock-sync-for-dropshipzone'),
            'manage_woocommerce',
            'dsz-sync-auto-import',
            [$this, 'render_auto_import']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        // Load on our pages and on order edit screens (DSZ order meta box)
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_order_screen = $screen && in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'], true);

        if (strpos($hook, 'dsz-sync') === false && !$is_order_screen) {
            return;
        }

        // Use timestamp in dev mode to bust cache
        $asset_version = defined('WP_DEBUG') && WP_DEBUG ? DSZSYNC_VERSION . '.' . time() : DSZSYNC_VERSION;

        wp_enqueue_style(
            'dsz-admin-css',
            DSZSYNC_PLUGIN_URL . 'assets/admin.css',
            [],
            $asset_version
        );

        wp_enqueue_script(
            'dsz-admin-js',
            DSZSYNC_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            $asset_version,
            true
        );

        wp_localize_script('dsz-admin-js', 'dszsync_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dszsync_admin_nonce'),
            'import_templates' => (object) get_option('dszsync_import_templates', []),
            'strings' => [
                'testing' => __('Testing connection...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'saving' => __('Saving...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'syncing' => __('Syncing...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'success' => __('Success!', '3s-soft-price-stock-sync-for-dropshipzone'),
                'error' => __('Error occurred', '3s-soft-price-stock-sync-for-dropshipzone'),
                'confirm_clear' => __('Are you sure you want to clear all logs?', '3s-soft-price-stock-sync-for-dropshipzone'),
                'confirm_never_synced' => __('This will resync all never-synced products. Continue?', '3s-soft-price-stock-sync-for-dropshipzone'),
                'confirm_scan' => __('This will check all unmapped products against Dropshipzone API. Products found will be linked; products not found will be marked as Non-DSZ. Continue?', '3s-soft-price-stock-sync-for-dropshipzone'),
                'processing' => __('Processing...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'scanning' => __('Scanning products...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'resynced' => __('Resynced', '3s-soft-price-stock-sync-for-dropshipzone'),
                'errors_word' => __('errors', '3s-soft-price-stock-sync-for-dropshipzone'),
                'processed' => __('processed...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'linked' => __('linked', '3s-soft-price-stock-sync-for-dropshipzone'),
                'non_dsz' => __('marked as non-DSZ', '3s-soft-price-stock-sync-for-dropshipzone'),
                'scanned' => __('scanned...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'importing' => __('Importing...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'submitting' => __('Submitting...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'request_failed' => __('Request failed', '3s-soft-price-stock-sync-for-dropshipzone'),
                'rate_wait' => __('Rate limit reached — retrying shortly...', '3s-soft-price-stock-sync-for-dropshipzone'),
                'confirm_ok' => __('Confirm', '3s-soft-price-stock-sync-for-dropshipzone'),
                'confirm_cancel' => __('Cancel', '3s-soft-price-stock-sync-for-dropshipzone'),
            ],
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('dszsync_api', 'dszsync_api_email', [
            'sanitize_callback' => 'sanitize_email'
        ]);
        register_setting('dszsync_api', 'dszsync_api_password', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);
        register_setting('dszsync_settings', 'dszsync_price_rules', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
        register_setting('dszsync_settings', 'dszsync_stock_rules', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
        register_setting('dszsync_settings', 'dszsync_settings', [
            'sanitize_callback' => [$this, 'sanitize_array']
        ]);
        register_setting('dszsync_settings', 'dszsync_import_settings', [
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state (current page, filter, pagination); nothing is created, changed or deleted here.
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'dsz-sync';
        
        $nav_items = [
            // Overview
            'dsz-sync' => [
                'label' => __('Dashboard', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-dashboard'
            ],
            // 1. First: Configure API
            'dsz-sync-api' => [
                'label' => __('API Settings', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-admin-network'
            ],
            // 2. Products: Import first, then map
            'dsz-sync-import' => [
                'label' => __('Import Products', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-download'
            ],
            'dsz-sync-auto-import' => [
                'label' => __('Auto Import', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-controls-repeat'
            ],
            'dsz-sync-mapping' => [
                'label' => __('Product Mapping', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-admin-links'
            ],
            // 3. Rules: Configure pricing and stock
            'dsz-sync-price' => [
                'label' => __('Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-money-alt'
            ],
            'dsz-sync-stock' => [
                'label' => __('Stock Rules', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-archive'
            ],
            // 4. Operations: Run sync and view logs
            'dsz-sync-control' => [
                'label' => __('Sync Center', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-update'
            ],
            'dsz-sync-logs' => [
                'label' => __('Logs', '3s-soft-price-stock-sync-for-dropshipzone'),
                'icon' => 'dashicons-list-view'
            ],
        ];
        ?>
        <div class="dsz-header">
            <div class="dsz-header-content">
                <div class="dsz-header-text">
                    <h1><?php echo esc_html($title); ?></h1>
                    <?php if ($subtitle): ?>
                        <p class="dsz-subtitle"><?php echo esc_html($subtitle); ?></p>
                    <?php endif; ?>
                </div>
                <button type="button" id="dsz-theme-toggle" class="button dsz-theme-toggle" title="<?php esc_attr_e('Toggle light/dark theme', '3s-soft-price-stock-sync-for-dropshipzone'); ?>">
                    <span class="dashicons dashicons-lightbulb"></span>
                </button>
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
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        $sync_status = $this->cron->get_sync_status();
        $token_status = $this->api_client->get_token_status();
        $error_count = $this->logger->get_count('error');
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Dropshipzone Sync Dashboard', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-dashboard">
                <!-- Status Cards -->
                <div class="dsz-cards">
                    <div class="dsz-card dsz-card-status <?php echo esc_attr($token_status['is_valid'] ? 'dsz-card-success' : 'dsz-card-error'); ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons <?php echo esc_attr($token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('API Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <p class="dsz-card-value">
                                <?php echo esc_html($token_status['is_valid'] ? __('Connected', '3s-soft-price-stock-sync-for-dropshipzone') : __('Not Connected', '3s-soft-price-stock-sync-for-dropshipzone')); ?>
                            </p>
                            <?php if ($token_status['is_valid'] && $token_status['expires_in'] > 0): ?>
                                <?php /* translators: %s: time difference */ ?>
                                <p class="dsz-card-meta"><?php printf(esc_html__('Expires in %s', '3s-soft-price-stock-sync-for-dropshipzone'), esc_html(human_time_diff(time(), time() + $token_status['expires_in']))); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dsz-card">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-clock"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('Last Sync', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <p class="dsz-card-value">
                                <?php echo $sync_status['last_sync'] ? esc_html(dszsync_time_ago($sync_status['last_sync'])) : esc_html__('Never', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </p>
                            <?php if ($sync_status['next_scheduled']): ?>
                                <?php /* translators: %s: date time */ ?>
                                <p class="dsz-card-meta"><?php printf(esc_html__('Next: %s', '3s-soft-price-stock-sync-for-dropshipzone'), esc_html(dszsync_format_datetime($sync_status['next_scheduled']))); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="dsz-card">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-chart-bar"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('Products Updated', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <p class="dsz-card-value"><?php echo intval($sync_status['last_products_updated']); ?></p>
                            <p class="dsz-card-meta"><?php esc_html_e('Last sync run', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                        </div>
                    </div>

                    <div class="dsz-card <?php echo esc_attr($error_count > 0 ? 'dsz-card-warning' : ''); ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons dashicons-flag"></span>
                        </div>
                        <div class="dsz-card-content">
                            <h3><?php esc_html_e('Errors', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <p class="dsz-card-value"><?php echo intval($error_count); ?></p>
                            <p class="dsz-card-meta">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=error')); ?>"><?php esc_html_e('View Logs', '3s-soft-price-stock-sync-for-dropshipzone'); ?></a>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="dsz-section">
                    <h2><?php esc_html_e('Quick Actions', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                    <div class="dsz-quick-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-api')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-admin-network"></span>
                            <?php esc_html_e('Configure API', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-control')); ?>" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Run Sync Now', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-price')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-money-alt"></span>
                            <?php esc_html_e('Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-stock')); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-archive"></span>
                            <?php esc_html_e('Stock Rules', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </a>
                    </div>
                </div>

                <!-- Sync Status (if in progress) -->
                <?php if ($sync_status['in_progress']): ?>
                <div class="dsz-section dsz-sync-progress-section">
                    <h2><?php esc_html_e('Sync in Progress', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                    <div class="dsz-progress-wrapper">
                        <div class="dsz-progress-bar">
                            <div class="dsz-progress-fill" style="width: <?php echo esc_attr($this->cron->get_progress()); ?>%"></div>
                        </div>
                        <p class="dsz-progress-text">
                            <?php /* translators: %s: progress percentage */ ?>
                            <?php printf(esc_html__('Processing... %s%%', '3s-soft-price-stock-sync-for-dropshipzone'), esc_html($this->cron->get_progress())); ?>
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
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        $email = get_option('dszsync_api_email', '');
        $token_status = $this->api_client->get_token_status();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('API Settings', '3s-soft-price-stock-sync-for-dropshipzone'), __('Configure your Dropshipzone API credentials', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-api-form" class="dsz-form">
                    <?php wp_nonce_field('dszsync_api_settings', 'dszsync_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('API Credentials', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dszsync_api_email"><?php esc_html_e('API Email', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="dszsync_api_email" name="dszsync_api_email" value="<?php echo esc_attr($email); ?>" class="regular-text" />
                                    <p class="description"><?php esc_html_e('Your Dropshipzone account email', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dszsync_api_password"><?php esc_html_e('API Password', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="password" id="dszsync_api_password" name="dszsync_api_password" value="" class="regular-text" placeholder="<?php echo esc_attr($email ? '••••••••' : ''); ?>" />
                                    <p class="description"><?php esc_html_e('Your Dropshipzone account password (stored securely)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <div class="dsz-form-actions">
                            <button type="button" id="dsz-test-connection" class="button button-secondary">
                                <span class="dashicons dashicons-admin-network"></span>
                                <?php esc_html_e('Test Connection', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                            <button type="submit" class="button button-primary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php esc_html_e('Save Settings', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>

                        <div id="dsz-api-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Import Settings -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Import Settings', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <?php 
                        $import_settings = get_option('dszsync_import_settings', ['default_status' => 'publish']);
                        $default_status = isset($import_settings['default_status']) ? $import_settings['default_status'] : 'publish';
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="dszsync_import_status"><?php esc_html_e('Default Product Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <select id="dszsync_import_status" name="dszsync_import_status" class="dsz-import-status-select">
                                        <option value="publish" <?php selected($default_status, 'publish'); ?>><?php esc_html_e('Published', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="draft" <?php selected($default_status, 'draft'); ?>><?php esc_html_e('Draft', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('New products will be created with this status.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Token Status -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Connection Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <div class="dsz-status-box <?php echo esc_attr($token_status['is_valid'] ? 'dsz-status-success' : 'dsz-status-warning'); ?>">
                            <span class="dashicons <?php echo esc_attr($token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
                            <div>
                                <strong><?php echo esc_html($token_status['is_valid'] ? __('Connected', '3s-soft-price-stock-sync-for-dropshipzone') : __('Not Connected', '3s-soft-price-stock-sync-for-dropshipzone')); ?></strong>
                                <?php if ($token_status['is_valid']): ?>
                                    <?php /* translators: %s: date time */ ?>
                                    <p><?php printf(esc_html__('Token expires: %s', '3s-soft-price-stock-sync-for-dropshipzone'), esc_html(dszsync_format_datetime($token_status['expires_at']))); ?></p>
                                <?php else: ?>
                                    <p><?php esc_html_e('Please enter your credentials and test the connection.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
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
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        $rules = $this->price_sync->get_rules();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'), __('Configure how prices are calculated from supplier cost', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-price-form" class="dsz-form" data-type="price_rules">
                    <?php wp_nonce_field('dszsync_price_settings', 'dszsync_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Markup Settings', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Markup Type', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="markup_type" value="percentage" <?php checked($rules['markup_type'], 'percentage'); ?> />
                                        <?php esc_html_e('Percentage', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                    <label class="dsz-ml-3">
                                        <input type="radio" name="markup_type" value="fixed" <?php checked($rules['markup_type'], 'fixed'); ?> />
                                        <?php esc_html_e('Fixed Amount', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="markup_value"><?php esc_html_e('Markup Value', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="markup_value" name="markup_value" value="<?php echo esc_attr($rules['markup_value']); ?>" step="0.01" min="0" class="small-text" />
                                    <span class="dsz-markup-symbol">%</span>
                                    <p class="description"><?php esc_html_e('Enter percentage (e.g., 30 for 30%) or fixed amount (e.g., 15 for $15)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('GST Settings', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Apply GST', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gst_enabled" value="1" <?php checked($rules['gst_enabled'], true); ?> />
                                        <?php esc_html_e('Enable GST calculation (10%)', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('GST Mode', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="radio" name="gst_type" value="include" <?php checked($rules['gst_type'], 'include'); ?> />
                                        <?php esc_html_e('Supplier price already includes GST', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label><br/>
                                    <label>
                                        <input type="radio" name="gst_type" value="exclude" <?php checked($rules['gst_type'], 'exclude'); ?> />
                                        <?php esc_html_e('Add GST to calculated price', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Price Rounding', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Rounding', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="rounding_enabled" value="1" <?php checked($rules['rounding_enabled'], true); ?> />
                                        <?php esc_html_e('Round prices for cleaner display', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Rounding Style', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <select name="rounding_type">
                                        <option value="99" <?php selected($rules['rounding_type'], '99'); ?>><?php esc_html_e('.99 (e.g., $29.99)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="95" <?php selected($rules['rounding_type'], '95'); ?>><?php esc_html_e('.95 (e.g., $29.95)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="nearest" <?php selected($rules['rounding_type'], 'nearest'); ?>><?php esc_html_e('Nearest dollar (e.g., $30)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Price Preview -->
                    <div class="dsz-form-section dsz-preview-section">
                        <h2><?php esc_html_e('Price Preview', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <div class="dsz-price-preview">
                            <div class="dsz-preview-input">
                                <label for="preview_price"><?php esc_html_e('Supplier Price:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                <input type="number" id="preview_price" value="100" step="0.01" min="0" />
                            </div>
                            <div class="dsz-preview-result">
                                <span class="dsz-preview-arrow">→</span>
                                <div class="dsz-preview-final">
                                    <label><?php esc_html_e('Final Price:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                    <strong id="calculated_price">$0.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>

                    <div id="dsz-price-message" class="dsz-message hidden"></div>
                </form>

                <!-- Advanced Price Rules: per category/supplier/SKU-prefix overrides -->
                <?php
                $advanced = get_option('dszsync_price_rules_v2', []);
                $adv_rules = isset($advanced['rules']) && is_array($advanced['rules']) ? $advanced['rules'] : [];
                ?>
                <div class="dsz-form-section">
                    <div class="dsz-section-header">
                        <h2><?php esc_html_e('Advanced Price Rules', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <button type="button" id="dsz-add-adv-rule" class="button">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('Add Rule', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>
                    <p class="description dsz-mb-4"><?php esc_html_e('Rules are checked top to bottom; the first match overrides the global markup above. GST and rounding settings are inherited from the global rules. Match by Dropshipzone category (numeric ID or path prefix like "Appliances"), supplier ID (vendor_id), or SKU prefix.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>

                    <table class="dsz-table" id="dsz-adv-rules-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Name', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th><?php esc_html_e('Match', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th><?php esc_html_e('Value', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th><?php esc_html_e('Markup', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th><?php esc_html_e('Amount', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adv_rules as $adv_rule): ?>
                            <tr class="dsz-adv-rule">
                                <td><input type="text" class="dsz-rule-name" value="<?php echo esc_attr(isset($adv_rule['name']) ? $adv_rule['name'] : ''); ?>" placeholder="<?php esc_attr_e('Rule name', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" /></td>
                                <td>
                                    <select class="dsz-rule-match-type">
                                        <option value="category" <?php selected($adv_rule['match_type'], 'category'); ?>><?php esc_html_e('Category', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="supplier" <?php selected($adv_rule['match_type'], 'supplier'); ?>><?php esc_html_e('Supplier ID', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="sku_prefix" <?php selected($adv_rule['match_type'], 'sku_prefix'); ?>><?php esc_html_e('SKU prefix', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                </td>
                                <td><input type="text" class="dsz-rule-match-value" value="<?php echo esc_attr(isset($adv_rule['match_value']) ? $adv_rule['match_value'] : ''); ?>" /></td>
                                <td>
                                    <select class="dsz-rule-markup-type">
                                        <option value="percentage" <?php selected($adv_rule['markup_type'], 'percentage'); ?>>%</option>
                                        <option value="fixed" <?php selected($adv_rule['markup_type'], 'fixed'); ?>>$</option>
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" class="dsz-rule-markup-value small-text" value="<?php echo esc_attr(isset($adv_rule['markup_value']) ? $adv_rule['markup_value'] : ''); ?>" /></td>
                                <td><button type="button" class="button dsz-btn-danger dsz-rule-remove" title="<?php esc_attr_e('Remove rule', '3s-soft-price-stock-sync-for-dropshipzone'); ?>"><span class="dashicons dashicons-no-alt"></span></button></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="dsz-adv-rule dsz-adv-rule-proto hidden">
                                <td><input type="text" class="dsz-rule-name" placeholder="<?php esc_attr_e('Rule name', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" /></td>
                                <td>
                                    <select class="dsz-rule-match-type">
                                        <option value="category"><?php esc_html_e('Category', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="supplier"><?php esc_html_e('Supplier ID', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="sku_prefix"><?php esc_html_e('SKU prefix', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                </td>
                                <td><input type="text" class="dsz-rule-match-value" /></td>
                                <td>
                                    <select class="dsz-rule-markup-type">
                                        <option value="percentage">%</option>
                                        <option value="fixed">$</option>
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" class="dsz-rule-markup-value small-text" /></td>
                                <td><button type="button" class="button dsz-btn-danger dsz-rule-remove" title="<?php esc_attr_e('Remove rule', '3s-soft-price-stock-sync-for-dropshipzone'); ?>"><span class="dashicons dashicons-no-alt"></span></button></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="dsz-form-actions">
                        <button type="button" id="dsz-save-adv-rules" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Advanced Rules', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>
                    <div id="dsz-adv-rules-message" class="dsz-message hidden"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Stock Rules page
     */
    public function render_stock_rules() {
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        $rules = $this->stock_sync->get_rules();
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Stock Rules', '3s-soft-price-stock-sync-for-dropshipzone'), __('Configure how stock quantities are synced', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-stock-form" class="dsz-form" data-type="stock_rules">
                    <?php wp_nonce_field('dszsync_stock_settings', 'dszsync_nonce'); ?>
                    
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Stock Buffer', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Stock Buffer', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="buffer_enabled" value="1" <?php checked($rules['buffer_enabled'], true); ?> />
                                        <?php esc_html_e('Subtract a buffer amount from supplier stock', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('Useful to prevent overselling', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="buffer_amount"><?php esc_html_e('Buffer Amount', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="buffer_amount" name="buffer_amount" value="<?php echo esc_attr($rules['buffer_amount']); ?>" min="0" step="1" class="small-text" />
                                    <p class="description"><?php esc_html_e('Number of units to subtract from supplier stock (e.g., 2 means if supplier has 10, your store shows 8)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Out of Stock Handling', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Zero Stock on Unavailable', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="zero_on_unavailable" value="1" <?php checked($rules['zero_on_unavailable'], true); ?> />
                                        <?php esc_html_e('Set stock to 0 if product is marked unavailable by supplier', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Auto Out of Stock', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="auto_out_of_stock" value="1" <?php checked($rules['auto_out_of_stock'], true); ?> />
                                        <?php esc_html_e('Automatically set product status to "Out of Stock" when quantity is 0', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Deactivate Missing Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="deactivate_if_not_found" value="1" <?php checked(isset($rules['deactivate_if_not_found']) ? $rules['deactivate_if_not_found'] : true, true); ?> />
                                        <?php esc_html_e('Set products to Draft if not found in Dropshipzone API (discontinued products)', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('When a product SKU is no longer available in Dropshipzone, the product will be set to Draft status and stock set to 0.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Auto-Republish on Restock', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="republish_on_restock" value="1" <?php checked(isset($rules['republish_on_restock']) ? $rules['republish_on_restock'] : true, true); ?> />
                                        <?php esc_html_e('Automatically republish Draft products when they come back in stock', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e('When a product that was set to Draft (due to being out of stock or discontinued) gets stock again, it will be automatically set back to Published.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Stock Rules', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
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
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        $sync_status = $this->cron->get_sync_status();
        $frequencies = $this->cron->get_frequencies();
        $total_mapped = intval($this->product_mapper->get_count());
        $unmapped_count = $this->product_mapper->get_unmapped_count();
        $in_progress = !empty($sync_status['in_progress']);
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Sync Center', '3s-soft-price-stock-sync-for-dropshipzone'), __('All sync actions in one place', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-sync-dashboard">
                <!-- Status Cards Grid -->
                <div class="dsz-sync-cards">
                    <!-- Card: Current Status -->
                    <div class="dsz-sync-card <?php echo esc_attr($in_progress ? 'dsz-card-syncing' : 'dsz-card-idle'); ?>">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons <?php echo esc_attr($in_progress ? 'dashicons-update-alt dsz-spin' : 'dashicons-yes-alt'); ?>"></span>
                        </div>
                        <div class="dsz-sync-card-content">
                            <h3><?php esc_html_e('Sync State', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value" id="sync-status-text">
                                <?php echo esc_html($in_progress ? __('Syncing...', '3s-soft-price-stock-sync-for-dropshipzone') : __('Ready', '3s-soft-price-stock-sync-for-dropshipzone')); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <?php if ($in_progress): ?>
                                    <span class="dsz-pulse-dot"></span> <?php esc_html_e('Processing...', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                <?php else: ?>
                                    <?php esc_html_e('All systems ready', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
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
                            <h3><?php esc_html_e('Last Sync', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value">
                                <?php echo $sync_status['last_sync'] ? esc_html(dszsync_time_ago($sync_status['last_sync'])) : esc_html__('Never', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <span class="dsz-text-success"><?php echo intval($sync_status['last_products_updated']); ?> <?php esc_html_e('updated', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card: Linked Products -->
                    <div class="dsz-sync-card">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons dashicons-admin-links"></span>
                        </div>
                        <div class="dsz-sync-card-content">
                            <h3><?php esc_html_e('Linked Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value">
                                <?php echo number_format($total_mapped); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <?php if ($unmapped_count > 0): ?>
                                    <span class="dsz-text-warning"><?php echo intval($unmapped_count); ?> <?php esc_html_e('unlinked', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                                <?php else: ?>
                                    <?php esc_html_e('All products linked', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
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
                            <h3><?php esc_html_e('Next Auto-Sync', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                            <div class="dsz-sync-card-value">
                                <?php echo $sync_status['next_scheduled'] ? esc_html(dszsync_time_ago($sync_status['next_scheduled'])) : esc_html__('Not scheduled', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </div>
                            <div class="dsz-sync-card-meta">
                                <?php 
                                $freq_labels = [
                                    'hourly' => __('Hourly', '3s-soft-price-stock-sync-for-dropshipzone'),
                                    'twicedaily' => __('Twice Daily', '3s-soft-price-stock-sync-for-dropshipzone'),
                                    'daily' => __('Daily', '3s-soft-price-stock-sync-for-dropshipzone'),
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
                            <h3><?php esc_html_e('Link Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Scan your WooCommerce catalog and automatically link products to Dropshipzone using matching SKUs.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-auto-map" class="button button-secondary">
                                <span class="dashicons dashicons-admin-links"></span>
                                <?php esc_html_e('Link Products by SKU', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-automap-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 2: Update Prices & Stock -->
                    <div class="dsz-action-card dsz-action-card-primary">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-money-alt"></span>
                            <h3><?php esc_html_e('Update Prices & Stock', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Sync the latest prices and stock levels from Dropshipzone API for all linked products.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-run-sync" class="button button-primary" <?php echo esc_attr($in_progress ? 'disabled' : ''); ?>>
                                <span class="dashicons dashicons-update-alt"></span>
                                <?php esc_html_e('Update Now', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                        <!-- Progress Section -->
                        <div id="dsz-progress-container" class="dsz-progress-console <?php echo esc_attr($in_progress ? '' : 'hidden'); ?>">
                            <div class="dsz-progress-stats">
                                <span class="dsz-progress-label"><?php esc_html_e('Progress', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
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
                            <h3><?php esc_html_e('Refresh Images', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Re-download product images and gallery from Dropshipzone for linked products.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-resync-images" class="button button-secondary">
                                <span class="dashicons dashicons-format-image"></span>
                                <?php esc_html_e('Refresh Images', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-resync-images-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 4: Refresh Categories -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-category"></span>
                            <h3><?php esc_html_e('Refresh Categories', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Update product categories from Dropshipzone for linked products.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-resync-categories" class="button button-secondary">
                                <span class="dashicons dashicons-category"></span>
                                <?php esc_html_e('Refresh Categories', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-resync-categories-message" class="dsz-message hidden"></div>
                    </div>

                    <!-- Action Card 5: Refresh All Product Data -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-download"></span>
                            <h3><?php esc_html_e('Refresh All Data', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Re-download everything: images, descriptions, categories, price, stock for linked products.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                        <div class="dsz-action-card-footer">
                            <button type="button" id="dsz-resync-all" class="button button-secondary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Refresh All Data', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
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

                    <!-- Action Card 6: Order Automation -->
                    <?php $order_settings = get_option('dszsync_order_settings', []); ?>
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-cart"></span>
                            <h3><?php esc_html_e('Order Automation', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Submit paid orders to Dropshipzone and pull tracking numbers back automatically.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                        <div class="dsz-form-group">
                            <label>
                                <input type="checkbox" id="dsz-order-auto-submit" <?php checked(!empty($order_settings['auto_submit'])); ?> />
                                <?php esc_html_e('Auto-submit orders to DSZ when paid (processing)', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </label>
                            <label>
                                <input type="checkbox" id="dsz-order-autocomplete" <?php checked(!empty($order_settings['tracking_autocomplete'])); ?> />
                                <?php esc_html_e('Complete WC order when DSZ marks it complete', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </label>
                        </div>
                        <div class="dsz-action-card-footer dsz-flex-row">
                            <button type="button" id="dsz-save-order-settings" class="button">
                                <?php esc_html_e('Save', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                            <button type="button" id="dsz-submit-pending-orders" class="button button-secondary">
                                <span class="dashicons dashicons-upload"></span>
                                <?php esc_html_e('Submit Pending Orders', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                            <button type="button" id="dsz-run-tracking-sync" class="button button-secondary">
                                <span class="dashicons dashicons-location"></span>
                                <?php esc_html_e('Check Tracking Now', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                        <div id="dsz-order-automation-message" class="dsz-message hidden"></div>
                    </div>
                </div>

                <!-- Schedule Settings -->
                <div class="dsz-content dsz-sync-settings-full">
                    <form id="dsz-schedule-form" class="dsz-form" data-type="sync_settings">
                        <?php wp_nonce_field('dszsync_settings', 'dszsync_nonce'); ?>
                        
                        <div class="dsz-form-section">
                            <div class="dsz-section-header">
                                <h2><?php esc_html_e('Auto-Sync Schedule', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                                <p class="description"><?php esc_html_e('Configure automatic price & stock updates.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                            </div>
                            
                            <div class="dsz-inline-settings">
                                <div class="dsz-form-group">
                                    <label for="frequency"><?php esc_html_e('Interval', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                    <select id="frequency" name="frequency" class="dsz-select">
                                        <?php foreach ($frequencies as $value => $label): ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($sync_status['frequency'], $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="dsz-form-group">
                                    <label for="batch_size"><?php esc_html_e('Batch Size', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                    <input type="number" id="batch_size" name="batch_size" value="<?php echo esc_attr($sync_status['batch_size']); ?>" min="10" max="200" step="10" />
                                </div>

                                <button type="submit" class="button button-primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Save', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                </button>
                                <div id="dsz-schedule-message" class="dsz-message hidden"></div>
                            </div>
                        </div>

                        <div class="dsz-form-section">
                            <div class="dsz-section-header">
                                <h2><?php esc_html_e('Fast Stock Updates', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                                <p class="description">
                                    <?php esc_html_e('Asks Dropshipzone which SKUs changed stock since the last check and refreshes only those, instead of re-reading your whole catalogue. Useful on large catalogues where a full sweep takes several passes.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                </p>
                            </div>

                            <div class="dsz-inline-settings">
                                <div class="dsz-form-group">
                                    <label class="dsz-toggle">
                                        <input type="checkbox" id="incremental_enabled" name="incremental_enabled" value="1" <?php checked(!empty($sync_status['incremental_enabled'])); ?> />
                                        <span><?php esc_html_e('Enable fast stock updates', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                                    </label>
                                </div>

                                <div class="dsz-form-group">
                                    <label for="incremental_frequency"><?php esc_html_e('Check Interval', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                    <select id="incremental_frequency" name="incremental_frequency" class="dsz-select">
                                        <?php foreach ($frequencies as $value => $label): ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected(isset($sync_status['incremental_frequency']) ? $sync_status['incremental_frequency'] : 'hourly', $value); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <p class="description">
                                <?php esc_html_e('This covers stock only. Price changes are picked up by the full sync above, which keeps running on its own schedule.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                <?php if (!empty($sync_status['incremental_last_run'])): ?>
                                    <br />
                                    <?php
                                    printf(
                                        /* translators: 1: human-readable time since the last check, 2: number of SKUs refreshed */
                                        esc_html__('Last check: %1$s ago, %2$d products refreshed.', '3s-soft-price-stock-sync-for-dropshipzone'),
                                        esc_html(human_time_diff($sync_status['incremental_last_run'], time())),
                                        intval($sync_status['incremental_refreshed'])
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>
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
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state (current page, filter, pagination); nothing is created, changed or deleted here.
        $level = isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination state.
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
            <?php $this->render_header(__('Activity Logs', '3s-soft-price-stock-sync-for-dropshipzone'), __('Monitor sync activity and troubleshoot issues', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <!-- Log Stats Cards -->
            <div class="dsz-log-stats">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs')); ?>" class="dsz-log-stat-card <?php echo empty($level) ? 'active' : ''; ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-list-view"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_all); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Total Logs', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=info')); ?>" class="dsz-log-stat-card dsz-log-stat-info <?php echo esc_attr($level === 'info' ? 'active' : ''); ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-info"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_info); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Info', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=warning')); ?>" class="dsz-log-stat-card dsz-log-stat-warning <?php echo esc_attr($level === 'warning' ? 'active' : ''); ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-warning"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_warning); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Warnings', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                    </div>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=error')); ?>" class="dsz-log-stat-card dsz-log-stat-error <?php echo esc_attr($level === 'error' ? 'active' : ''); ?>">
                    <span class="dsz-log-stat-icon dashicons dashicons-dismiss"></span>
                    <div class="dsz-log-stat-content">
                        <span class="dsz-log-stat-value"><?php echo number_format($total_error); ?></span>
                        <span class="dsz-log-stat-label"><?php esc_html_e('Errors', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                    </div>
                </a>
            </div>

            <div class="dsz-content">
                <!-- Toolbar -->
                <div class="dsz-logs-toolbar">
                    <div class="dsz-logs-summary">
                        <?php if ($level): ?>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %1$d: count, %2$s: level type */
                                __('Showing %1$d %2$s logs', '3s-soft-price-stock-sync-for-dropshipzone'),
                                $total,
                                ucfirst($level)
                            ));
                            ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs')); ?>" class="dsz-clear-filter">
                                <?php esc_html_e('Clear filter', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </a>
                        <?php else: ?>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: total log count */
                                __('Showing all %d logs', '3s-soft-price-stock-sync-for-dropshipzone'),
                                $total
                            ));
                            ?>
                        <?php endif; ?>
                    </div>
                    <div class="dsz-logs-actions">
                        <button type="button" id="dsz-export-logs" class="button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Export CSV', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                        <button type="button" id="dsz-clear-logs" class="button button-link-delete">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Clear All', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>
                </div>

                <!-- Logs List -->
                <?php if (empty($logs)): ?>
                    <div class="dsz-logs-empty">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <h3><?php esc_html_e('No logs found', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        <p><?php esc_html_e('Activity logs will appear here as sync operations run.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="dsz-logs-list">
                        <?php foreach ($logs as $log): ?>
                            <div class="dsz-log-item dsz-log-<?php echo esc_attr($log['level']); ?>">
                                <div class="dsz-log-indicator"></div>
                                <div class="dsz-log-content">
                                    <div class="dsz-log-header">
                                        <?php echo wp_kses_post(Logger::get_level_badge($log['level'])); ?>
                                        <span class="dsz-log-time" title="<?php echo esc_attr(dszsync_format_datetime($log['created_at'])); ?>">
                                            <?php echo esc_html(dszsync_time_ago($log['created_at'])); ?>
                                        </span>
                                    </div>
                                    <div class="dsz-log-message"><?php echo esc_html($log['message']); ?></div>
                                    <?php if (!empty($log['context'])): ?>
                                        <details class="dsz-log-context">
                                            <summary><?php esc_html_e('View details', '3s-soft-price-stock-sync-for-dropshipzone'); ?></summary>
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
                            <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="button">&laquo; <?php esc_html_e('Previous', '3s-soft-price-stock-sync-for-dropshipzone'); ?></a>
                        <?php endif; ?>
                        
                        <span class="dsz-pagination-info">
                            <?php
                            /* translators: %1$d: current page, %2$d: total pages */
                            echo esc_html(sprintf(__('Page %1$d of %2$d', '3s-soft-price-stock-sync-for-dropshipzone'), $page, $total_pages));
                            ?>
                        </span>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="button"><?php esc_html_e('Next', '3s-soft-price-stock-sync-for-dropshipzone'); ?> &raquo;</a>
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        // A password is opaque credential data, not display text. Sanitizing
        // would corrupt valid passwords; it is never output and is only sent
        // to the API over HTTPS or stored encrypted.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see comment above
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        // If password is empty, try to use stored password
        if (empty($password)) {
            $encrypted = get_option('dszsync_api_password', '');
            $password = dszsync_decrypt($encrypted);
        }

        $result = $this->api_client->test_connection($email, $password);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Persist the verified credentials. Without this, a successful test
        // stores a token but no password — once the token expires, scheduled
        // refreshes fail with "missing credentials".
        if (!empty($email)) {
            update_option('dszsync_api_email', $email);
        }
        if (!empty($_POST['password'])) {
            update_option('dszsync_api_password', dszsync_encrypt($password));
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        // Each branch below sanitizes the individual fields it consumes with
        // the function appropriate to that field's type.
        $settings = isset($_POST['settings']) && is_array($_POST['settings'])
            ? wp_unslash($_POST['settings']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below
            : [];

        switch ($type) {
            case 'api':
                $email = isset($settings['email']) ? sanitize_email($settings['email']) : '';
                // A password is opaque credential data, not display text.
                // sanitize_text_field() would silently corrupt valid passwords
                // (it collapses whitespace and strips tag-like sequences), so
                // the value is only forced to a scalar string. It is never
                // echoed, and is encrypted at rest via dszsync_encrypt().
                $password = isset($settings['password']) && is_scalar($settings['password'])
                    ? (string) $settings['password']
                    : '';

                $email_changed = ($email !== get_option('dszsync_api_email', ''));

                update_option('dszsync_api_email', $email);

                if (!empty($password)) {
                    update_option('dszsync_api_password', dszsync_encrypt($password));
                }

                // Credentials changed, so the cached token was issued for the
                // previous account. Drop it and let the next request re-auth,
                // rather than leaving a stale token valid until it expires.
                if ($email_changed || !empty($password)) {
                    $this->api_client->clear_token();
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
                update_option('dszsync_price_rules', $rules);
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
                update_option('dszsync_stock_rules', $rules);
                $this->stock_sync->reload_rules();
                break;

            case 'sync_settings':
                $current = get_option('dszsync_settings', []);
                $current['frequency'] = isset($settings['frequency']) ? sanitize_text_field($settings['frequency']) : 'hourly';
                $current['batch_size'] = isset($settings['batch_size']) ? max(10, min(200, intval($settings['batch_size']))) : 100;
                $current['incremental_enabled'] = !empty($settings['incremental_enabled']);
                $current['incremental_frequency'] = isset($settings['incremental_frequency'])
                    ? sanitize_text_field($settings['incremental_frequency'])
                    : 'hourly';
                update_option('dszsync_settings', $current);

                // Reschedule cron
                $this->cron->schedule_sync($current['frequency']);

                if ($current['incremental_enabled']) {
                    $this->cron->schedule_incremental($current['incremental_frequency']);
                } else {
                    $this->cron->unschedule_incremental();
                }
                break;

            case 'import_settings':
                $import_settings = [
                    'default_status' => isset($settings['default_status']) ? sanitize_text_field($settings['default_status']) : 'publish',
                ];
                update_option('dszsync_import_settings', $import_settings);
                break;

            case 'orders':
                update_option('dszsync_order_settings', [
                    'auto_submit' => !empty($settings['auto_submit']),
                    'tracking_autocomplete' => !empty($settings['tracking_autocomplete']),
                ], false);
                break;

            default:
                wp_send_json_error(['message' => __('Invalid settings type', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        wp_send_json_success(['message' => __('Settings saved successfully', '3s-soft-price-stock-sync-for-dropshipzone')]);
    }

    /**
     * AJAX: Run sync
     */
    public function ajax_run_sync() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $result = $this->cron->manual_sync();

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $status = $this->cron->get_sync_status();
        $status['progress'] = $this->cron->get_progress();

        wp_send_json_success($status);
    }

    /**
     * AJAX: Continue sync batch
     */
    public function ajax_continue_sync() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $result = $this->cron->continue_batch();

        wp_send_json_success($result);
    }

    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $this->logger->clear_logs();

        wp_send_json_success(['message' => __('Logs cleared successfully', '3s-soft-price-stock-sync-for-dropshipzone')]);
    }

    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $level = isset($_POST['level']) ? sanitize_text_field(wp_unslash($_POST['level'])) : '';
        $csv = $this->logger->export_csv(['level' => $level]);

        wp_send_json_success([
            'csv' => base64_encode($csv),
            'filename' => 'dsz-sync-logs-' . gmdate('Y-m-d') . '.csv',
        ]);
    }

    /**
     * AJAX: Export product mappings as CSV
     */
    public function ajax_export_mappings() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $mappings = $this->product_mapper->get_mappings(['limit' => 100000, 'offset' => 0]);

        $rows = [
            ['wc_product_id', 'wc_product_name', 'dsz_sku', 'sync_enabled', 'last_synced', 'last_resynced', 'created_at'],
        ];
        foreach ($mappings as $m) {
            $rows[] = [
                $m['wc_product_id'],
                isset($m['wc_product_name']) ? $m['wc_product_name'] : '',
                $m['dsz_sku'],
                $m['sync_enabled'],
                isset($m['last_synced']) ? $m['last_synced'] : '',
                isset($m['last_resynced']) ? $m['last_resynced'] : '',
                isset($m['created_at']) ? $m['created_at'] : '',
            ];
        }

        // Built as a string rather than via a stream: no filesystem access is
        // involved, so WP_Filesystem is not applicable here.
        $csv = '';
        foreach ($rows as $row) {
            $escaped = [];
            foreach ($row as $field) {
                $escaped[] = '"' . str_replace('"', '""', (string) $field) . '"';
            }
            $csv .= implode(',', $escaped) . "\r\n";
        }

        wp_send_json_success([
            'csv' => base64_encode($csv),
            'filename' => 'dsz-mappings-' . gmdate('Y-m-d') . '.csv',
        ]);
    }

    /**
     * AJAX: Save advanced price rules (ordered, first match wins)
     */
    public function ajax_save_advanced_price_rules() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        // Each rule field is sanitized individually in the loop below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below
        $raw_rules = isset($_POST['rules']) ? (array) wp_unslash($_POST['rules']) : [];
        $clean = [];

        foreach ($raw_rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $match_type = isset($rule['match_type']) ? sanitize_text_field($rule['match_type']) : '';
            $match_value = isset($rule['match_value']) ? sanitize_text_field($rule['match_value']) : '';

            if (!in_array($match_type, ['category', 'supplier', 'sku_prefix'], true) || $match_value === '') {
                continue;
            }

            $clean[] = [
                'name' => isset($rule['name']) ? sanitize_text_field($rule['name']) : '',
                'match_type' => $match_type,
                'match_value' => $match_value,
                'markup_type' => (isset($rule['markup_type']) && $rule['markup_type'] === 'fixed') ? 'fixed' : 'percentage',
                'markup_value' => isset($rule['markup_value']) ? floatval($rule['markup_value']) : 0,
            ];

            if (count($clean) >= 50) {
                break;
            }
        }

        update_option('dszsync_price_rules_v2', ['rules' => $clean], false);

        wp_send_json_success([
            /* translators: %d: number of rules */
            'message' => sprintf(__('Saved %d advanced price rules.', '3s-soft-price-stock-sync-for-dropshipzone'), count($clean)),
        ]);
    }

    /**
     * AJAX: Save an import filter template
     */
    public function ajax_save_import_template() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        // Each filter field is sanitized individually when the template is built.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below
        $filters = isset($_POST['filters']) ? (array) wp_unslash($_POST['filters']) : [];

        if ($name === '') {
            wp_send_json_error(['message' => __('Template name is required.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $templates = get_option('dszsync_import_templates', []);

        if (!isset($templates[$name]) && count($templates) >= 20) {
            wp_send_json_error(['message' => __('Template limit reached (20). Delete one first.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $sort = isset($filters['sort']) ? sanitize_text_field($filters['sort']) : '';
        $templates[$name] = [
            'category' => isset($filters['category']) ? sanitize_text_field($filters['category']) : '',
            'sort' => in_array($sort, ['', 'price_asc', 'price_desc'], true) ? $sort : '',
            'in_stock' => !empty($filters['in_stock']),
            'free_shipping' => !empty($filters['free_shipping']),
            'promotion' => !empty($filters['promotion']),
            'new_arrival' => !empty($filters['new_arrival']),
        ];

        update_option('dszsync_import_templates', $templates, false);

        wp_send_json_success([
            'message' => __('Template saved.', '3s-soft-price-stock-sync-for-dropshipzone'),
            'templates' => (object) $templates,
        ]);
    }

    /**
     * AJAX: Delete an import filter template
     */
    public function ajax_delete_import_template() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $templates = get_option('dszsync_import_templates', []);

        if (!isset($templates[$name])) {
            wp_send_json_error(['message' => __('Template not found.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        unset($templates[$name]);
        update_option('dszsync_import_templates', $templates, false);

        wp_send_json_success([
            'message' => __('Template deleted.', '3s-soft-price-stock-sync-for-dropshipzone'),
            'templates' => (object) $templates,
        ]);
    }

    /**
     * Render Product Mapping page
     */
    public function render_mapping() {
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        if (!$this->product_mapper) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Product Mapper not initialized.', '3s-soft-price-stock-sync-for-dropshipzone') . '</p></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen state (current page, filter, pagination); nothing is created, changed or deleted here.
        $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
        $resync_filter = isset($_GET['resync_filter']) ? sanitize_text_field(wp_unslash($_GET['resync_filter'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state.
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination state.
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
            <?php $this->render_header(__('Product Mapping', '3s-soft-price-stock-sync-for-dropshipzone'), __('Map your WooCommerce products to Dropshipzone SKUs', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-content">
                <!-- Mapping Stats -->
                <div class="dsz-form-section">
                    <div class="dsz-mapping-stats">
                        <div class="dsz-stat">
                            <strong><?php echo intval($total); ?></strong>
                            <span><?php esc_html_e('Mapped Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                        </div>
                        <div class="dsz-stat dsz-stat-warning">
                            <strong><?php echo intval($unmapped_count); ?></strong>
                            <span><?php esc_html_e('Unmapped Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                        </div>
                        <div class="dsz-stat dsz-stat-info">
                            <strong><?php echo intval($never_synced_count); ?></strong>
                            <span><?php esc_html_e('Never Resynced', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                        </div>
                    </div>
                    
                    <?php if ($never_synced_count > 0): ?>
                    <div class="dsz-mt-4">
                        <button type="button" id="dsz-resync-never-synced" class="button button-primary">
                            <span class="dashicons dashicons-update"></span>
                            <?php 
                            /* translators: %d: number of products */
                            echo esc_html(sprintf(__('Resync %d Never Synced Products', '3s-soft-price-stock-sync-for-dropshipzone'), $never_synced_count)); 
                            ?>
                        </button>
                        <span id="dsz-resync-never-synced-status" class="dsz-ml-2"></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($unmapped_count > 0): ?>
                    <div class="dsz-mt-4">
                        <button type="button" id="dsz-scan-unmapped" class="button button-secondary">
                            <span class="dashicons dashicons-search"></span>
                            <?php 
                            /* translators: %d: number of products */
                            echo esc_html(sprintf(__('Scan %d Unmapped Products', '3s-soft-price-stock-sync-for-dropshipzone'), $unmapped_count)); 
                            ?>
                        </button>
                        <span id="dsz-scan-unmapped-status" class="dsz-ml-2"></span>
                        <p class="description dsz-mt-2">
                            <?php esc_html_e('Checks if unmapped products exist in Dropshipzone. Found products will be linked; not-found products will be marked as Non-DSZ.', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Sync Actions Callout -->
                <div class="dsz-form-section">
                    <div class="dsz-callout dsz-callout-info">
                        <span class="dashicons dashicons-info"></span>
                        <div>
                            <strong><?php esc_html_e('Looking for sync actions?', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong>
                            <p><?php
                            echo wp_kses_post(sprintf(
                                /* translators: %s: Link to Sync Center page */
                                __('Link products by SKU, update prices & stock, and refresh product data from the %s.', '3s-soft-price-stock-sync-for-dropshipzone'),
                                '<a href="' . esc_url(admin_url('admin.php?page=dsz-sync-control')) . '">' . esc_html__('Sync Center', '3s-soft-price-stock-sync-for-dropshipzone') . '</a>'
                            ));
                            ?></p>
                        </div>
                    </div>
                </div>

                <!-- Search and Add New -->
                <div class="dsz-form-section">
                    <h2><?php esc_html_e('Add New Mapping', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                    <div class="dsz-mapping-add">
                        <div class="dsz-mapping-field">
                            <label><?php esc_html_e('WooCommerce Product:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                            <input type="text" id="dsz-wc-search" placeholder="<?php esc_attr_e('Search by name or SKU...', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" />
                            <div id="dsz-wc-results" class="dsz-search-results hidden"></div>
                            <input type="hidden" id="dsz-wc-product-id" value="" />
                        </div>
                        <div class="dsz-mapping-arrow">→</div>
                        <div class="dsz-mapping-field">
                            <label><?php esc_html_e('Dropshipzone SKU:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                            <input type="text" id="dsz-dsz-sku" placeholder="<?php esc_attr_e('Enter DSZ SKU or search...', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" />
                            <div id="dsz-dsz-results" class="dsz-search-results hidden"></div>
                        </div>
                        <button type="button" id="dsz-create-mapping" class="button button-primary" disabled>
                            <?php esc_html_e('Create Mapping', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>
                    <div id="dsz-mapping-message" class="dsz-message hidden"></div>
                </div>

                <!-- Existing Mappings -->
                <div class="dsz-form-section">
                    <div class="dsz-section-header">
                        <h2><?php esc_html_e('Existing Mappings', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <button type="button" id="dsz-export-mappings" class="button">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Export CSV', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>
                    
                    <!-- Search and Filter -->
                    <form method="get" class="dsz-mapping-search">
                        <input type="hidden" name="page" value="dsz-sync-mapping" />
                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search mappings...', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" />
                        <select name="resync_filter">
                            <option value=""><?php esc_html_e('All Resync Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                            <option value="never" <?php selected($resync_filter, 'never'); ?>><?php esc_html_e('Never Resynced', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                            <option value="today" <?php selected($resync_filter, 'today'); ?>><?php esc_html_e('Resynced Today', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                            <option value="week" <?php selected($resync_filter, 'week'); ?>><?php esc_html_e('Last 7 Days', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                            <option value="month" <?php selected($resync_filter, 'month'); ?>><?php esc_html_e('Last 30 Days', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                            <option value="older" <?php selected($resync_filter, 'older'); ?>><?php esc_html_e('Older than 30 Days', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                        </select>
                        <button type="submit" class="button"><?php esc_html_e('Filter', '3s-soft-price-stock-sync-for-dropshipzone'); ?></button>
                        <?php if ($search || $resync_filter): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-mapping')); ?>" class="button"><?php esc_html_e('Clear', '3s-soft-price-stock-sync-for-dropshipzone'); ?></a>
                        <?php endif; ?>
                    </form>

                    <!-- Mappings Table -->
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('WooCommerce Product', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th><?php esc_html_e('Dropshipzone SKU', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th><?php esc_html_e('Last Resynced', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <th class="column-actions"><?php esc_html_e('Actions', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mappings)): ?>
                                <tr>
                                    <td colspan="4" class="dsz-no-logs"><?php esc_html_e('No mappings found.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mappings as $mapping): ?>
                                    <tr data-wc-id="<?php echo esc_attr($mapping['wc_product_id']); ?>">
                                        <td>
                                            <a href="<?php echo esc_url(get_edit_post_link($mapping['wc_product_id'])); ?>" target="_blank">
                                                <?php echo esc_html($mapping['wc_product_name'] ?: '#' . $mapping['wc_product_id']); ?>
                                            </a>
                                        </td>
                                        <td><code><?php echo esc_html($mapping['dsz_sku']); ?></code></td>
                                        <td><?php echo isset($mapping['last_resynced']) && $mapping['last_resynced'] ? esc_html(dszsync_format_datetime($mapping['last_resynced'])) : esc_html__('Never', '3s-soft-price-stock-sync-for-dropshipzone'); ?></td>
                                        <td class="column-actions">
                                            <button type="button" class="button button-small dsz-resync-btn" data-product-id="<?php echo esc_attr($mapping['wc_product_id']); ?>" data-sku="<?php echo esc_attr($mapping['dsz_sku']); ?>">
                                                <span class="dashicons dashicons-update"></span>
                                                <?php esc_html_e('Resync', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                            </button>
                                            <button type="button" class="button button-small dsz-unmap-btn" data-wc-id="<?php echo esc_attr($mapping['wc_product_id']); ?>">
                                                <span class="dashicons dashicons-no-alt"></span>
                                                <?php esc_html_e('Unmap', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
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
                                <a href="<?php echo esc_url($base_url . '&paged=' . ($page - 1)); ?>" class="button">&laquo; <?php esc_html_e('Previous', '3s-soft-price-stock-sync-for-dropshipzone'); ?></a>
                            <?php endif; ?>
                            
                            <span class="dsz-pagination-info">
                                <?php
                                /* translators: %1$d: current page, %2$d: total pages */
                                echo esc_html(sprintf(__('Page %1$d of %2$d', '3s-soft-price-stock-sync-for-dropshipzone'), $page, $total_pages));
                                ?>
                            </span>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo esc_url($base_url . '&paged=' . ($page + 1)); ?>" class="button"><?php esc_html_e('Next', '3s-soft-price-stock-sync-for-dropshipzone'); ?> &raquo;</a>
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(['products' => []]);
        }

        $products = $this->product_mapper->search_wc_products($search, 20);
        wp_send_json_success(['products' => $products]);
    }

    /**
     * AJAX: Search Dropshipzone products
     */
    public function ajax_search_catalog_products() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(['products' => []]);
        }

        // Search Dropshipzone API by SKU
        $response = $this->api_client->get_products(['skus' => $search, 'limit' => 40]);
        
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $wc_product_id = isset($_POST['wc_product_id']) ? intval($_POST['wc_product_id']) : 0;
        $dsz_sku = isset($_POST['dsz_sku']) ? sanitize_text_field(wp_unslash($_POST['dsz_sku'])) : '';

        if (!$wc_product_id || !$dsz_sku) {
            wp_send_json_error(['message' => __('Product ID and SKU are required', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $result = $this->product_mapper->map($wc_product_id, $dsz_sku);

        if ($result) {
            wp_send_json_success(['message' => __('Mapping created successfully', '3s-soft-price-stock-sync-for-dropshipzone')]);
        } else {
            wp_send_json_error(['message' => __('Failed to create mapping', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }
    }

    /**
     * AJAX: Unmap product
     */
    public function ajax_unmap_product() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $wc_product_id = isset($_POST['wc_product_id']) ? intval($_POST['wc_product_id']) : 0;

        if (!$wc_product_id) {
            wp_send_json_error(['message' => __('Product ID is required', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $result = $this->product_mapper->unmap($wc_product_id);

        if ($result) {
            wp_send_json_success(['message' => __('Mapping removed successfully', '3s-soft-price-stock-sync-for-dropshipzone')]);
        } else {
            wp_send_json_error(['message' => __('Failed to remove mapping', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }
    }

    /**
     * AJAX: Auto-map products
     */
    public function ajax_auto_map() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $results = $this->product_mapper->auto_map_by_sku();

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$d: mapped count, %2$d: skipped count */
                __('Auto-mapping complete! %1$d products mapped, %2$d skipped.', '3s-soft-price-stock-sync-for-dropshipzone'),
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
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Product Import', '3s-soft-price-stock-sync-for-dropshipzone'), __('Discover and import products from Dropshipzone catalog', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <!-- Hero Search Section -->
            <div class="dsz-import-hero">
                <div class="dsz-import-hero-content">
                    <h2><?php esc_html_e('Find Products to Import', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                    <p><?php esc_html_e('Search by keywords, SKU, or browse categories to find products for your store.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                    
                    <div class="dsz-import-search-wrapper">
                        <div class="dsz-import-search-box">
                            <span class="dashicons dashicons-search"></span>
                            <input type="text" id="dsz-import-search" placeholder="<?php esc_attr_e('Enter keywords, SKU, or product name...', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" />
                            <button type="button" id="dsz-import-search-btn" class="button button-primary button-hero">
                                <?php esc_html_e('Search Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Filter Cards -->
            <div class="dsz-import-quick-filters">
                <div class="dsz-quick-filter-card" data-filter="in_stock">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('In Stock', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                </div>
                <div class="dsz-quick-filter-card" data-filter="on_promotion">
                    <span class="dashicons dashicons-tag"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('On Sale', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                </div>
                <div class="dsz-quick-filter-card" data-filter="free_shipping">
                    <span class="dashicons dashicons-car"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('Free Shipping', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                </div>
                <div class="dsz-quick-filter-card" data-filter="new_arrival">
                    <span class="dashicons dashicons-star-filled"></span>
                    <span class="dsz-quick-filter-label"><?php esc_html_e('New Arrivals', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                </div>
            </div>

            <div class="dsz-content">
                <!-- Advanced Filters (Collapsible) -->
                <div class="dsz-import-filters-section">
                    <button type="button" id="dsz-toggle-filters" class="dsz-toggle-filters-btn">
                        <span class="dashicons dashicons-filter"></span>
                        <?php esc_html_e('Advanced Filters', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        <span class="dashicons dashicons-arrow-down-alt2 dsz-toggle-arrow"></span>
                    </button>
                    
                    <div id="dsz-filters-panel" class="dsz-filters-panel hidden">
                        <div class="dsz-filters-grid">
                            <!-- Category Filter -->
                            <div class="dsz-filter-item">
                                <label for="dsz-filter-category"><?php esc_html_e('Category', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                <div class="dsz-filter-select-wrapper">
                                    <select id="dsz-filter-category">
                                        <option value=""><?php esc_html_e('All Categories', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                    <button type="button" id="dsz-load-categories" class="button button-small" title="<?php esc_attr_e('Load categories from API', '3s-soft-price-stock-sync-for-dropshipzone'); ?>">
                                        <span class="dashicons dashicons-update"></span>
                                    </button>
                                </div>
                            </div>

                            <!-- Sort Options -->
                            <div class="dsz-filter-item">
                                <label for="dsz-filter-sort"><?php esc_html_e('Sort By', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                <select id="dsz-filter-sort">
                                    <option value=""><?php esc_html_e('Default', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    <option value="price_asc"><?php esc_html_e('Price: Low to High', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    <option value="price_desc"><?php esc_html_e('Price: High to Low', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- Filter templates: save/apply named filter presets -->
                        <?php $import_templates = get_option('dszsync_import_templates', []); ?>
                        <div class="dsz-filter-item dsz-mt-3">
                            <label for="dsz-import-template"><?php esc_html_e('Filter Templates', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                            <div class="dsz-flex-row">
                                <select id="dsz-import-template">
                                    <option value=""><?php esc_html_e('&mdash; Select template &mdash;', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    <?php foreach (array_keys($import_templates) as $tpl_name): ?>
                                        <option value="<?php echo esc_attr($tpl_name); ?>"><?php echo esc_html($tpl_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" id="dsz-import-template-name" placeholder="<?php esc_attr_e('Template name', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" />
                                <button type="button" id="dsz-save-template" class="button"><?php esc_html_e('Save Current', '3s-soft-price-stock-sync-for-dropshipzone'); ?></button>
                                <button type="button" id="dsz-delete-template" class="button dsz-btn-danger"><?php esc_html_e('Delete', '3s-soft-price-stock-sync-for-dropshipzone'); ?></button>
                            </div>
                        </div>

                        <div class="dsz-filters-actions">
                            <button type="button" id="dsz-apply-filters" class="button button-primary">
                                <span class="dashicons dashicons-yes"></span>
                                <?php esc_html_e('Apply Filters', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                            <button type="button" id="dsz-clear-filters" class="button button-secondary">
                                <span class="dashicons dashicons-no-alt"></span>
                                <?php esc_html_e('Clear All', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Hidden checkboxes for quick filters (JS interacts with these) -->
                <div class="hidden">
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
                        <h3><?php esc_html_e('Ready to Import', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        <p><?php esc_html_e('Search for products or click a quick filter above to browse the Dropshipzone catalog.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        // Get search parameters
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $in_stock = isset($_POST['in_stock']) && $_POST['in_stock'] === 'true';
        $free_shipping = isset($_POST['free_shipping']) && $_POST['free_shipping'] === 'true';
        $on_promotion = isset($_POST['on_promotion']) && $_POST['on_promotion'] === 'true';
        $new_arrival = isset($_POST['new_arrival']) && $_POST['new_arrival'] === 'true';
        $sort = isset($_POST['sort']) ? sanitize_text_field(wp_unslash($_POST['sort'])) : '';

        // Allow empty search to browse all products (will be limited by API limit param)

        // Build API query parameters
        $api_params = [
            'limit' => 100,
        ];

        // Add filters
        if ($category_id > 0) $api_params['category_id'] = $category_id;
        if ($in_stock) $api_params['in_stock'] = true;
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
            $sku_params['limit'] = 40; // API minimum limit
            
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

        // Belt-and-braces for the In Stock filter: the API result is trimmed
        // here as well. This used to run unconditionally, which both hid
        // out-of-stock products from anyone browsing without the filter and
        // masked the fact that in_stock was never being sent to the API.
        if ($in_stock && !empty($products)) {
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
            
            $message = __('No products found.', '3s-soft-price-stock-sync-for-dropshipzone');
            if (!empty($search)) {
                $message .= ' ' . __('Try different keywords or adjust filters.', '3s-soft-price-stock-sync-for-dropshipzone');
            } else {
                $message .= ' ' . __('Try adjusting your filters.', '3s-soft-price-stock-sync-for-dropshipzone');
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $response = $this->api_client->get_categories();

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
            wp_send_json_error(['message' => __('No categories found from API.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $this->logger->info('Categories loaded', ['count' => count($categories)]);

        // Return the flat list of categories
        wp_send_json_success(['categories' => $categories]);
    }

    /**
     * AJAX: Import product
     */
    public function ajax_import_product() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $sku = isset($_POST['sku']) ? sanitize_text_field(wp_unslash($_POST['sku'])) : '';
        
        if (!$sku) {
            wp_send_json_error(['message' => __('SKU is required', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        // Product data may be posted back from cached search results to avoid
        // a second API call. It is client-supplied, so sanitize it fully.
        $product_data = null;
        if (!empty($_POST['product_data'])) {
            $decoded = json_decode(wp_unslash($_POST['product_data']), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below
            if (is_array($decoded)) {
                $product_data = dszsync_sanitize_api_product($decoded);
            }
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
            'message' => __('Product imported successfully!', '3s-soft-price-stock-sync-for-dropshipzone'),
            'product_id' => $result,
            'edit_url' => get_edit_post_link($result, 'url')
        ]);
    }

    /**
     * AJAX: Resync existing product from Dropshipzone API
     */
    public function ajax_resync_product() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $sku = isset($_POST['sku']) ? sanitize_text_field(wp_unslash($_POST['sku'])) : '';
        
        if (!$product_id && !$sku) {
            wp_send_json_error(['message' => __('Product ID or SKU is required', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        // If we only have SKU, try to find the product ID
        if (!$product_id && $sku) {
            $product_id = wc_get_product_id_by_sku($sku);
            if (!$product_id) {
                wp_send_json_error(['message' => __('Product not found in WooCommerce', '3s-soft-price-stock-sync-for-dropshipzone')]);
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

        // Product data may be posted back from cached search results to avoid
        // a second API call. It is client-supplied, so sanitize it fully.
        $product_data = null;
        if (!empty($_POST['product_data'])) {
            $decoded = json_decode(wp_unslash($_POST['product_data']), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line
            if (is_array($decoded)) {
                $product_data = dszsync_sanitize_api_product($decoded);
            }
        }

        // Perform resync
        $result = $this->product_importer->resync_product($product_id, $product_data, $options);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Product resynced successfully!', '3s-soft-price-stock-sync-for-dropshipzone'),
            'product_id' => $result,
            'edit_url' => get_edit_post_link($result, 'url')
        ]);
    }

    /**
     * Fetch API data for a chunk of mappings and resync each product.
     *
     * Chunks are bounded (≤100) so this issues a single API request.
     *
     * @param array $mappings Mapping rows (wc_product_id, dsz_sku)
     * @param array $options  Resync options passed to Product_Importer::resync_product()
     * @return array|\WP_Error ['success' => int, 'errors' => int, 'error_details' => string[]]
     */
    private function resync_mapping_chunk($mappings, $options) {
        $skus = array_column($mappings, 'dsz_sku');

        $response = $this->api_client->get_products_by_skus($skus);
        if (is_wp_error($response)) {
            return $response;
        }

        $api_products = [];
        if (!empty($response['result'])) {
            foreach ($response['result'] as $product_data) {
                if (!empty($product_data['sku'])) {
                    $api_products[$product_data['sku']] = $product_data;
                }
            }
        }

        $success = 0;
        $errors = 0;
        $error_details = [];

        foreach ($mappings as $mapping) {
            $product_id = $mapping['wc_product_id'];
            $sku = $mapping['dsz_sku'];

            if (!isset($api_products[$sku])) {
                $errors++;
                $error_details[] = sprintf('%s: %s', $sku, __('Not found in Dropshipzone API', '3s-soft-price-stock-sync-for-dropshipzone'));
                continue;
            }

            $result = $this->product_importer->resync_product($product_id, $api_products[$sku], $options);

            if (is_wp_error($result)) {
                $errors++;
                $error_details[] = sprintf('%s: %s', $sku, $result->get_error_message());
            } else {
                $success++;
            }

            if (dszsync_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping chunk early');
                break;
            }
        }

        return [
            'success' => $success,
            'errors' => $errors,
            'error_details' => $error_details,
        ];
    }

    /**
     * Send a chunk-processing error, passing retry_after through so the
     * client can wait out a rate limit and retry the same chunk.
     *
     * @param \WP_Error $error Error from the API client
     */
    private function send_chunk_error($error) {
        $payload = ['message' => $error->get_error_message()];

        $data = $error->get_error_data();
        if (is_array($data) && !empty($data['retry_after'])) {
            $payload['retry_after'] = intval($data['retry_after']);
        }

        wp_send_json_error($payload);
    }

    /**
     * AJAX: Resync all mapped products (one bounded chunk per request)
     *
     * The client passes `offset` and keeps calling until `done` is true, so
     * large catalogs never hit PHP max_execution_time in a single request.
     * Products that are already inactive (draft + out of stock) are skipped.
     */
    public function ajax_resync_all() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $chunk_size = 20; // Small: full resync downloads images per product

        $total = $this->product_mapper->get_count();

        if ($total === 0) {
            wp_send_json_error(['message' => __('No mapped products found to resync.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $mappings = $this->product_mapper->get_mappings([
            'limit' => $chunk_size,
            'offset' => $offset,
        ]);

        if (empty($mappings)) {
            wp_send_json_success([
                'done' => true,
                'next_offset' => $offset,
                'total' => $total,
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'skipped_inactive' => 0,
                'error_details' => [],
            ]);
        }

        // Filter out products that are already inactive (draft + out of stock)
        $active_mappings = [];
        $skipped_inactive = 0;

        foreach ($mappings as $mapping) {
            $product = wc_get_product(intval($mapping['wc_product_id']));

            if (!$product) {
                $skipped_inactive++;
                continue;
            }

            if ($product->get_status() === 'draft'
                && ($product->get_stock_quantity() <= 0 || $product->get_stock_status() === 'outofstock')) {
                $skipped_inactive++;
                continue;
            }

            $active_mappings[] = $mapping;
        }

        $result = ['success' => 0, 'errors' => 0, 'error_details' => []];

        if (!empty($active_mappings)) {
            $result = $this->resync_mapping_chunk($active_mappings, [
                'update_price' => true,
                'update_stock' => true,
                'update_images' => true,
                'update_description' => true,
                'update_title' => true,
                'update_categories' => true,
            ]);

            if (is_wp_error($result)) {
                $this->send_chunk_error($result);
            }
        }

        $next_offset = $offset + count($mappings);
        $done = (count($mappings) < $chunk_size) || ($next_offset >= $total);

        $this->logger->info('Resync all chunk complete', [
            'offset' => $offset,
            'processed' => count($mappings),
            'success' => $result['success'],
            'errors' => $result['errors'],
            'skipped_inactive' => $skipped_inactive,
            'done' => $done,
        ]);

        wp_send_json_success([
            'done' => $done,
            'next_offset' => $next_offset,
            'total' => $total,
            'processed' => count($mappings),
            'success' => $result['success'],
            'errors' => $result['errors'],
            'skipped_inactive' => $skipped_inactive,
            'error_details' => array_slice($result['error_details'], 0, 10),
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        // Image refresh downloads files per product; categories are cheap
        $chunk_size = ($type === 'images') ? 20 : 50;

        $total = $this->product_mapper->get_count();

        if ($total === 0) {
            wp_send_json_error(['message' => __('No mapped products found.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $mappings = $this->product_mapper->get_mappings([
            'limit' => $chunk_size,
            'offset' => $offset,
        ]);

        if (empty($mappings)) {
            wp_send_json_success([
                'done' => true,
                'next_offset' => $offset,
                'total' => $total,
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'error_details' => [],
            ]);
        }

        $result = $this->resync_mapping_chunk($mappings, [
            'update_images' => ($type === 'images'),
            'update_categories' => ($type === 'categories'),
            'update_description' => false,
            'update_price' => false,
            'update_stock' => false,
            'update_title' => false,
        ]);

        if (is_wp_error($result)) {
            $this->send_chunk_error($result);
        }

        $next_offset = $offset + count($mappings);
        $done = (count($mappings) < $chunk_size) || ($next_offset >= $total);

        $this->logger->info($type . ' resync chunk complete', [
            'offset' => $offset,
            'processed' => count($mappings),
            'success' => $result['success'],
            'errors' => $result['errors'],
            'done' => $done,
        ]);

        wp_send_json_success([
            'done' => $done,
            'next_offset' => $next_offset,
            'total' => $total,
            'processed' => count($mappings),
            'success' => $result['success'],
            'errors' => $result['errors'],
            'error_details' => array_slice($result['error_details'], 0, 10),
        ]);
    }

    /**
     * AJAX handler for resyncing products that have never been synced
     *
     * Processes one bounded chunk per request. Successfully synced products
     * leave the "never" set (last_resynced gets stamped), so the client passes
     * its cumulative error count as `offset` to step past persistent failures.
     */
    public function ajax_resync_never_synced() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $chunk_size = 50; // Price/stock only — no image downloads

        $never_synced = $this->product_mapper->get_mappings([
            'resync_filter' => 'never',
            'limit' => $chunk_size,
            'offset' => $offset,
        ]);

        if (empty($never_synced)) {
            wp_send_json_success([
                'done' => true,
                'processed' => 0,
                'success' => 0,
                'errors' => 0,
                'error_details' => [],
            ]);
        }

        $result = $this->resync_mapping_chunk($never_synced, [
            'update_title' => false,
            'update_description' => false,
            'update_images' => false,
            'update_price' => true,
            'update_stock' => true,
        ]);

        if (is_wp_error($result)) {
            $this->send_chunk_error($result);
        }

        $done = count($never_synced) < $chunk_size;

        $this->logger->info('Never-synced resync chunk complete', [
            'offset' => $offset,
            'processed' => count($never_synced),
            'success' => $result['success'],
            'errors' => $result['errors'],
            'done' => $done,
        ]);

        wp_send_json_success([
            'done' => $done,
            'processed' => count($never_synced),
            'success' => $result['success'],
            'errors' => $result['errors'],
            'error_details' => array_slice($result['error_details'], 0, 10),
        ]);
    }

    /**
     * AJAX handler for scanning unmapped products against Dropshipzone API
     * 
     * Checks if unmapped WC products exist in DSZ:
     * - Found: creates mapping and resyncs
     * - Not found: marks as non-DSZ product (_dszsync_not_available meta)
     */
    public function ajax_scan_unmapped_products() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        // Get WC products that are NOT in our mapping table and not already
        // flagged as non-DSZ. Processing consumes the set (products get mapped
        // or flagged), so each request re-queries from the top — the client
        // keeps calling until `done` is true.
        global $wpdb;
        $mapping_table = $wpdb->prefix . 'dsz_product_mapping';
        $chunk_size = 50;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built from $wpdb->prefix and is not user input; all values are passed through prepare(). These are plugin-owned tables, so no core caching API applies.
        $unmapped_products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_value as sku
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
                LEFT JOIN %i m ON p.ID = m.wc_product_id
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_dszsync_not_available'
                WHERE p.post_type IN ('product', 'product_variation')
                AND p.post_status != 'trash'
                AND pm.meta_value != ''
                AND m.id IS NULL
                AND pm2.post_id IS NULL
                ORDER BY p.ID DESC
                LIMIT %d",
                $mapping_table,
                $chunk_size
            ),
            ARRAY_A
        );

        if (empty($unmapped_products)) {
            wp_send_json_success([
                'message' => __('No unmapped products to scan.', '3s-soft-price-stock-sync-for-dropshipzone'),
                'done' => true,
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
                    delete_post_meta($product_id, '_dszsync_not_available');
                    
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
                    $errors[] = sprintf('%s: %s', $sku, __('Failed to create mapping', '3s-soft-price-stock-sync-for-dropshipzone'));
                }
            } else {
                // Product NOT FOUND in Dropshipzone - mark as non-DSZ product
                update_post_meta($product_id, '_dszsync_not_available', '1');
                $not_found_count++;
                
                $this->logger->debug('Product marked as non-DSZ', [
                    'product_id' => $product_id,
                    'sku' => $sku,
                ]);
            }

            // Memory check
            if (dszsync_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping scan early', [
                    'processed' => $found_count + $not_found_count,
                    'total' => count($all_skus),
                ]);
                break;
            }
        }

        $message = sprintf(
            /* translators: %1$d: found count, %2$d: not found count */
            __('Scan complete! %1$d products linked to Dropshipzone, %2$d marked as non-DSZ products.', '3s-soft-price-stock-sync-for-dropshipzone'),
            $found_count,
            $not_found_count
        );

        $this->logger->info('Unmapped product scan chunk complete', [
            'total_scanned' => count($all_skus),
            'found' => $found_count,
            'not_found' => $not_found_count,
        ]);

        // Done when the set is exhausted, or nothing progressed (mapping
        // failures would otherwise be re-selected forever)
        $done = (count($unmapped_products) < $chunk_size)
            || (($found_count + $not_found_count) === 0);

        wp_send_json_success([
            'message' => $message,
            'done' => $done,
            'found' => $found_count,
            'not_found' => $not_found_count,
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    /**
     * AJAX handler for submitting order to Dropshipzone
     */
    public function ajax_submit_order() {
        check_ajax_referer('dszsync_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('Order ID required.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        if (!$this->order_handler) {
            wp_send_json_error(['message' => __('Order handler not initialized.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $result = $this->order_handler->submit_order($order_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Submit pending (paid, unsubmitted) orders to DSZ — one chunk
     * per request; the client keeps calling until `done`.
     */
    public function ajax_submit_pending_orders() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        if (!$this->order_handler) {
            wp_send_json_error(['message' => __('Order handler not initialized.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $chunk_size = 5;
        $pending = $this->order_handler->get_pending_order_ids($chunk_size);

        if (empty($pending)) {
            wp_send_json_success([
                'done' => true,
                'submitted' => 0,
                'errors' => 0,
                'error_details' => [],
            ]);
        }

        $submitted = 0;
        $errors = 0;
        $error_details = [];

        foreach ($pending as $order_id) {
            $result = $this->order_handler->submit_order($order_id);

            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'rate_limited') {
                    $data = $result->get_error_data();
                    wp_send_json_error([
                        'message' => $result->get_error_message(),
                        'retry_after' => is_array($data) && !empty($data['retry_after']) ? intval($data['retry_after']) : 30,
                    ]);
                }
                $errors++;
                $error_details[] = sprintf('#%d: %s', $order_id, $result->get_error_message());
            } else {
                $submitted++;
            }
        }

        // Failed orders stay pending, so stop when a whole chunk produced no
        // submissions to avoid looping over the same failures forever
        $done = (count($pending) < $chunk_size) || ($submitted === 0);

        wp_send_json_success([
            'done' => $done,
            'submitted' => $submitted,
            'errors' => $errors,
            'error_details' => array_slice($error_details, 0, 10),
        ]);
    }

    /**
     * AJAX: Run the tracking sync on demand
     */
    public function ajax_run_tracking_sync() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');

        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        if (!$this->order_handler) {
            wp_send_json_error(['message' => __('Order handler not initialized.', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $results = $this->order_handler->sync_tracking();

        wp_send_json_success([
            /* translators: %1$d: orders checked, %2$d: orders updated */
            'message' => sprintf(__('Tracking checked for %1$d orders, %2$d updated.', '3s-soft-price-stock-sync-for-dropshipzone'), $results['checked'], $results['updated']),
            'results' => $results,
        ]);
    }

    /**
     * Register the orders-list bulk action
     *
     * @param array $actions Bulk actions
     * @return array
     */
    public function register_order_bulk_action($actions) {
        $actions['dszsync_submit_orders'] = __('Submit to Dropshipzone', '3s-soft-price-stock-sync-for-dropshipzone');
        return $actions;
    }

    /**
     * Handle the orders-list bulk action
     *
     * @param string $redirect Redirect URL
     * @param string $action   Action name
     * @param array  $ids      Selected order IDs
     * @return string Redirect URL with result args
     */
    public function handle_order_bulk_action($redirect, $action, $ids) {
        if ($action !== 'dszsync_submit_orders' || !$this->order_handler) {
            return $redirect;
        }

        $submitted = 0;
        $errors = 0;

        foreach (array_slice((array) $ids, 0, 25) as $order_id) {
            $result = $this->order_handler->submit_order(intval($order_id));
            if (is_wp_error($result)) {
                $errors++;
            } else {
                $submitted++;
            }
        }

        return add_query_arg([
            'dszsync_bulk_submitted' => $submitted,
            'dszsync_bulk_errors' => $errors,
        ], $redirect);
    }

    /**
     * Show the result notice after the bulk submit action
     */
    public function order_bulk_action_notice() {
        if (!isset($_GET['dszsync_bulk_submitted'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        $submitted = intval($_GET['dszsync_bulk_submitted']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $errors = isset($_GET['dszsync_bulk_errors']) ? intval($_GET['dszsync_bulk_errors']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($errors > 0 ? 'warning' : 'success'),
            esc_html(sprintf(
                /* translators: %1$d: submitted count, %2$d: error count */
                __('Dropshipzone: %1$d orders submitted, %2$d failed (see order notes / logs).', '3s-soft-price-stock-sync-for-dropshipzone'),
                $submitted,
                $errors
            ))
        );
    }

    /**
     * Add the DSZ margin column to the products list
     *
     * @param array $columns Columns
     * @return array
     */
    public function add_product_margin_column($columns) {
        $columns['dszsync_margin'] = __('DSZ Margin', '3s-soft-price-stock-sync-for-dropshipzone');
        return $columns;
    }

    /**
     * Render the DSZ margin column value
     *
     * @param string $column  Column key
     * @param int    $post_id Product ID
     */
    public function render_product_margin_column($column, $post_id) {
        if ($column !== 'dszsync_margin') {
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return;
        }

        $cost = $product->get_meta('_dszsync_cost');
        if ($cost === '' || $cost === null) {
            echo '<span class="dsz-text-muted">&mdash;</span>';
            return;
        }

        $price = floatval($product->get_price());
        $margin = $price - floatval($cost);

        printf(
            '<span class="%1$s">%2$s</span>',
            esc_attr($margin >= 0 ? 'dsz-text-success' : 'dsz-text-error'),
            wp_kses_post(wc_price($margin))
        );
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
            __('Dropshipzone Order', '3s-soft-price-stock-sync-for-dropshipzone'),
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
            echo '<p>' . esc_html__('Order not found.', '3s-soft-price-stock-sync-for-dropshipzone') . '</p>';
            return;
        }

        $order_id = $order->get_id();

        // Check if order has DSZ products
        if (!$this->order_handler) {
            echo '<p>' . esc_html__('Order handler not available.', '3s-soft-price-stock-sync-for-dropshipzone') . '</p>';
            return;
        }

        $has_dszsync_products = $this->order_handler->order_has_dszsync_products($order_id);

        if (!$has_dszsync_products) {
            echo '<p class="dsz-text-muted">' . esc_html__('No Dropshipzone products in this order.', '3s-soft-price-stock-sync-for-dropshipzone') . '</p>';
            return;
        }

        // Get DSZ order info
        $dszsync_order = $this->order_handler->get_dszsync_order($order_id);
        $dszsync_serial = $order->get_meta('_dszsync_serial_number');

        wp_nonce_field('dszsync_nonce', 'dszsync_order_nonce');
        ?>
        <div class="dsz-order-box">
            <?php if ($dszsync_serial || ($dszsync_order && !empty($dszsync_order['dsz_serial_number']))): 
                $serial = $dszsync_serial ?: $dszsync_order['dsz_serial_number'];
                $status = $dszsync_order ? $dszsync_order['dsz_status'] : 'not_submitted';
            ?>
                <p>
                    <strong><?php esc_html_e('DSZ Serial:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong><br>
                    <code><?php echo esc_html($serial); ?></code>
                </p>
                <p>
                    <strong><?php esc_html_e('Status:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong><br>
                    <span class="dsz-status dsz-status-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?>
                    </span>
                </p>
                <?php if ($dszsync_order && !empty($dszsync_order['submitted_at'])): ?>
                <p>
                    <strong><?php esc_html_e('Submitted:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong><br>
                    <?php echo esc_html(dszsync_format_datetime($dszsync_order['submitted_at'])); ?>
                </p>
                <?php endif; ?>
                <?php $tracking = $order->get_meta('_dszsync_tracking_number'); ?>
                <?php if ($tracking): ?>
                <p>
                    <strong><?php esc_html_e('Tracking:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong><br>
                    <code><?php echo esc_html($tracking); ?></code>
                    <?php $courier = $order->get_meta('_dszsync_courier'); ?>
                    <?php if ($courier): ?><span class="dsz-text-muted"><?php echo esc_html($courier); ?></span><?php endif; ?>
                </p>
                <?php endif; ?>
            <?php elseif ($dszsync_order && !empty($dszsync_order['error_message'])): ?>
                <p class="dsz-error">
                    <strong><?php esc_html_e('Last Error:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong><br>
                    <?php echo esc_html($dszsync_order['error_message']); ?>
                </p>
                <button type="button" class="button button-primary dsz-submit-order-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Retry Submit', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                </button>
            <?php else: ?>
                <p><?php esc_html_e('This order has Dropshipzone products and can be submitted.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                <button type="button" class="button button-primary dsz-submit-order-btn" data-order-id="<?php echo esc_attr($order_id); ?>">
                    <span class="dashicons dashicons-upload"></span>
                    <?php esc_html_e('Submit to Dropshipzone', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                </button>
            <?php endif; ?>
            <?php
            // Estimated profit on DSZ line items (line revenue − supplier cost)
            $profit_revenue = 0.0;
            $profit_cost = 0.0;
            $profit_known = true;
            foreach ($order->get_items() as $profit_item) {
                $profit_product = $profit_item->get_product();
                if (!$profit_product || !$this->product_mapper || !$this->product_mapper->get_dsz_sku($profit_product->get_id())) {
                    continue;
                }
                $profit_revenue += floatval($profit_item->get_total());
                $cost_meta = $profit_product->get_meta('_dszsync_cost');
                if ($cost_meta === '' || $cost_meta === null) {
                    $profit_known = false;
                }
                $profit_cost += floatval($cost_meta) * $profit_item->get_quantity();
            }
            ?>
            <?php if ($profit_revenue > 0): ?>
            <p>
                <strong><?php esc_html_e('Est. Item Profit:', '3s-soft-price-stock-sync-for-dropshipzone'); ?></strong><br>
                <span class="<?php echo esc_attr(($profit_revenue - $profit_cost) >= 0 ? 'dsz-text-success' : 'dsz-text-error'); ?>">
                    <?php echo wp_kses_post(wc_price($profit_revenue - $profit_cost)); ?>
                </span>
                <span class="dsz-text-muted"><?php esc_html_e('excl. DSZ shipping', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                <?php if (!$profit_known): ?><br><span class="dsz-text-muted"><?php esc_html_e('Some supplier costs unknown — run a sync to populate them.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span><?php endif; ?>
            </p>
            <?php endif; ?>
            <div class="dsz-order-message"></div>
        </div>
        <?php
    }

    /**
     * Render Auto Import settings page
     */
    public function render_auto_import() {
        if (!dszsync_current_user_can_manage()) {
            wp_die(esc_html__('You do not have permission to access this page.', '3s-soft-price-stock-sync-for-dropshipzone'));
        }

        $settings = $this->auto_importer ? $this->auto_importer->get_settings() : [];
        $status = $this->auto_importer ? $this->auto_importer->get_status() : [];
        $next_scheduled = $this->auto_importer ? $this->auto_importer->get_next_scheduled() : false;
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Auto Import', '3s-soft-price-stock-sync-for-dropshipzone'), __('Automatically import new products from Dropshipzone', '3s-soft-price-stock-sync-for-dropshipzone')); ?>

            <div class="dsz-content">
                <form id="dsz-auto-import-form" class="dsz-form">
                    <?php wp_nonce_field('dszsync_auto_import_settings', 'dszsync_nonce'); ?>
                    
                    <!-- Status Section -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Auto Import Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <div class="dsz-cards">
                            <div class="dsz-card <?php echo esc_attr($settings['enabled'] ? 'dsz-card-success' : 'dsz-card-warning'); ?>">
                                <div class="dsz-card-icon">
                                    <span class="dashicons <?php echo esc_attr($settings['enabled'] ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo esc_html($settings['enabled'] ? __('Enabled', '3s-soft-price-stock-sync-for-dropshipzone') : __('Disabled', '3s-soft-price-stock-sync-for-dropshipzone')); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Next Run', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo $next_scheduled ? esc_html(dszsync_time_ago($next_scheduled)) : esc_html__('Not Scheduled', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </div>
                            </div>
                            <?php if (!empty($status['last_results'])): ?>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-download"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Last Run', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo isset($status['last_results']['imported']) ? intval($status['last_results']['imported']) : 0; ?> <?php esc_html_e('imported', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                    <?php if ($status['last_completed']): ?>
                                        <p class="dsz-card-meta"><?php echo esc_html(dszsync_time_ago($status['last_completed'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="dsz-form-actions dsz-mb-5">
                            <button type="button" id="dsz-run-auto-import" class="button button-secondary">
                                <span class="dashicons dashicons-download"></span>
                                <?php esc_html_e('Run Import Now', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                            </button>
                            <span id="dsz-auto-import-result" class="dsz-ml-3"></span>
                        </div>
                    </div>

                    <!-- Import Metrics Section -->
                    <?php 
                    $stats = $this->auto_importer ? $this->auto_importer->get_stats() : [];
                    $history = $this->auto_importer ? $this->auto_importer->get_history(10) : [];
                    
                    // Get realtime count of mapped products from database
                    global $wpdb;
                    $table = $wpdb->prefix . 'dsz_product_mapping';
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built from $wpdb->prefix and is not user input; all values are passed through prepare(). These are plugin-owned tables, so no core caching API applies.
                    $realtime_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
                    $realtime_count = $realtime_count !== null ? intval($realtime_count) : 0;
                    ?>
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Import Metrics', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <div class="dsz-cards">
                            <div class="dsz-card">
                                <div class="dsz-card-icon dsz-icon-success">
                                    <span class="dashicons dashicons-database"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Mapped Products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($realtime_count); ?></p>
                                    <p class="dsz-card-meta"><?php esc_html_e('in database (live)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-chart-bar"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Auto Imported', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo isset($stats['total_imported']) ? intval($stats['total_imported']) : 0; ?></p>
                                    <p class="dsz-card-meta"><?php echo isset($stats['total_runs']) ? intval($stats['total_runs']) : 0; ?> <?php esc_html_e('runs', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Last 7 Days', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($stats['last_7_days']['imported']); ?></p>
                                    <p class="dsz-card-meta"><?php echo intval($stats['last_7_days']['runs']); ?> <?php esc_html_e('runs', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </div>
                            </div>
                            <div class="dsz-card">
                                <div class="dsz-card-icon">
                                    <span class="dashicons dashicons-calendar"></span>
                                </div>
                                <div class="dsz-card-content">
                                    <h3><?php esc_html_e('Last 30 Days', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                                    <p class="dsz-card-value"><?php echo intval($stats['last_30_days']['imported']); ?></p>
                                    <p class="dsz-card-meta"><?php echo intval($stats['last_30_days']['runs']); ?> <?php esc_html_e('runs', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($history)): ?>
                        <h3><?php esc_html_e('Recent Import History', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h3>
                        <table class="widefat striped dsz-mb-5">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Date', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Imported', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Skipped', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Errors', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                    <th><?php esc_html_e('Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
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
                                            <span class="dsz-text-success"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Complete', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                                        <?php elseif ($entry['status'] === 'error'): ?>
                                            <span class="dsz-text-error"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('Error', '3s-soft-price-stock-sync-for-dropshipzone'); ?></span>
                                        <?php else: ?>
                                            <?php echo esc_html($entry['status']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="dsz-text-muted"><?php esc_html_e('No import history yet. Run an import to see metrics.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Settings Section -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Auto Import Settings', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Enable Auto Import', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'], true); ?> />
                                        <?php esc_html_e('Automatically import new products on schedule', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="frequency"><?php esc_html_e('Import Frequency', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <select name="frequency" id="frequency">
                                        <option value="hourly" <?php selected($settings['frequency'], 'hourly'); ?>><?php esc_html_e('Every Hour', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="twicedaily" <?php selected($settings['frequency'], 'twicedaily'); ?>><?php esc_html_e('Twice Daily', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="daily" <?php selected($settings['frequency'], 'daily'); ?>><?php esc_html_e('Once Daily', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="max_products_per_run"><?php esc_html_e('Max Products Per Run', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="max_products_per_run" name="max_products_per_run" value="<?php echo esc_attr($settings['max_products_per_run']); ?>" min="1" max="200" class="small-text" />
                                    <p class="description"><?php esc_html_e('Maximum number of products to import per scheduled run (1-200)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="min_stock_qty"><?php esc_html_e('Minimum Stock Quantity', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="min_stock_qty" name="min_stock_qty" value="<?php echo esc_attr(isset($settings['min_stock_qty']) ? $settings['min_stock_qty'] : 10); ?>" min="0" max="10000" class="small-text" />
                                    <p class="description"><?php esc_html_e('Only import products with at least this many units in stock (default: 100)', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="default_product_status"><?php esc_html_e('Default Product Status', '3s-soft-price-stock-sync-for-dropshipzone'); ?></label>
                                </th>
                                <td>
                                    <select name="default_product_status" id="default_product_status">
                                        <option value="publish" <?php selected($settings['default_product_status'], 'publish'); ?>><?php esc_html_e('Published', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="draft" <?php selected($settings['default_product_status'], 'draft'); ?>><?php esc_html_e('Draft', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                        <option value="pending" <?php selected($settings['default_product_status'], 'pending'); ?>><?php esc_html_e('Pending Review', '3s-soft-price-stock-sync-for-dropshipzone'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Status for newly imported products', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Filters Section -->
                    <div class="dsz-form-section">
                        <h2><?php esc_html_e('Import Filters', '3s-soft-price-stock-sync-for-dropshipzone'); ?></h2>
                        <p class="description"><?php esc_html_e('Only products matching these filters will be imported.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('New Arrivals Only', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_new_arrival" value="1" <?php checked($settings['filter_new_arrival'], true); ?> />
                                        <?php esc_html_e('Only import products marked as new arrivals', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('In Stock Only', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_in_stock" value="1" <?php checked($settings['filter_in_stock'], true); ?> />
                                        <?php esc_html_e('Only import products that are currently in stock', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Free Shipping Only', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_free_shipping" value="1" <?php checked($settings['filter_free_shipping'], true); ?> />
                                        <?php esc_html_e('Only import products with free shipping in Australia', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('On Sale Only', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_on_promotion" value="1" <?php checked(!empty($settings['filter_on_promotion']), true); ?> />
                                        <?php esc_html_e('Only import products the supplier currently has on promotion', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('New Zealand Available', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="filter_nz_available" value="1" <?php checked(!empty($settings['filter_nz_available']), true); ?> />
                                        <?php esc_html_e('Only import products the supplier can ship to New Zealand', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Supplier Blacklist', '3s-soft-price-stock-sync-for-dropshipzone'); ?></th>
                                <td>
                                    <input type="text" name="exclude_supplier_ids" class="regular-text" value="<?php echo esc_attr(isset($settings['exclude_supplier_ids']) ? $settings['exclude_supplier_ids'] : ''); ?>" placeholder="<?php esc_attr_e('e.g. 201,305,412', '3s-soft-price-stock-sync-for-dropshipzone'); ?>" />
                                    <p class="description"><?php esc_html_e('Comma-separated supplier IDs to exclude from imports (max 50). Find the supplier ID in the product data as vendor_id.', '3s-soft-price-stock-sync-for-dropshipzone'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="dsz-form-actions">
                        <button type="submit" class="button button-primary">
                            <span class="dashicons dashicons-saved"></span>
                            <?php esc_html_e('Save Settings', '3s-soft-price-stock-sync-for-dropshipzone'); ?>
                        </button>
                    </div>

                    <div id="dsz-auto-import-message" class="dsz-message hidden"></div>
                </form>
            </div>
        </div>

        <?php
    }

    /**
     * AJAX handler: Run auto import manually
     */
    public function ajax_run_auto_import() {
        check_ajax_referer('dszsync_admin_nonce', 'nonce');
        
        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        if (!$this->auto_importer) {
            wp_send_json_error(['message' => __('Auto importer not initialized', '3s-soft-price-stock-sync-for-dropshipzone')]);
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
        check_ajax_referer('dszsync_admin_nonce', 'nonce');
        
        if (!dszsync_current_user_can_manage()) {
            wp_send_json_error(['message' => __('Permission denied', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        if (!$this->auto_importer) {
            wp_send_json_error(['message' => __('Auto importer not initialized', '3s-soft-price-stock-sync-for-dropshipzone')]);
        }

        $settings = [
            'enabled'               => !empty($_POST['enabled']),
            'frequency'             => isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'daily',
            'max_products_per_run'  => isset($_POST['max_products_per_run']) ? intval($_POST['max_products_per_run']) : 50,
            'min_stock_qty'         => isset($_POST['min_stock_qty']) ? intval($_POST['min_stock_qty']) : 10,
            'default_product_status'=> isset($_POST['default_product_status']) ? sanitize_text_field(wp_unslash($_POST['default_product_status'])) : 'publish',
            'filter_new_arrival'    => !empty($_POST['filter_new_arrival']),
            'filter_in_stock'       => !empty($_POST['filter_in_stock']),
            'filter_free_shipping'  => !empty($_POST['filter_free_shipping']),
            'filter_on_promotion'   => !empty($_POST['filter_on_promotion']),
            'filter_nz_available'   => !empty($_POST['filter_nz_available']),
            'exclude_supplier_ids'  => isset($_POST['exclude_supplier_ids']) ? sanitize_text_field(wp_unslash($_POST['exclude_supplier_ids'])) : '',
        ];

        $this->auto_importer->save_settings($settings);

        // Update cron schedule
        if ($settings['enabled']) {
            $this->auto_importer->schedule_import($settings['frequency']);
        } else {
            $this->auto_importer->unschedule_import();
        }

        wp_send_json_success(['message' => __('Settings saved successfully', '3s-soft-price-stock-sync-for-dropshipzone')]);
    }
}
