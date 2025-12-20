<?php
/**
 * Cron Class
 *
 * Handles scheduled syncing with batch processing
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cron scheduler and batch processor
 */
class Cron {

    /**
     * Price Sync instance
     */
    private $price_sync;

    /**
     * Stock Sync instance
     */
    private $stock_sync;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Available frequencies
     */
    private $frequencies = [
        'hourly' => 'Every Hour',
        'twicedaily' => 'Twice Daily',
        'daily' => 'Once Daily',
    ];

    /**
     * Constructor
     *
     * @param Price_Sync $price_sync Price sync instance
     * @param Stock_Sync $stock_sync Stock sync instance
     * @param Logger     $logger     Logger instance
     */
    public function __construct(Price_Sync $price_sync, Stock_Sync $stock_sync, Logger $logger) {
        $this->price_sync = $price_sync;
        $this->stock_sync = $stock_sync;
        $this->logger = $logger;

        // Register cron hook
        add_action('dsz_sync_cron_hook', [$this, 'run_scheduled_sync']);
        
        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_six_hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'dropshipzone-sync'),
        ];
        return $schedules;
    }

    /**
     * Get available frequencies
     *
     * @return array Frequencies
     */
    public function get_frequencies() {
        return $this->frequencies;
    }

    /**
     * Schedule sync cron
     *
     * @param string $frequency Frequency (hourly, twicedaily, daily)
     */
    public function schedule_sync($frequency = 'hourly') {
        // Clear existing schedule
        wp_clear_scheduled_hook('dsz_sync_cron_hook');

        // Schedule new cron
        if (array_key_exists($frequency, $this->frequencies)) {
            wp_schedule_event(time(), $frequency, 'dsz_sync_cron_hook');
            $this->logger->info('Sync scheduled', ['frequency' => $frequency]);
        }
    }

    /**
     * Unschedule sync cron
     */
    public function unschedule_sync() {
        wp_clear_scheduled_hook('dsz_sync_cron_hook');
        $this->logger->info('Sync unscheduled');
    }

    /**
     * Get next scheduled run
     *
     * @return int|false Next run timestamp or false
     */
    public function get_next_scheduled() {
        return wp_next_scheduled('dsz_sync_cron_hook');
    }

    /**
     * Run scheduled sync (called by WP-Cron)
     */
    public function run_scheduled_sync() {
        $this->logger->info('Scheduled sync started');
        $this->run_sync(false);
    }

    /**
     * Run sync process
     *
     * @param bool $is_manual Whether this is a manual run
     * @return array Sync results
     */
    public function run_sync($is_manual = false) {
        $settings = get_option('dsz_sync_settings', []);
        
        // Check if sync is already in progress
        if (!empty($settings['sync_in_progress'])) {
            // Check if it's been stuck for more than 30 minutes
            $last_update = isset($settings['last_batch_time']) ? $settings['last_batch_time'] : 0;
            if ((time() - $last_update) < 1800) {
                $this->logger->warning('Sync already in progress, skipping');
                return [
                    'status' => 'skipped',
                    'message' => __('Sync already in progress', 'dropshipzone-sync'),
                ];
            }
            // Reset stuck sync
            $this->reset_sync_state();
        }

        // Mark sync as in progress
        $settings['sync_in_progress'] = true;
        $settings['last_batch_time'] = time();
        update_option('dsz_sync_settings', $settings);

        $results = $this->process_sync_batch();

        return $results;
    }

    /**
     * Process a single sync batch - WooCommerce catalog driven
     *
     * This approach starts from YOUR WooCommerce products and only syncs
     * those that have matching SKUs in Dropshipzone.
     *
     * @return array Batch results
     */
    private function process_sync_batch() {
        $settings = get_option('dsz_sync_settings', []);
        $batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 100;
        $current_offset = isset($settings['current_offset']) ? intval($settings['current_offset']) : 0;

        // Get API client
        $plugin = dsz_sync();
        if (!$plugin || !$plugin->api_client) {
            $this->complete_sync(['error' => 'Plugin not initialized']);
            return ['status' => 'error', 'message' => 'Plugin not initialized'];
        }

        // Get WooCommerce products with SKUs (YOUR catalog)
        $wc_products = $this->get_woocommerce_products_with_skus($batch_size, $current_offset);
        
        if (empty($wc_products['skus'])) {
            $this->complete_sync(['message' => 'No products with SKUs to sync']);
            return [
                'status' => 'complete',
                'message' => __('Sync completed - No more products to sync', 'dropshipzone-sync'),
                'products_updated' => isset($settings['products_updated']) ? $settings['products_updated'] : 0,
                'errors_count' => isset($settings['errors_count']) ? $settings['errors_count'] : 0,
            ];
        }

        $skus = $wc_products['skus'];
        $total_wc_products = $wc_products['total'];

        $this->logger->info('Fetching Dropshipzone data for WooCommerce SKUs', [
            'batch_skus' => count($skus),
            'offset' => $current_offset,
            'total' => $total_wc_products,
        ]);

        // Fetch only these SKUs from Dropshipzone API (max 100 per API call)
        $api_products = [];
        $sku_chunks = array_chunk($skus, 100); // API allows max 100 SKUs per request
        
        $this->logger->info('WooCommerce SKUs to sync', [
            'total_skus' => count($skus),
            'sample_skus' => array_slice($skus, 0, 10), // Log first 10 for debugging
        ]);
        
        foreach ($sku_chunks as $chunk_index => $chunk) {
            $this->logger->debug('Fetching SKU chunk from Dropshipzone', [
                'chunk' => $chunk_index + 1,
                'skus_in_chunk' => count($chunk),
            ]);
            
            $response = $plugin->api_client->get_products_by_skus($chunk);
            
            if (is_wp_error($response)) {
                $this->logger->error('Failed to fetch products from Dropshipzone', [
                    'error' => $response->get_error_message(),
                    'skus_count' => count($chunk),
                ]);
                continue;
            }

            // Debug: Log API response structure
            $this->logger->debug('Dropshipzone API response', [
                'has_result' => isset($response['result']),
                'result_count' => isset($response['result']) ? count($response['result']) : 0,
                'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
            ]);

            if (!empty($response['result'])) {
                $api_products = array_merge($api_products, $response['result']);
            }
        }

        // Create SKU-indexed map for easy lookup
        $api_products_by_sku = [];
        foreach ($api_products as $product) {
            if (!empty($product['sku'])) {
                $api_products_by_sku[$product['sku']] = $product;
            }
        }

        $this->logger->info('Dropshipzone products fetched', [
            'requested' => count($skus),
            'found' => count($api_products_by_sku),
            'found_skus' => array_slice(array_keys($api_products_by_sku), 0, 10), // Sample found SKUs
        ]);

        // Sync prices and stock for matched products
        $price_results = $this->price_sync->sync_batch($api_products);
        $stock_results = $this->stock_sync->sync_batch($api_products);
        
        // Debug: Log sync results
        $this->logger->info('Sync batch results', [
            'price_updated' => $price_results['updated'],
            'price_skipped' => $price_results['skipped'],
            'price_not_found' => $price_results['not_found'],
            'price_errors' => $price_results['errors'],
            'stock_updated' => $stock_results['updated'],
            'stock_skipped' => $stock_results['skipped'],
        ]);

        // Log SKUs not found in Dropshipzone
        $not_found_skus = array_diff($skus, array_keys($api_products_by_sku));
        if (!empty($not_found_skus)) {
            $this->logger->warning('SKUs not found in Dropshipzone', [
                'count' => count($not_found_skus),
                'skus' => array_slice($not_found_skus, 0, 20), // Log first 20
            ]);
        }


        // Update settings
        $settings = get_option('dsz_sync_settings', []);
        $settings['products_updated'] = (isset($settings['products_updated']) ? $settings['products_updated'] : 0) 
            + $price_results['updated'] + $stock_results['updated'];
        $settings['errors_count'] = (isset($settings['errors_count']) ? $settings['errors_count'] : 0)
            + $price_results['errors'] + $stock_results['errors'];
        $settings['current_offset'] = $current_offset + count($skus);
        $settings['last_batch_time'] = time();
        $settings['total_products'] = $total_wc_products;

        // Check if we've processed all WooCommerce products
        $new_offset = $current_offset + count($skus);
        if ($new_offset >= $total_wc_products) {
            $this->complete_sync([
                'products_updated' => $settings['products_updated'],
                'errors_count' => $settings['errors_count'],
            ]);
            return [
                'status' => 'complete',
                'message' => __('Sync completed', 'dropshipzone-sync'),
                'products_updated' => $settings['products_updated'],
                'errors_count' => $settings['errors_count'],
            ];
        }

        update_option('dsz_sync_settings', $settings);

        // Schedule next batch if not complete
        wp_schedule_single_event(time() + 5, 'dsz_sync_batch_continue');

        $current_batch = floor($current_offset / $batch_size) + 1;
        $total_batches = ceil($total_wc_products / $batch_size);

        $this->logger->info('Batch processed', [
            'batch' => $current_batch,
            'total_batches' => $total_batches,
            'price_updated' => $price_results['updated'],
            'stock_updated' => $stock_results['updated'],
            'not_found_in_dsz' => count($not_found_skus),
        ]);

        return [
            'status' => 'processing',
            'message' => sprintf(__('Processing batch %d of %d', 'dropshipzone-sync'), $current_batch, $total_batches),
            'current_page' => $current_batch,
            'total_pages' => $total_batches,
            'progress' => round(($new_offset / $total_wc_products) * 100),
            'price_results' => $price_results,
            'stock_results' => $stock_results,
        ];
    }

    /**
     * Get WooCommerce products with SKUs from YOUR catalog
     *
     * @param int $limit  Number of products per batch
     * @param int $offset Offset for pagination
     * @return array Array with 'skus' and 'total'
     */
    private function get_woocommerce_products_with_skus($limit = 100, $offset = 0) {
        global $wpdb;

        // Get total count of products with SKUs
        $total = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            AND pm.meta_value IS NOT NULL
        ");

        // Get SKUs for current batch
        $skus = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            AND pm.meta_value IS NOT NULL
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset));

        return [
            'skus' => $skus ?: [],
            'total' => intval($total),
        ];
    }

    /**
     * Complete sync and reset state
     *
     * @param array $final_results Final results to log
     */
    private function complete_sync($final_results = []) {
        $settings = get_option('dsz_sync_settings', []);
        $settings['sync_in_progress'] = false;
        $settings['current_offset'] = 0;
        $settings['last_sync'] = time();
        $settings['last_batch_time'] = null;
        
        if (isset($final_results['products_updated'])) {
            $settings['last_products_updated'] = $final_results['products_updated'];
        }
        if (isset($final_results['errors_count'])) {
            $settings['last_errors_count'] = $final_results['errors_count'];
        }

        // Reset counters for next run
        $settings['products_updated'] = 0;
        $settings['errors_count'] = 0;

        update_option('dsz_sync_settings', $settings);

        $this->logger->info('Sync completed', $final_results);
    }

    /**
     * Reset sync state (for stuck syncs)
     */
    public function reset_sync_state() {
        $settings = get_option('dsz_sync_settings', []);
        $settings['sync_in_progress'] = false;
        $settings['current_offset'] = 0;
        $settings['last_batch_time'] = null;
        update_option('dsz_sync_settings', $settings);

        $this->logger->info('Sync state reset');
    }

    /**
     * Get sync status
     *
     * @return array Sync status
     */
    public function get_sync_status() {
        $settings = get_option('dsz_sync_settings', []);
        $next_scheduled = $this->get_next_scheduled();

        return [
            'in_progress' => !empty($settings['sync_in_progress']),
            'last_sync' => isset($settings['last_sync']) ? $settings['last_sync'] : null,
            'next_scheduled' => $next_scheduled,
            'current_offset' => isset($settings['current_offset']) ? $settings['current_offset'] : 0,
            'total_products' => isset($settings['total_products']) ? $settings['total_products'] : 0,
            'products_updated' => isset($settings['products_updated']) ? $settings['products_updated'] : 0,
            'errors_count' => isset($settings['errors_count']) ? $settings['errors_count'] : 0,
            'last_products_updated' => isset($settings['last_products_updated']) ? $settings['last_products_updated'] : 0,
            'last_errors_count' => isset($settings['last_errors_count']) ? $settings['last_errors_count'] : 0,
            'frequency' => isset($settings['frequency']) ? $settings['frequency'] : 'hourly',
            'batch_size' => isset($settings['batch_size']) ? $settings['batch_size'] : 100,
        ];
    }

    /**
     * Get sync progress percentage
     *
     * @return int Progress percentage (0-100)
     */
    public function get_progress() {
        $settings = get_option('dsz_sync_settings', []);
        
        if (empty($settings['sync_in_progress'])) {
            return 100;
        }

        $total = isset($settings['total_products']) ? intval($settings['total_products']) : 0;
        $offset = isset($settings['current_offset']) ? intval($settings['current_offset']) : 0;

        if ($total <= 0) {
            return 0;
        }

        return min(100, round(($offset / $total) * 100));
    }

    /**
     * Manual sync trigger
     *
     * @return array Sync results
     */
    public function manual_sync() {
        $this->logger->info('Manual sync triggered');
        
        // Reset state for fresh start
        $this->reset_sync_state();
        
        return $this->run_sync(true);
    }

    /**
     * Continue batch processing (AJAX handler)
     *
     * @return array Batch results
     */
    public function continue_batch() {
        $settings = get_option('dsz_sync_settings', []);
        
        if (empty($settings['sync_in_progress'])) {
            return [
                'status' => 'complete',
                'message' => __('Sync not in progress', 'dropshipzone-sync'),
            ];
        }

        return $this->process_sync_batch();
    }
}

// Register batch continuation hook
add_action('dsz_sync_batch_continue', function() {
    $plugin = dsz_sync();
    if ($plugin && $plugin->cron) {
        $plugin->cron->continue_batch();
    }
});
