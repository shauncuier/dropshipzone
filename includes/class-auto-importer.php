<?php
/**
 * Auto Importer Class
 *
 * Handles scheduled automatic import of products from Dropshipzone API
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto Importer
 */
class Auto_Importer {

    /**
     * Product Importer instance
     */
    private $product_importer;

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Default settings
     */
    private $default_settings = [
        'enabled'               => false,
        'frequency'             => 'daily',
        'max_products_per_run'  => 50,
        'min_stock_qty'         => 10,  // Lowered from 100 for more products
        'filter_new_arrival'    => false, // Disabled - was too restrictive
        'filter_in_stock'       => false, // Disabled - causes API issues, PHP filtering handles this
        'filter_free_shipping'  => false,
        'filter_category_ids'   => [],
        'default_product_status'=> 'publish',
    ];

    /**
     * Constructor
     *
     * @param Product_Importer $product_importer Product importer instance
     * @param API_Client       $api_client       API client instance
     * @param Logger           $logger           Logger instance
     */
    public function __construct(Product_Importer $product_importer, API_Client $api_client, Logger $logger) {
        $this->product_importer = $product_importer;
        $this->api_client = $api_client;
        $this->logger = $logger;
    }

    /**
     * Get auto import settings
     *
     * @return array Settings
     */
    public function get_settings() {
        $settings = get_option('dsz_auto_import_settings', []);
        return wp_parse_args($settings, $this->default_settings);
    }

    /**
     * Save auto import settings
     *
     * @param array $settings Settings to save
     * @return bool Success
     */
    public function save_settings($settings) {
        $sanitized = [
            'enabled'               => !empty($settings['enabled']),
            'frequency'             => in_array($settings['frequency'], ['hourly', 'twicedaily', 'daily']) ? $settings['frequency'] : 'daily',
            'max_products_per_run'  => max(1, min(200, intval($settings['max_products_per_run']))),
            'min_stock_qty'         => max(0, intval(isset($settings['min_stock_qty']) ? $settings['min_stock_qty'] : 100)),
            'filter_new_arrival'    => !empty($settings['filter_new_arrival']),
            'filter_in_stock'       => !empty($settings['filter_in_stock']),
            'filter_free_shipping'  => !empty($settings['filter_free_shipping']),
            'filter_category_ids'   => isset($settings['filter_category_ids']) ? array_map('intval', (array) $settings['filter_category_ids']) : [],
            'default_product_status'=> in_array($settings['default_product_status'], ['publish', 'draft', 'pending']) ? $settings['default_product_status'] : 'publish',
        ];

        return update_option('dsz_auto_import_settings', $sanitized);
    }

    /**
     * Run the auto import process
     *
     * @return array Import results
     */
    public function run_import() {
        $settings = $this->get_settings();

        if (!$settings['enabled']) {
            $this->logger->info('Auto import is disabled, skipping');
            return [
                'status'   => 'skipped',
                'message'  => __('Auto import is disabled', 'dropshipzone'),
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => 0,
            ];
        }

        $this->logger->info('Starting auto import', $settings);

        // Check if import is already in progress
        $import_state = get_option('dsz_auto_import_state', []);
        if (!empty($import_state['in_progress'])) {
            $last_update = isset($import_state['last_update']) ? $import_state['last_update'] : 0;
            if ((time() - $last_update) < 1800) { // 30 minutes
                $this->logger->warning('Auto import already in progress, skipping');
                return [
                    'status'   => 'skipped',
                    'message'  => __('Import already in progress', 'dropshipzone'),
                    'imported' => 0,
                    'skipped'  => 0,
                    'errors'   => 0,
                ];
            }
            // Reset stuck import
            $this->reset_import_state();
        }

        // Mark as in progress
        update_option('dsz_auto_import_state', [
            'in_progress' => true,
            'last_update' => time(),
            'started_at'  => time(),
        ]);

        // Fetch products from API
        $products = $this->fetch_products_to_import($settings);

        if (is_wp_error($products)) {
            $this->complete_import(['error' => $products->get_error_message()]);
            return [
                'status'   => 'error',
                'message'  => $products->get_error_message(),
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => 1,
            ];
        }

        if (empty($products)) {
            $this->complete_import(['message' => 'No new products to import']);
            return [
                'status'   => 'complete',
                'message'  => __('No new products to import', 'dropshipzone'),
                'imported' => 0,
                'skipped'  => 0,
                'errors'   => 0,
            ];
        }

        // Process import
        $results = $this->process_import($products, $settings);

        $this->complete_import($results);

        return $results;
    }

