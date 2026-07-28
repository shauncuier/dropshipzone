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

        // Register cron hooks
        add_action('dszsync_cron_hook', [$this, 'run_scheduled_sync']);
        add_action('dszsync_incremental_hook', [$this, 'run_incremental_sync']);

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
            'display' => __('Every 6 Hours', '3s-soft-price-stock-sync-for-dropshipzone'),
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
        wp_clear_scheduled_hook('dszsync_cron_hook');

        // Schedule new cron
        if (array_key_exists($frequency, $this->frequencies)) {
            wp_schedule_event(time(), $frequency, 'dszsync_cron_hook');
            $this->logger->info('Sync scheduled', ['frequency' => $frequency]);
        }
    }

    /**
     * Unschedule sync cron
     */
    public function unschedule_sync() {
        wp_clear_scheduled_hook('dszsync_cron_hook');
        $this->logger->info('Sync unscheduled');
    }

    /**
     * Get next scheduled run
     *
     * @return int|false Next run timestamp or false
     */
    public function get_next_scheduled() {
        return wp_next_scheduled('dszsync_cron_hook');
    }

    /**
     * Schedule the incremental stock pass
     *
     * @param string $frequency Frequency (hourly, twicedaily, daily)
     */
    public function schedule_incremental($frequency = 'hourly') {
        wp_clear_scheduled_hook('dszsync_incremental_hook');

        if (array_key_exists($frequency, $this->frequencies)) {
            wp_schedule_event(time() + 300, $frequency, 'dszsync_incremental_hook');
            $this->logger->info('Incremental sync scheduled', ['frequency' => $frequency]);
        }
    }

    /**
     * Unschedule the incremental stock pass
     */
    public function unschedule_incremental() {
        wp_clear_scheduled_hook('dszsync_incremental_hook');
        $this->logger->info('Incremental sync unscheduled');
    }

    /**
     * Get next scheduled incremental run
     *
     * @return int|false Next run timestamp or false
     */
    public function get_next_incremental_scheduled() {
        return wp_next_scheduled('dszsync_incremental_hook');
    }

    /**
     * Run scheduled sync (called by WP-Cron)
     */
    public function run_scheduled_sync() {
        $this->logger->info('Scheduled sync started');
        $this->run_sync(false);
    }

    /**
     * Run the incremental stock pass (called by WP-Cron).
     *
     * The full sweep re-fetches every mapped SKU, which on a large catalogue
     * is one `/v2/products` call per 100 products per pass against a 600/hour
     * limit. This pass asks `/stock` which SKUs actually moved since the last
     * run and refreshes only those.
     *
     * `/stock` reports stock changes only, so it is a fast lane, not a
     * replacement: a cost change with no stock movement is invisible to it.
     * The full sweep still runs on its own schedule and remains the mechanism
     * that keeps prices correct.
     *
     * @return array Result summary
     */
    public function run_incremental_sync() {
        $settings = get_option('dszsync_settings', []);

        if (empty($settings['incremental_enabled'])) {
            return ['status' => 'skipped', 'message' => 'Incremental sync is disabled'];
        }

        // Never run alongside a full sweep batch — both write the same products
        if (get_transient('dszsync_batch_lock') || get_transient('dszsync_incremental_lock')) {
            $this->logger->debug('Incremental sync skipped, another batch holds the lock');
            return ['status' => 'skipped', 'message' => 'Another sync batch is running'];
        }

        set_transient('dszsync_incremental_lock', 1, 300);

        try {
            return $this->process_incremental_sync();
        } finally {
            delete_transient('dszsync_incremental_lock');
        }
    }

    /**
     * Poll /stock for changed SKUs and refresh the ones we map.
     *
     * @return array Result summary
     */
    private function process_incremental_sync() {
        $plugin = dszsync_sync();
        if (!$plugin || !$plugin->api_client || !$plugin->product_mapper) {
            return ['status' => 'error', 'message' => 'Plugin not initialized'];
        }

        $state = get_option('dszsync_incremental_state', []);
        $now   = time();

        // Resume from the end of the last successful window so nothing is
        // skipped when a run fails or the site sleeps. The API rejects ranges
        // of 10 days or more, so an old cursor is clamped rather than passed
        // through to a guaranteed error.
        $start_ts = isset($state['window_end_ts']) ? intval($state['window_end_ts']) : 0;
        if ($start_ts <= 0) {
            $start_ts = $now - HOUR_IN_SECONDS;
        }

        $oldest_allowed = $now - (9 * DAY_IN_SECONDS);
        if ($start_ts < $oldest_allowed) {
            $this->logger->warning('Incremental cursor older than the /stock 10-day limit, clamping', [
                'cursor' => gmdate('Y-m-d H:i:s', $start_ts),
            ]);
            $start_ts = $oldest_allowed;
        }

        if ($start_ts >= $now) {
            return ['status' => 'complete', 'message' => 'No new window to poll', 'refreshed' => 0];
        }

        $start_time = gmdate('Y-m-d H:i:s', $start_ts);
        $end_time   = gmdate('Y-m-d H:i:s', $now);

        /**
         * Filter the page cap for a single incremental run.
         *
         * At 160 rows per page the default covers 3,200 stock changes. A
         * busier supplier window is truncated rather than allowed to consume
         * the hourly API budget; the full sweep catches the remainder.
         *
         * @param int $max_pages Maximum /stock pages to read in one run
         */
        $max_pages = (int) apply_filters('dszsync_incremental_max_pages', 20);

        $changed_skus = [];
        $page = 1;

        do {
            $response = $plugin->api_client->get_stock([], $start_time, $end_time, $page, 160);

            if (is_wp_error($response)) {
                $this->logger->error('Incremental sync failed to read /stock', [
                    'error' => $response->get_error_message(),
                    'page'  => $page,
                ]);
                // Cursor is not advanced, so the next run retries this window
                return ['status' => 'error', 'message' => $response->get_error_message()];
            }

            $rows = [];
            if (!empty($response['result']) && is_array($response['result'])) {
                $rows = $response['result'];
            } elseif (isset($response[0])) {
                $rows = $response;
            }

            foreach ($rows as $row) {
                if (!empty($row['sku'])) {
                    $changed_skus[$row['sku']] = true;
                }
            }

            // /stock does not return total_pages the way the product and zone
            // endpoints do — it echoes total, page_no and limit. Verified
            // against the live endpoint: assuming total_pages meant reading
            // only the first page and silently dropping the rest of the window.
            if (isset($response['total_pages'])) {
                $total_pages = max(1, intval($response['total_pages']));
            } else {
                $total = isset($response['total']) ? intval($response['total']) : 0;
                $per_page = isset($response['limit']) ? max(1, intval($response['limit'])) : 160;
                $total_pages = max(1, (int) ceil($total / $per_page));
            }

            $page++;

            if ($page > $max_pages && $page <= $total_pages) {
                $this->logger->warning('Incremental sync hit the page cap, remaining changes deferred to the full sweep', [
                    'read_pages'  => $max_pages,
                    'total_pages' => $total_pages,
                ]);
                break;
            }
        } while ($page <= $total_pages && !empty($rows));

        $changed_skus = array_keys($changed_skus);

        $this->logger->info('Incremental window polled', [
            'from'         => $start_time,
            'to'           => $end_time,
            'changed_skus' => count($changed_skus),
        ]);

        $refreshed = 0;
        $errors    = 0;

        if (!empty($changed_skus)) {
            // Only SKUs this store actually maps are worth an API call
            $mapped = $plugin->product_mapper->get_syncable_by_skus($changed_skus);

            $this->logger->info('Incremental changes matched to mapped products', [
                'matched' => count($mapped),
            ]);

            if (!empty($mapped)) {
                $result    = $this->refresh_mapped_products($mapped, $plugin);
                $refreshed = $result['updated'];
                $errors    = $result['errors'];
            }
        }

        // Advance the cursor only after a clean pass
        update_option('dszsync_incremental_state', [
            'window_end_ts' => $now,
            'last_run'      => $now,
            'changed_skus'  => count($changed_skus),
            'refreshed'     => $refreshed,
        ]);

        return [
            'status'    => 'complete',
            'message'   => __('Incremental sync completed', '3s-soft-price-stock-sync-for-dropshipzone'),
            'refreshed' => $refreshed,
            'errors'    => $errors,
        ];
    }

    /**
     * Fetch and apply current API data for a set of mappings.
     *
     * @param array  $mapped Rows of ['wc_product_id' => int, 'dsz_sku' => string]
     * @param object $plugin Plugin container
     * @return array ['updated' => int, 'errors' => int]
     */
    private function refresh_mapped_products($mapped, $plugin) {
        $updated = 0;
        $errors  = 0;

        $lookup = [];
        foreach ($mapped as $row) {
            $lookup[$row['dsz_sku']] = intval($row['wc_product_id']);
        }

        // /v2/products accepts at most 100 SKUs per call
        foreach (array_chunk(array_keys($lookup), 100) as $chunk) {
            $response = $plugin->api_client->get_products_by_skus($chunk);

            if (is_wp_error($response)) {
                $errors++;
                $this->logger->error('Incremental refresh failed for a SKU chunk', [
                    'error' => $response->get_error_message(),
                    'skus'  => count($chunk),
                ]);
                continue;
            }

            if (empty($response['result'])) {
                continue;
            }

            foreach ($response['result'] as $api_data) {
                if (empty($api_data['sku']) || !isset($lookup[$api_data['sku']])) {
                    continue;
                }

                $wc_product_id = $lookup[$api_data['sku']];
                $wc_product = wc_get_product($wc_product_id);

                if (!$wc_product) {
                    $errors++;
                    continue;
                }

                try {
                    if ($this->sync_one($wc_product, $api_data)) {
                        $updated++;
                    }
                    $plugin->product_mapper->update_last_synced($wc_product_id);
                } catch (\Exception $e) {
                    $errors++;
                    $this->logger->error('Incremental refresh error', [
                        'wc_product_id' => $wc_product_id,
                        'dsz_sku'       => $api_data['sku'],
                        'error'         => $e->getMessage(),
                    ]);
                }
            }

            if (dszsync_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping incremental refresh early');
                break;
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Run sync process
     *
     * @param bool $is_manual Whether this is a manual run
     * @return array Sync results
     */
    public function run_sync($is_manual = false) {
        $settings = get_option('dszsync_settings', []);
        
        // Check if sync is already in progress
        if (!empty($settings['sync_in_progress'])) {
            // Check if it's been stuck for more than 30 minutes
            $last_update = isset($settings['last_batch_time']) ? $settings['last_batch_time'] : 0;
            if ((time() - $last_update) < 1800) {
                $this->logger->warning('Sync already in progress, skipping');
                return [
                    'status' => 'skipped',
                    'message' => __('Sync already in progress', '3s-soft-price-stock-sync-for-dropshipzone'),
                ];
            }
            // Reset stuck sync
            $this->reset_sync_state();
        }

        // Mark sync as in progress
        $settings['sync_in_progress'] = true;
        $settings['last_batch_time'] = time();
        update_option('dszsync_settings', $settings);

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
        // Prevent concurrent batches (admin AJAX polling vs scheduled continuation)
        if (get_transient('dszsync_batch_lock')) {
            return [
                'status' => 'processing',
                'message' => __('Another sync batch is already running', '3s-soft-price-stock-sync-for-dropshipzone'),
            ];
        }
        set_transient('dszsync_batch_lock', 1, 120);

        try {
            return $this->process_sync_batch_inner();
        } finally {
            delete_transient('dszsync_batch_lock');
        }
    }

    /**
     * Process a single sync batch (assumes the batch lock is held)
     *
     * @return array Batch results
     */
    private function process_sync_batch_inner() {
        $settings = get_option('dszsync_settings', []);
        $batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 100;
        $current_offset = isset($settings['current_offset']) ? intval($settings['current_offset']) : 0;

        // Get plugin instances
        $plugin = dszsync_sync();
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
                'message' => __('Sync completed - No mapped products. Use Product Mapping page to map products first.', '3s-soft-price-stock-sync-for-dropshipzone'),
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
                'message' => __('Sync completed', '3s-soft-price-stock-sync-for-dropshipzone'),
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
                $this->logger->error('Failed to fetch from Dropshipzone API - aborting batch for retry', [
                    'error' => $response->get_error_message(),
                    'skus_count' => count($chunk),
                ]);

                // Abort without advancing the offset: treating a failed fetch
                // as "not found" could wrongly deactivate healthy products.
                // The continuation event retries this same batch.
                if (!wp_next_scheduled('dszsync_batch_continue')) {
                    wp_schedule_single_event(time() + 120, 'dszsync_batch_continue');
                }

                return [
                    'status' => 'error',
                    'message' => $response->get_error_message(),
                ];
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
        $processed = 0;

        foreach ($mapped_products as $mapping) {
            $processed++;
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
                $changed = $this->sync_one($wc_product, $api_data);

                // Update last_synced for all checked products (not just updated)
                $product_mapper->update_last_synced($wc_product_id);

                if ($changed) {
                    $updated++;

                    $this->logger->info('Product synced successfully', [
                        'wc_product_id' => $wc_product_id,
                        'dsz_sku' => $dsz_sku,
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
            if (dszsync_is_memory_near_limit(85)) {
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
        $settings = get_option('dszsync_settings', []);
        $settings['products_updated'] = (isset($settings['products_updated']) ? $settings['products_updated'] : 0) + $updated;
        $settings['errors_count'] = (isset($settings['errors_count']) ? $settings['errors_count'] : 0) + $errors;
        // Advance by the number actually processed (a memory-limit break can end the batch early)
        $settings['current_offset'] = $current_offset + $processed;
        $settings['last_batch_time'] = time();
        $settings['total_products'] = $total_mappings;

        // Check if we've processed all mapped products
        $new_offset = $current_offset + $processed;
        if ($new_offset >= $total_mappings) {
            $this->complete_sync([
                'products_updated' => $settings['products_updated'],
                'errors_count' => $settings['errors_count'],
            ]);
            return [
                'status' => 'complete',
                'message' => __('Sync completed', '3s-soft-price-stock-sync-for-dropshipzone'),
                'products_updated' => $settings['products_updated'],
                'errors_count' => $settings['errors_count'],
            ];
        }

        update_option('dszsync_settings', $settings);

        // Schedule the next batch so scheduled syncs progress without manual AJAX polling
        if (!wp_next_scheduled('dszsync_batch_continue')) {
            wp_schedule_single_event(time() + 60, 'dszsync_batch_continue');
        }

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
            /* translators: %1$d: current batch number, %2$d: total batches */
            'message' => sprintf(__('Processing batch %1$d of %2$d', '3s-soft-price-stock-sync-for-dropshipzone'), $current_batch, $total_batches),
            'progress' => $progress,
            'products_updated' => $settings['products_updated'],
            'errors_count' => $settings['errors_count'],
        ];
    }

    /**
     * Apply one product's API payload and save if anything changed.
     *
     * Single entry point for both the full sweep and the incremental pass.
     * Keeping one implementation is deliberate: the sale-price handling was
     * previously written twice and the live copy drifted, so specials never
     * cleared (fixed in 3.3.5).
     *
     * @param \WC_Product $product  WooCommerce product
     * @param array       $api_data API data from Dropshipzone
     * @return bool Whether the product was changed and saved
     */
    private function sync_one($product, $api_data) {
        $price_updated   = $this->update_product_price($product, $api_data);
        $stock_updated   = $this->update_product_stock($product, $api_data);
        $catalog_updated = $this->update_product_catalog_fields($product, $api_data);

        if ($price_updated || $stock_updated || $catalog_updated) {
            $product->save();
            return true;
        }

        return false;
    }

    /**
     * Map supplier catalogue fields that are not price or stock.
     *
     * `updated_at` is stored so a future sync can tell whether the supplier
     * record moved at all. EAN and brand map to WooCommerce's native fields
     * where the running version provides them, and are skipped otherwise —
     * the plugin supports WooCommerce 8.0, GTIN landed in 9.2 and the brands
     * taxonomy in 9.4.
     *
     * @param \WC_Product $product  WooCommerce product
     * @param array       $api_data API data from Dropshipzone
     * @return bool Whether anything changed
     */
    private function update_product_catalog_fields($product, $api_data) {
        $changed = false;

        // Written directly rather than through the CRUD object: this is
        // bookkeeping, and a supplier timestamp moving is not on its own a
        // reason to save the whole product.
        if (!empty($api_data['updated_at'])) {
            $updated_at = (string) $api_data['updated_at'];
            if ((string) get_post_meta($product->get_id(), '_dszsync_updated_at', true) !== $updated_at) {
                update_post_meta($product->get_id(), '_dszsync_updated_at', $updated_at);
            }
        }

        if (!empty($api_data['eancode']) && method_exists($product, 'set_global_unique_id')) {
            $ean = preg_replace('/[^0-9]/', '', (string) $api_data['eancode']);

            // Most of the catalogue carries the literal string "N/A" here, and
            // some records hold digit strings that are not GTINs at all. Only
            // the four valid GTIN lengths are accepted — this field feeds
            // product feeds, so a wrong value is worse than none.
            if (in_array(strlen($ean), [8, 12, 13, 14], true)
                && (string) $product->get_global_unique_id() !== $ean) {
                $product->set_global_unique_id($ean);
                $changed = true;
            }
        }

        if (!empty($api_data['brand']) && taxonomy_exists('product_brand')) {
            $brand = sanitize_text_field((string) $api_data['brand']);

            if (self::is_real_brand($brand)) {
                $current = wp_get_object_terms($product->get_id(), 'product_brand', ['fields' => 'names']);

                if (!is_wp_error($current) && !in_array($brand, $current, true)) {
                    $result = wp_set_object_terms($product->get_id(), $brand, 'product_brand', false);
                    if (!is_wp_error($result)) {
                        $changed = true;
                    }
                }
            }
        }

        return $changed;
    }

    /**
     * Is this supplier brand value an actual brand?
     *
     * Roughly three quarters of the catalogue carries a marketplace filler
     * value such as "Does not apply" rather than a brand. Creating a
     * `product_brand` term for those would put most of the catalogue under a
     * meaningless brand and surface it in storefront filters.
     *
     * @param string $brand Raw brand value from the API
     * @return bool
     */
    private static function is_real_brand($brand) {
        $brand = trim($brand);

        if ($brand === '') {
            return false;
        }

        $placeholders = [
            'does not apply',
            'doesnotapply',
            'n/a',
            'na',
            'none',
            'null',
            'unbranded',
            'no brand',
            'unknown',
            '-',
            '--',
        ];

        /**
         * Filter the supplier brand values treated as "no brand".
         *
         * @param array $placeholders Lower-case values to reject
         */
        $placeholders = (array) apply_filters('dszsync_brand_placeholders', $placeholders);

        return !in_array(strtolower($brand), array_map('strtolower', $placeholders), true);
    }

    /**
     * Update product price from API data
     *
     * @param \WC_Product $product WooCommerce product
     * @param array       $api_data API data from Dropshipzone
     * @return bool Whether price was updated
     */
    private function update_product_price($product, $api_data) {
        // Get supplier cost from API (shared source with import/price sync paths)
        $cost = dszsync_get_api_cost($api_data);

        if ($cost <= 0) {
            return false;
        }

        // Shared pricing engine — advanced rules (category/supplier/SKU prefix)
        // resolve via the product context, falling back to global rules
        $new_price = $this->price_sync->calculate_price($cost, $api_data);

        // Track supplier cost for profit reporting
        $product->update_meta_data('_dszsync_cost', $cost);

        // Recommended retail price, when the supplier publishes one. Arrives as
        // RrpPrice and/or RRP.Standard depending on the response schema.
        $rrp = 0.0;
        if (isset($api_data['RrpPrice'])) {
            $rrp = floatval($api_data['RrpPrice']);
        } elseif (isset($api_data['RRP']['Standard'])) {
            $rrp = floatval($api_data['RRP']['Standard']);
        }
        if ($rrp > 0) {
            $product->update_meta_data('_dszsync_rrp', $rrp);
        }

        /**
         * Filter the calculated price before it is saved.
         *
         * @param float $new_price  Calculated price after markup/GST/rounding
         * @param int   $product_id WooCommerce product ID
         * @param float $cost       Supplier cost the price was derived from
         */
        $new_price = (float) apply_filters('dszsync_calculated_price', $new_price, $product->get_id(), $cost);

        // Regular price
        $current_price = floatval($product->get_regular_price());
        $regular_changed = (abs($current_price - $new_price) >= 0.01);

        if ($regular_changed) {
            $product->set_regular_price($new_price);

            /**
             * Fires after a product price has been updated by the sync.
             *
             * @param int   $product_id    WooCommerce product ID
             * @param float $current_price Previous regular price
             * @param float $new_price     New regular price
             */
            do_action('dszsync_price_updated', $product->get_id(), $current_price, $new_price);
        }

        // Sale price is resolved on every run, not only when the regular
        // price moved. A special can start, change or end while the supplier
        // cost stays flat, and an ended special must clear — otherwise the
        // product keeps selling below the intended price indefinitely.
        $desired_sale = null;
        if (!empty($api_data['special_price']) && floatval($api_data['special_price']) > 0) {
            $special = $this->price_sync->calculate_price(floatval($api_data['special_price']), $api_data);
            if ($special < $new_price) {
                $desired_sale = $special;
            }
        }

        $current_sale = $product->get_sale_price();
        $sale_changed = false;

        if ($desired_sale !== null) {
            if ($current_sale === '' || abs(floatval($current_sale) - $desired_sale) >= 0.01) {
                $product->set_sale_price($desired_sale);
                $sale_changed = true;
            }

            // Honour the supplier's promotion window when one is supplied
            $from = !empty($api_data['special_price_from_date'])
                ? strtotime($api_data['special_price_from_date'])
                : '';
            $to = !empty($api_data['special_price_end_date'])
                ? strtotime($api_data['special_price_end_date'])
                : '';
            $product->set_date_on_sale_from($from ?: '');
            $product->set_date_on_sale_to($to ?: '');
        } elseif ($current_sale !== '') {
            // Special ended or was withdrawn — remove it and its dates
            $product->set_sale_price('');
            $product->set_date_on_sale_from('');
            $product->set_date_on_sale_to('');
            $sale_changed = true;
        }

        return $regular_changed || $sale_changed;
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
        $supplier_stock = $stock_qty;
        
        // Check if out of stock based on status
        $status = isset($api_data['status']) ? $api_data['status'] : '';
        // When the API omits in_stock, derive availability from the quantity
        $in_stock = isset($api_data['in_stock'])
            ? ($api_data['in_stock'] == '1' || $api_data['in_stock'] === true)
            : ($stock_qty > 0);
        
        if ($rules['zero_on_unavailable'] && ($status === 'Out Of Stock' || !$in_stock)) {
            $stock_qty = 0;
        }

        // Apply buffer if enabled
        if ($rules['buffer_enabled'] && $rules['buffer_amount'] > 0) {
            $stock_qty = max(0, $stock_qty - $rules['buffer_amount']);
        }

        /**
         * Filter the calculated stock quantity before it is saved.
         *
         * @param int $stock_qty      Calculated stock after availability/buffer rules
         * @param int $product_id     WooCommerce product ID
         * @param int $supplier_stock Raw supplier stock from the API
         */
        $stock_qty = (int) apply_filters('dszsync_calculated_stock', $stock_qty, $product->get_id(), $supplier_stock);

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
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built from $wpdb->prefix and is not user input; all values are passed through prepare(). These are plugin-owned tables, so no core caching API applies.
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
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is built from $wpdb->prefix and is not user input; all values are passed through prepare(). These are plugin-owned tables, so no core caching API applies.
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
        $settings = get_option('dszsync_settings', []);
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

        update_option('dszsync_settings', $settings);

        // No further batches needed
        wp_clear_scheduled_hook('dszsync_batch_continue');

        $this->logger->info('Sync completed', $final_results);

        /**
         * Fires when a full sync run completes.
         *
         * @param array $stats { updated: int, skipped: int, errors: int }
         */
        do_action('dszsync_sync_completed', [
            'updated' => isset($final_results['products_updated']) ? intval($final_results['products_updated']) : 0,
            'skipped' => isset($final_results['skipped']) ? intval($final_results['skipped']) : 0,
            'errors' => isset($final_results['errors_count']) ? intval($final_results['errors_count']) : 0,
        ]);
    }

    /**
     * Reset sync state (for stuck syncs)
     */
    public function reset_sync_state() {
        $settings = get_option('dszsync_settings', []);
        $settings['sync_in_progress'] = false;
        $settings['current_offset'] = 0;
        $settings['last_batch_time'] = null;
        update_option('dszsync_settings', $settings);

        wp_clear_scheduled_hook('dszsync_batch_continue');

        $this->logger->info('Sync state reset');
    }

    /**
     * Get sync status
     *
     * @return array Sync status
     */
    public function get_sync_status() {
        $settings = get_option('dszsync_settings', []);
        $next_scheduled = $this->get_next_scheduled();
        $incremental = get_option('dszsync_incremental_state', []);

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
            'incremental_enabled' => !empty($settings['incremental_enabled']),
            'incremental_frequency' => isset($settings['incremental_frequency']) ? $settings['incremental_frequency'] : 'hourly',
            'incremental_next_scheduled' => $this->get_next_incremental_scheduled(),
            'incremental_last_run' => isset($incremental['last_run']) ? intval($incremental['last_run']) : 0,
            'incremental_refreshed' => isset($incremental['refreshed']) ? intval($incremental['refreshed']) : 0,
        ];
    }

    /**
     * Get sync progress percentage
     *
     * @return int Progress percentage (0-100)
     */
    public function get_progress() {
        $settings = get_option('dszsync_settings', []);
        
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
        $settings = get_option('dszsync_settings', []);
        
        if (empty($settings['sync_in_progress'])) {
            return [
                'status' => 'complete',
                'message' => __('Sync not in progress', '3s-soft-price-stock-sync-for-dropshipzone'),
            ];
        }

        return $this->process_sync_batch();
    }
}

// Register batch continuation hook
add_action('dszsync_batch_continue', function() {
    $plugin = dszsync_sync();
    if ($plugin && $plugin->cron) {
        $plugin->cron->continue_batch();
    }
});
