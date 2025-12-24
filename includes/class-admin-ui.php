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
        add_action('wp_ajax_dsz_get_categories', [$this, 'ajax_get_categories']);
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
                    <div class="dsz-card dsz-card-status <?php echo $token_status['is_valid'] ? 'dsz-card-success' : 'dsz-card-error'; ?>">
                        <div class="dsz-card-icon">
                            <span class="dashicons <?php echo $token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
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

                    <div class="dsz-card <?php echo $error_count > 0 ? 'dsz-card-warning' : ''; ?>">
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
                            <div class="dsz-progress-fill" style="width: <?php echo $this->cron->get_progress(); ?>%"></div>
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
                                    <input type="password" id="dsz_api_password" name="dsz_api_password" value="" class="regular-text" placeholder="<?php echo $email ? '••••••••' : ''; ?>" />
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
                        <div class="dsz-status-box <?php echo $token_status['is_valid'] ? 'dsz-status-success' : 'dsz-status-warning'; ?>">
                            <span class="dashicons <?php echo $token_status['is_valid'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
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
                    <div class="dsz-sync-card <?php echo $in_progress ? 'dsz-card-syncing' : 'dsz-card-idle'; ?>">
                        <div class="dsz-sync-card-icon">
                            <span class="dashicons <?php echo $in_progress ? 'dashicons-update-alt dsz-spin' : 'dashicons-yes-alt'; ?>"></span>
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
                            <button type="button" id="dsz-run-sync" class="button button-primary" <?php echo $in_progress ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-update-alt"></span>
                                <?php esc_html_e('Update Now', 'dropshipzone'); ?>
                            </button>
                        </div>
                        <!-- Progress Section -->
                        <div id="dsz-progress-container" class="dsz-progress-console <?php echo $in_progress ? '' : 'hidden'; ?>">
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

                    <!-- Action Card 3: Refresh Product Data -->
                    <div class="dsz-action-card">
                        <div class="dsz-action-card-header">
                            <span class="dashicons dashicons-download"></span>
                            <h3><?php esc_html_e('Refresh Product Data', 'dropshipzone'); ?></h3>
                        </div>
                        <p class="dsz-action-card-desc">
                            <?php esc_html_e('Re-download images, descriptions, and all product details from Dropshipzone for linked products.', 'dropshipzone'); ?>
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
        ?>
        <div class="wrap dsz-wrap">
            <?php $this->render_header(__('Sync Logs', 'dropshipzone'), __('View sync activity and error logs', 'dropshipzone')); ?>

            <div class="dsz-content">
                <!-- Filters -->
                <div class="dsz-logs-toolbar">
                    <div class="dsz-logs-filters">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs')); ?>" class="button <?php echo empty($level) ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('All', 'dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=info')); ?>" class="button <?php echo $level === 'info' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Info', 'dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=warning')); ?>" class="button <?php echo $level === 'warning' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Warnings', 'dropshipzone'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-logs&level=error')); ?>" class="button <?php echo $level === 'error' ? 'button-primary' : ''; ?>">
                            <?php esc_html_e('Errors', 'dropshipzone'); ?>
                        </a>
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

                <!-- Logs Table -->
                <table class="wp-list-table widefat fixed striped dsz-logs-table">
                    <thead>
                        <tr>
                            <th class="column-level"><?php esc_html_e('Level', 'dropshipzone'); ?></th>
                            <th class="column-message"><?php esc_html_e('Message', 'dropshipzone'); ?></th>
                            <th class="column-context"><?php esc_html_e('Context', 'dropshipzone'); ?></th>
                            <th class="column-date"><?php esc_html_e('Date', 'dropshipzone'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="4" class="dsz-no-logs"><?php esc_html_e('No logs found.', 'dropshipzone'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="column-level"><?php echo wp_kses_post(Logger::get_level_badge($log['level'])); ?></td>
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
                    </div>
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
                    
                    <!-- Search -->
                    <form method="get" class="dsz-mapping-search">
                        <input type="hidden" name="page" value="dsz-sync-mapping" />
                        <input type="text" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search mappings...', 'dropshipzone'); ?>" />
                        <button type="submit" class="button"><?php esc_html_e('Search', 'dropshipzone'); ?></button>
                        <?php if ($search): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dsz-sync-mapping')); ?>" class="button"><?php esc_html_e('Clear', 'dropshipzone'); ?></a>
                        <?php endif; ?>
                    </form>

                    <!-- Mappings Table -->
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('WooCommerce Product', 'dropshipzone'); ?></th>
                                <th><?php esc_html_e('Dropshipzone SKU', 'dropshipzone'); ?></th>
                                <th><?php esc_html_e('Last Synced', 'dropshipzone'); ?></th>
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
                                        <td><?php echo $mapping['last_synced'] ? esc_html(dsz_format_datetime($mapping['last_synced'])) : esc_html__('Never', 'dropshipzone'); ?></td>
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
                            $base_url = admin_url('admin.php?page=dsz-sync-mapping' . ($search ? '&search=' . urlencode($search) : ''));
                            
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
            <?php $this->render_header(__('Product Import', 'dropshipzone'), __('Search and import new products from Dropshipzone', 'dropshipzone')); ?>

            <div class="dsz-content">
                <div class="dsz-form-section">
                    <!-- Advanced Search Bar -->
                    <div class="dsz-import-search-bar">
                        <input type="text" id="dsz-import-search" placeholder="<?php _e('Enter keywords or SKU...', 'dropshipzone'); ?>" />
                        <button type="button" id="dsz-import-search-btn" class="button button-primary">
                            <span class="dashicons dashicons-search"></span>
                            <?php _e('Search', 'dropshipzone'); ?>
                        </button>
                    </div>

                    <!-- Advanced Filters (Collapsible) -->
                    <div class="dsz-import-filters">
                        <button type="button" id="dsz-toggle-filters" class="button button-secondary dsz-toggle-filters-btn">
                            <span class="dashicons dashicons-filter"></span>
                            <?php _e('Advanced Filters', 'dropshipzone'); ?>
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                        
                        <div id="dsz-filters-panel" class="dsz-filters-panel hidden">
                            <div class="dsz-filters-grid">
                                <!-- Category Filter -->
                                <div class="dsz-filter-item">
                                    <label for="dsz-filter-category"><?php _e('Category', 'dropshipzone'); ?></label>
                                    <select id="dsz-filter-category">
                                        <option value=""><?php _e('All Categories', 'dropshipzone'); ?></option>
                                    </select>
                                    <button type="button" id="dsz-load-categories" class="button button-small" title="<?php _e('Load categories from API', 'dropshipzone'); ?>">
                                        <span class="dashicons dashicons-update"></span>
                                    </button>
                                </div>

                                <!-- Stock Filter -->
                                <div class="dsz-filter-item">
                                    <label><?php _e('Stock Status', 'dropshipzone'); ?></label>
                                    <div class="dsz-filter-checkbox">
                                        <input type="checkbox" id="dsz-filter-instock" value="1">
                                        <label for="dsz-filter-instock"><?php _e('In Stock Only', 'dropshipzone'); ?></label>
                                    </div>
                                </div>

                                <!-- Quick Filters -->
                                <div class="dsz-filter-item">
                                    <label><?php _e('Quick Filters', 'dropshipzone'); ?></label>
                                    <div class="dsz-filter-checkboxes">
                                        <div class="dsz-filter-checkbox">
                                            <input type="checkbox" id="dsz-filter-freeship" value="1">
                                            <label for="dsz-filter-freeship"><?php _e('Free Shipping', 'dropshipzone'); ?></label>
                                        </div>
                                        <div class="dsz-filter-checkbox">
                                            <input type="checkbox" id="dsz-filter-promotion" value="1">
                                            <label for="dsz-filter-promotion"><?php _e('On Promotion', 'dropshipzone'); ?></label>
                                        </div>
                                        <div class="dsz-filter-checkbox">
                                            <input type="checkbox" id="dsz-filter-newarrivals" value="1">
                                            <label for="dsz-filter-newarrivals"><?php _e('New Arrivals', 'dropshipzone'); ?></label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sort Options -->
                                <div class="dsz-filter-item">
                                    <label for="dsz-filter-sort"><?php _e('Sort By', 'dropshipzone'); ?></label>
                                    <select id="dsz-filter-sort">
                                        <option value=""><?php _e('Default', 'dropshipzone'); ?></option>
                                        <option value="price_asc"><?php _e('Price: Low to High', 'dropshipzone'); ?></option>
                                        <option value="price_desc"><?php _e('Price: High to Low', 'dropshipzone'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="dsz-filters-actions">
                                <button type="button" id="dsz-apply-filters" class="button button-primary">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php _e('Apply Filters', 'dropshipzone'); ?>
                                </button>
                                <button type="button" id="dsz-clear-filters" class="button button-secondary">
                                    <span class="dashicons dashicons-no-alt"></span>
                                    <?php _e('Clear All', 'dropshipzone'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Results Info -->
                <div id="dsz-search-info" class="dsz-search-info hidden">
                    <span id="dsz-result-count"></span>
                    <span id="dsz-active-filters"></span>
                </div>

                <div id="dsz-import-results" class="dsz-import-results-container">
                    <div class="dsz-import-empty">
                        <span class="dashicons dashicons-search"></span>
                        <p><?php _e('Search for products using keywords, SKU, or browse by category.', 'dropshipzone'); ?></p>
                        <p class="dsz-import-empty-hint"><?php _e('Use Advanced Filters to narrow down results by stock status, promotions, or new arrivals.', 'dropshipzone'); ?></p>
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
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        if (empty($response)) {
            wp_send_json_error(['message' => __('No categories found or API error.', 'dropshipzone')]);
        }

        // Return the flat list of categories
        wp_send_json_success(['categories' => $response]);
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
            /* translators: %1$d: success count, %2$d: total count */
            __('Resync complete! %1$d of %2$d products resynced successfully.', 'dropshipzone'),
            $success_count,
            $total
        );

        if ($error_count > 0) {
            /* translators: %d: error count */
            $message .= ' ' . sprintf(__('%d errors occurred.', 'dropshipzone'), $error_count);
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