    /**
     * Fetch products from API that should be imported
     *
     * @param array $settings Import settings
     * @return array|WP_Error Products to import or error
     */
    private function fetch_products_to_import($settings) {
        $api_params = [
            'limit'   => min($settings['max_products_per_run'] * 2, 200), // Fetch extra to account for skips
            'page_no' => 1,
            'enabled' => true,
        ];

        // Apply filters
        if ($settings['filter_new_arrival']) {
            $api_params['new_arrival'] = true;
        }

        if ($settings['filter_in_stock']) {
            $api_params['in_stock'] = true;
        }

        if ($settings['filter_free_shipping']) {
            $api_params['au_free_shipping'] = true;
        }

        if (!empty($settings['filter_category_ids'])) {
            // API only supports single category_id, use first one
            $api_params['category_id'] = $settings['filter_category_ids'][0];
        }

        $this->logger->debug('Fetching products for auto import', $api_params);

        $response = $this->api_client->get_products($api_params);

        if (is_wp_error($response)) {
            return $response;
        }

        if (empty($response['result'])) {
            return [];
        }

        // Filter out products that already exist in WooCommerce or don't meet stock requirements
        $products_to_import = [];
        $min_stock = isset($settings['min_stock_qty']) ? intval($settings['min_stock_qty']) : 100;
        
        foreach ($response['result'] as $product) {
            if (empty($product['sku'])) {
                continue;
            }
            
            // Check minimum stock quantity
            $stock_qty = isset($product['stock_qty']) ? intval($product['stock_qty']) : 0;
            if ($stock_qty < $min_stock) {
                $this->logger->debug('Product skipped due to low stock', [
                    'sku' => $product['sku'],
                    'stock_qty' => $stock_qty,
                    'min_required' => $min_stock,
                ]);
                continue;
            }

            // Check if product already exists
            $existing_id = wc_get_product_id_by_sku($product['sku']);
            if ($existing_id) {
                continue;
            }

            $products_to_import[] = $product;

            // Limit to max products per run
            if (count($products_to_import) >= $settings['max_products_per_run']) {
                break;
            }
        }

        $this->logger->info('Products to import after filtering', [
            'api_total'     => count($response['result']),
            'to_import'     => count($products_to_import),
            'max_per_run'   => $settings['max_products_per_run'],
        ]);

        return $products_to_import;
    }

