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
     * This approach uses the mapping table for proper SKU linking:
     * 1. Get mapped products from database (WC Product ID -> DSZ SKU)
     * 2. Fetch data from Dropshipzone API using the DSZ SKUs
     * 3. Update WooCommerce products directly by ID
     * 4. Track last_synced timestamp
     *
     * @return array Batch results
     */
    private function process_sync_batch() {
        $settings = get_option('dsz_sync_settings', []);
        $batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 100;
        $current_offset = isset($settings['current_offset']) ? intval($settings['current_offset']) : 0;

        // Get plugin instances
        $plugin = dsz_sync();
        if (!$plugin || !$plugin->api_client) {
            $this->complete_sync(['error' => 'Plugin not initialized']);
            return ['status' => 'error', 'message' => 'Plugin not initialized'];
        }

        // Get product mapper
        $product_mapper = $plugin->product_mapper;
        if (!$product_mapper) {
            $this->complete_sync(['error' => 'Product mapper not initialized']);
            return ['status' => 'error', 'message' => 'Product mapper not initialized'];
        }

        // Get total count of syncable mappings
        $total_mappings = $product_mapper->get_syncable_count();
        
        if ($total_mappings === 0) {
            $this->complete_sync(['message' => 'No mapped products to sync']);
            return [
                'status' => 'complete',
                'message' => __('Sync completed - No mapped products. Use Product Mapping page to map products first.', 'dropshipzone-sync'),
                'products_updated' => 0,
                'errors_count' => 0,
            ];
        }

        // Get mapped products for this batch (WC ID -> DSZ SKU)
        $mapped_products = $product_mapper->get_mapped_skus_for_sync($batch_size, $current_offset);
        
        if (empty($mapped_products)) {
            $this->complete_sync([
                'message' => 'All products processed',
                'products_updated' => isset($settings['products_updated']) ? $settings['products_updated'] : 0,
                'errors_count' => isset($settings['errors_count']) ? $settings['errors_count'] : 0,
            ]);
            return [
                'status' => 'complete',
                'message' => __('Sync completed', 'dropshipzone-sync'),
                'products_updated' => isset($settings['products_updated']) ? $settings['products_updated'] : 0,
                'errors_count' => isset($settings['errors_count']) ? $settings['errors_count'] : 0,
            ];
        }

        // Extract DSZ SKUs from mappings
        $dsz_skus = array_column($mapped_products, 'dsz_sku');
        $mapping_lookup = []; // dsz_sku => wc_product_id
        foreach ($mapped_products as $mapping) {
            $mapping_lookup[$mapping['dsz_sku']] = $mapping['wc_product_id'];
        }

        $this->logger->info('Syncing mapped products', [
            'batch_size' => count($mapped_products),
            'offset' => $current_offset,
            'total' => $total_mappings,
            'sample_skus' => array_slice($dsz_skus, 0, 5),
        ]);

        // Fetch product data from Dropshipzone API (max 100 SKUs per request)
        $api_products = [];
        $sku_chunks = array_chunk($dsz_skus, 100);
        
        foreach ($sku_chunks as $chunk_index => $chunk) {
            $this->logger->debug('Fetching SKU chunk from Dropshipzone', [
                'chunk' => $chunk_index + 1,
                'skus_count' => count($chunk),
            ]);
            
            $response = $plugin->api_client->get_products_by_skus($chunk);
            
            if (is_wp_error($response)) {
                $this->logger->error('Failed to fetch from Dropshipzone API', [
                    'error' => $response->get_error_message(),
                    'skus_count' => count($chunk),
                ]);
                continue;
            }

            if (!empty($response['result'])) {
                $api_products = array_merge($api_products, $response['result']);
                $this->logger->debug('API response received', [
                    'products_returned' => count($response['result']),
                ]);
            }
        }

        // Create SKU-indexed map for easy lookup
        $api_products_by_sku = [];
        foreach ($api_products as $product) {
            if (!empty($product['sku'])) {
                $api_products_by_sku[$product['sku']] = $product;
            }
        }

        $this->logger->info('Dropshipzone data fetched', [
            'requested_skus' => count($dsz_skus),
            'found_skus' => count($api_products_by_sku),
        ]);

        // Process each mapped product individually
        $updated = 0;
        $errors = 0;
        $skipped = 0;
        $not_found = 0;

        foreach ($mapped_products as $mapping) {
            $wc_product_id = intval($mapping['wc_product_id']);
            $dsz_sku = $mapping['dsz_sku'];
            
            // Check if we have API data for this SKU
            if (!isset($api_products_by_sku[$dsz_sku])) {
                $not_found++;
                $this->logger->warning('Mapped SKU not found in Dropshipzone', [
                    'dsz_sku' => $dsz_sku,
                    'wc_product_id' => $wc_product_id,
                ]);

                // Check if we should deactivate missing products
                $stock_rules = $this->stock_sync->get_rules();
                if (!empty($stock_rules['deactivate_if_not_found'])) {
                    $wc_product = wc_get_product($wc_product_id);
                    if ($wc_product) {
                        $this->logger->info('Deactivating missing product', [
                            'wc_product_id' => $wc_product_id,
                            'dsz_sku' => $dsz_sku,
                        ]);

                        // Set to draft and zero stock
                        $wc_product->set_status('draft');
                        $wc_product->set_manage_stock(true);
                        $wc_product->set_stock_quantity(0);
                        $wc_product->set_stock_status('outofstock');
                        $wc_product->save();
                        
                        // Update last synced
                        $product_mapper->update_last_synced($wc_product_id);
                    }
                }
                continue;
            }

            $api_data = $api_products_by_sku[$dsz_sku];
            
            // Get WooCommerce product directly by ID
            $wc_product = wc_get_product($wc_product_id);
            if (!$wc_product) {
                $errors++;
                $this->logger->error('WooCommerce product not found', [
                    'wc_product_id' => $wc_product_id,
                    'dsz_sku' => $dsz_sku,
                ]);
                continue;
            }

            try {
                // Update PRICE
                $price_updated = $this->update_product_price($wc_product, $api_data);
                
                // Update STOCK
                $stock_updated = $this->update_product_stock($wc_product, $api_data);
                
                // Update last_synced for all checked products (not just updated)
                $product_mapper->update_last_synced($wc_product_id);
                
                if ($price_updated || $stock_updated) {
                    $wc_product->save();
                    $updated++;
                    
                    $this->logger->info('Product synced successfully', [
                        'wc_product_id' => $wc_product_id,
                        'dsz_sku' => $dsz_sku,
                        'price_updated' => $price_updated,
                        'stock_updated' => $stock_updated,
                    ]);
                } else {
                    $skipped++;
                    $this->logger->debug('Product already in sync', [
                        'wc_product_id' => $wc_product_id,
                        'dsz_sku' => $dsz_sku,
                    ]);
                }
                
            } catch (\Exception $e) {
                $errors++;
                $this->logger->error('Error syncing product', [
                    'wc_product_id' => $wc_product_id,
                    'dsz_sku' => $dsz_sku,
                    'error' => $e->getMessage(),
                ]);
            }

            // Memory check
            if (dsz_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping batch early');
                break;
            }
        }

        // Log batch summary
        $this->logger->info('Batch sync completed', [
            'updated' => $updated,
            'skipped' => $skipped,
            'not_found' => $not_found,
            'errors' => $errors,
        ]);


        // Update settings with batch results
        $settings = get_option('dsz_sync_settings', []);
        $settings['products_updated'] = (isset($settings['products_updated']) ? $settings['products_updated'] : 0) + $updated;
        $settings['errors_count'] = (isset($settings['errors_count']) ? $settings['errors_count'] : 0) + $errors;
        $settings['current_offset'] = $current_offset + count($mapped_products);
        $settings['last_batch_time'] = time();
        $settings['total_products'] = $total_mappings;

        // Check if we've processed all mapped products
        $new_offset = $current_offset + count($mapped_products);
        if ($new_offset >= $total_mappings) {
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

        // Return progress status
        $progress = ($total_mappings > 0) ? round(($new_offset / $total_mappings) * 100) : 0;
        $current_batch = floor($current_offset / $batch_size) + 1;
        $total_batches = ceil($total_mappings / $batch_size);

        $this->logger->info('Batch processed', [
            'batch' => $current_batch,
            'total_batches' => $total_batches,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return [
            'status' => 'processing',
            'message' => sprintf(__('Processing batch %d of %d', 'dropshipzone-sync'), $current_batch, $total_batches),
            'progress' => $progress,
            'products_updated' => $settings['products_updated'],
            'errors_count' => $settings['errors_count'],
        ];
    }

    /**
     * Update product price from API data
     *
     * @param \WC_Product $product WooCommerce product
     * @param array       $api_data API data from Dropshipzone
     * @return bool Whether price was updated
     */
    private function update_product_price($product, $api_data) {
        // Get price rules
        $rules = $this->price_sync->get_rules();
        
        // Get cost from API (cost is wholesale, price is sometimes RRP)
        $cost = isset($api_data['cost']) ? floatval($api_data['cost']) : 0;
        if ($cost <= 0) {
            $cost = isset($api_data['price']) ? floatval($api_data['price']) : 0;
        }
        
        if ($cost <= 0) {
            return false;
        }

        // Calculate price with markup
        $new_price = $cost;
        
        if ($rules['markup_type'] === 'percentage') {
            $new_price = $cost * (1 + ($rules['markup_value'] / 100));
        } else {
            $new_price = $cost + $rules['markup_value'];
        }

        // Apply GST if needed
        if ($rules['gst_enabled'] && $rules['gst_type'] === 'exclude') {
            $new_price = $new_price * 1.1; // Add 10% GST
        }

        // Apply rounding if enabled
        if ($rules['rounding_enabled']) {
            if ($rules['rounding_type'] === '99') {
                $new_price = floor($new_price) + 0.99;
            } elseif ($rules['rounding_type'] === '95') {
                $new_price = floor($new_price) + 0.95;
            } else {
                $new_price = round($new_price);
            }
        }

        // Check if price changed
        $current_price = floatval($product->get_regular_price());
        if (abs($current_price - $new_price) < 0.01) {
            return false; // No change
        }

        // Update price
        $product->set_regular_price($new_price);
        
        // Handle special/sale price
        if (!empty($api_data['special_price']) && floatval($api_data['special_price']) > 0) {
            $special = floatval($api_data['special_price']);
            // Apply same markup to special price
            if ($rules['markup_type'] === 'percentage') {
                $special = $special * (1 + ($rules['markup_value'] / 100));
            } else {
                $special = $special + $rules['markup_value'];
            }
            $product->set_sale_price($special);
        }

        return true;
    }

    /**
     * Update product stock from API data
     *
     * @param \WC_Product $product WooCommerce product
     * @param array       $api_data API data from Dropshipzone
     * @return bool Whether stock was updated
     */
    private function update_product_stock($product, $api_data) {
        // Get stock rules
        $rules = $this->stock_sync->get_rules();
        
        // Get stock quantity
        $stock_qty = isset($api_data['stock_qty']) ? intval($api_data['stock_qty']) : 0;
        
        // Check if out of stock based on status
        $status = isset($api_data['status']) ? $api_data['status'] : '';
        $in_stock = isset($api_data['in_stock']) ? ($api_data['in_stock'] == '1' || $api_data['in_stock'] === true) : true;
        
        if ($rules['zero_on_unavailable'] && ($status === 'Out Of Stock' || !$in_stock)) {
            $stock_qty = 0;
        }

        // Apply buffer if enabled
        if ($rules['buffer_enabled'] && $rules['buffer_amount'] > 0) {
            $stock_qty = max(0, $stock_qty - $rules['buffer_amount']);
        }

        // Check if stock changed
        $current_stock = intval($product->get_stock_quantity());
        if ($current_stock === $stock_qty) {
            return false; // No change
        }

        // Enable stock management if not already
        if (!$product->get_manage_stock()) {
            $product->set_manage_stock(true);
        }

        // Update stock
        $product->set_stock_quantity($stock_qty);

        // Update stock status
        if ($rules['auto_out_of_stock'] && $stock_qty <= 0) {
            $product->set_stock_status('outofstock');
        } elseif ($stock_qty > 0) {
            $product->set_stock_status('instock');
        }

        return true;
    }

    /**
     * Get WooCommerce products with SKUs from YOUR catalog (legacy support)
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