    /**
     * Process the import of products
     *
     * @param array $products Products to import
     * @param array $settings Import settings
     * @return array Results
     */
    private function process_import($products, $settings) {
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $error_messages = [];

        // Temporarily set default status for imported products
        $original_import_settings = get_option('dsz_sync_import_settings', []);
        update_option('dsz_sync_import_settings', array_merge($original_import_settings, [
            'default_status' => $settings['default_product_status'],
        ]));

        foreach ($products as $product) {
            try {
                // Update state
                update_option('dsz_auto_import_state', [
                    'in_progress'    => true,
                    'last_update'    => time(),
                    'current_sku'    => $product['sku'],
                    'imported_count' => $imported,
                ]);

                $result = $this->product_importer->import_product($product);

                if (is_wp_error($result)) {
                    if ($result->get_error_code() === 'product_exists') {
                        $skipped++;
                        $this->logger->debug('Product skipped (already exists)', ['sku' => $product['sku']]);
                    } else {
                        $errors++;
                        $error_messages[] = $product['sku'] . ': ' . $result->get_error_message();
                        $this->logger->error('Failed to import product', [
                            'sku'   => $product['sku'],
                            'error' => $result->get_error_message(),
                        ]);
                    }
                } else {
                    $imported++;
                    $this->logger->info('Product imported via auto import', [
                        'sku'        => $product['sku'],
                        'product_id' => $result,
                    ]);
                }

                // Memory check
                if (dsz_is_memory_near_limit(85)) {
                    $this->logger->warning('Memory limit approaching, stopping import early');
                    break;
                }

            } catch (\Exception $e) {
                $errors++;
                $error_messages[] = $product['sku'] . ': ' . $e->getMessage();
                $this->logger->error('Exception during product import', [
                    'sku'   => $product['sku'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Restore original import settings
        update_option('dsz_sync_import_settings', $original_import_settings);

        return [
            'status'         => 'complete',
            'message'        => sprintf(
                /* translators: %1$d: imported count, %2$d: skipped count, %3$d: error count */
                __('Import complete: %1$d imported, %2$d skipped, %3$d errors', 'dropshipzone'),
                $imported,
                $skipped,
                $errors
            ),
            'imported'       => $imported,
            'skipped'        => $skipped,
            'errors'         => $errors,
            'error_messages' => $error_messages,
        ];
    }

    /**
     * Complete import and update state
     *
     * @param array $results Import results
     */
    private function complete_import($results = []) {
        $state = [
            'in_progress'    => false,
            'last_update'    => time(),
            'last_completed' => time(),
            'last_results'   => $results,
        ];

        update_option('dsz_auto_import_state', $state);
        
        // Save to history for metrics tracking
        $this->save_to_history($results);

        $this->logger->info('Auto import completed', $results);
    }

    /**
     * Reset import state (for stuck imports)
     */
    public function reset_import_state() {
        update_option('dsz_auto_import_state', [
            'in_progress' => false,
            'last_update' => time(),
        ]);

        $this->logger->info('Auto import state reset');
    }

    /**
     * Get import status
     *
     * @return array Import status
     */
    public function get_status() {
        $state = get_option('dsz_auto_import_state', []);
        $settings = $this->get_settings();

        return [
            'enabled'        => $settings['enabled'],
            'in_progress'    => !empty($state['in_progress']),
            'last_completed' => isset($state['last_completed']) ? $state['last_completed'] : null,
            'last_results'   => isset($state['last_results']) ? $state['last_results'] : null,
            'settings'       => $settings,
        ];
    }

    /**
     * Schedule auto import cron
     *
     * @param string $frequency Frequency (hourly, twicedaily, daily)
     */
    public function schedule_import($frequency = 'daily') {
        wp_clear_scheduled_hook('dsz_auto_import_cron_hook');

        $valid_frequencies = ['hourly', 'twicedaily', 'daily'];
        if (in_array($frequency, $valid_frequencies)) {
            wp_schedule_event(time() + 60, $frequency, 'dsz_auto_import_cron_hook');
            $this->logger->info('Auto import scheduled', ['frequency' => $frequency]);
        }
    }

    /**
     * Unschedule auto import cron
     */
    public function unschedule_import() {
        wp_clear_scheduled_hook('dsz_auto_import_cron_hook');
        $this->logger->info('Auto import unscheduled');
    }

    /**
     * Get next scheduled import time
     *
     * @return int|false Next run timestamp or false
     */
    public function get_next_scheduled() {
        return wp_next_scheduled('dsz_auto_import_cron_hook');
    }

    /**
     * Save import run to history
     *
     * @param array $results Import results
     */
    private function save_to_history($results) {
        $history = get_option('dsz_auto_import_history', []);
        
        // Add new entry
        $entry = [
            'timestamp' => time(),
            'imported'  => isset($results['imported']) ? $results['imported'] : 0,
            'skipped'   => isset($results['skipped']) ? $results['skipped'] : 0,
            'errors'    => isset($results['errors']) ? $results['errors'] : 0,
            'status'    => isset($results['status']) ? $results['status'] : 'unknown',
        ];
        
        array_unshift($history, $entry);
        
        // Keep only last 30 runs
        $history = array_slice($history, 0, 30);
        
        update_option('dsz_auto_import_history', $history);
    }

    /**
     * Get import history
     *
     * @param int $limit Number of entries to return
     * @return array Import history
     */
    public function get_history($limit = 10) {
        $history = get_option('dsz_auto_import_history', []);
        return array_slice($history, 0, $limit);
    }

    /**
     * Get total import statistics
     *
     * @return array Total stats
     */
    public function get_stats() {
        $history = get_option('dsz_auto_import_history', []);
        
        $stats = [
            'total_runs'     => count($history),
            'total_imported' => 0,
            'total_skipped'  => 0,
            'total_errors'   => 0,
            'last_7_days'    => [
                'runs'     => 0,
                'imported' => 0,
            ],
            'last_30_days'   => [
                'runs'     => 0,
                'imported' => 0,
            ],
        ];
        
        $now = time();
        $seven_days_ago = $now - (7 * DAY_IN_SECONDS);
        $thirty_days_ago = $now - (30 * DAY_IN_SECONDS);
        
        foreach ($history as $entry) {
            $stats['total_imported'] += $entry['imported'];
            $stats['total_skipped'] += $entry['skipped'];
            $stats['total_errors'] += $entry['errors'];
            
            if ($entry['timestamp'] >= $seven_days_ago) {
                $stats['last_7_days']['runs']++;
                $stats['last_7_days']['imported'] += $entry['imported'];
            }
            
            if ($entry['timestamp'] >= $thirty_days_ago) {
                $stats['last_30_days']['runs']++;
                $stats['last_30_days']['imported'] += $entry['imported'];
            }
        }
        
        return $stats;
    }

    /**
     * Clear import history
     */
    public function clear_history() {
        delete_option('dsz_auto_import_history');
        $this->logger->info('Auto import history cleared');
    }
}
