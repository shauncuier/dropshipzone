<?php
/**
 * Stock Sync Class
 *
 * Handles syncing product stock from Dropshipzone API
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stock Sync Engine
 */
class Stock_Sync {

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Stock rules
     */
    private $stock_rules;

    /**
     * Constructor
     *
     * @param API_Client $api_client API client instance
     * @param Logger     $logger     Logger instance
     */
    public function __construct(API_Client $api_client, Logger $logger) {
        $this->api_client = $api_client;
        $this->logger = $logger;
        $this->load_stock_rules();
    }

    /**
     * Load stock rules from options
     */
    private function load_stock_rules() {
        $defaults = [
            'buffer_enabled' => false,
            'buffer_amount' => 0,
            'zero_on_unavailable' => true,
            'auto_out_of_stock' => true,
            'deactivate_if_not_found' => true, // Set products to draft if not found in Dropshipzone API
        ];

        $this->stock_rules = wp_parse_args(
            get_option('dsz_sync_stock_rules', []),
            $defaults
        );
    }

    /**
     * Reload stock rules
     */
    public function reload_rules() {
        $this->load_stock_rules();
    }

    /**
     * Get current stock rules
     *
     * @return array Stock rules
     */
    public function get_rules() {
        return $this->stock_rules;
    }

    /**
     * Calculate final stock based on rules
     *
     * @param int $supplier_stock Original stock quantity
     * @return int Final calculated stock
     */
    public function calculate_stock($supplier_stock) {
        return dsz_calculate_stock(
            $supplier_stock,
            $this->stock_rules['buffer_enabled'],
            $this->stock_rules['buffer_amount']
        );
    }

    /**
     * Sync stock for a batch of products from API data
     *
     * @param array $api_products Array of products from API
     * @return array Sync results
     */
    public function sync_batch($api_products) {
        $results = [
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'not_found' => 0,
            'details' => [],
        ];

        if (empty($api_products)) {
            return $results;
        }

        foreach ($api_products as $api_product) {
            $sku = isset($api_product['sku']) ? $api_product['sku'] : '';
            
            if (empty($sku)) {
                $results['skipped']++;
                continue;
            }

            $result = $this->sync_product_stock($api_product);
            
            if ($result['status'] === 'updated') {
                $results['updated']++;
            } elseif ($result['status'] === 'not_found') {
                $results['not_found']++;
            } elseif ($result['status'] === 'error') {
                $results['errors']++;
            } else {
                $results['skipped']++;
            }

            $results['details'][] = $result;

            // Check memory usage
            if (dsz_is_memory_near_limit(85)) {
                $this->logger->warning('Memory limit approaching, stopping batch early');
                break;
            }
        }

        return $results;
    }

    /**
     * Sync stock for a single product
     *
     * @param array $api_product Product data from API
     * @return array Sync result
     */
    public function sync_product_stock($api_product) {
        $sku = isset($api_product['sku']) ? trim($api_product['sku']) : '';
        
        if (empty($sku)) {
            return [
                'status' => 'skipped',
                'sku' => '',
                'message' => 'Empty SKU',
            ];
        }

        // Find WooCommerce product by SKU
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            $this->logger->debug('SKU not found in WooCommerce', ['sku' => $sku]);
            return [
                'status' => 'not_found',
                'sku' => $sku,
                'message' => 'Product not found (SKU mismatch)',
            ];
        }

        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [
                'status' => 'error',
                'sku' => $sku,
                'message' => 'Failed to load product',
            ];
        }

        try {
            // Get supplier stock info
            $supplier_stock = isset($api_product['stock_qty']) ? intval($api_product['stock_qty']) : 0;
            $api_status = isset($api_product['status']) ? $api_product['status'] : '';
            $in_stock = isset($api_product['in_stock']) ? $api_product['in_stock'] : '1';

            // Check if product is unavailable
            $is_available = ($in_stock === '1' || $in_stock === 1 || $in_stock === true);
            
            if (!$is_available && $this->stock_rules['zero_on_unavailable']) {
                $supplier_stock = 0;
            }

            // Calculate final stock with buffer
            $final_stock = $this->calculate_stock($supplier_stock);

            // Get current stock for comparison
            $current_stock = $product->get_stock_quantity();
            $current_status = $product->get_stock_status();

            // Determine new stock status
            $new_status = ($final_stock > 0) ? 'instock' : 'outofstock';
            
            // Check if update is needed
            $stock_changed = ($current_stock !== $final_stock);
            $status_changed = ($current_status !== $new_status);

            if (!$stock_changed && !$status_changed) {
                return [
                    'status' => 'skipped',
                    'sku' => $sku,
                    'message' => 'Stock unchanged',
                    'stock' => $final_stock,
                ];
            }

            // Update stock
            $product->set_manage_stock(true);
            $product->set_stock_quantity($final_stock);
            
            // Update stock status if auto out of stock is enabled
            if ($this->stock_rules['auto_out_of_stock']) {
                $product->set_stock_status($new_status);
            }

            // Save product
            $product->save();

            $this->logger->info('Stock updated', [
                'sku' => $sku,
                'product_id' => $product_id,
                'old_stock' => $current_stock,
                'new_stock' => $final_stock,
                'status' => $new_status,
            ]);

            return [
                'status' => 'updated',
                'sku' => $sku,
                'product_id' => $product_id,
                'message' => 'Stock updated successfully',
                'old_stock' => $current_stock,
                'new_stock' => $final_stock,
                'stock_status' => $new_status,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Stock sync error', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'sku' => $sku,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync stock for a single SKU (fetch from API first)
     *
     * @param string $sku Product SKU
     * @return array Sync result
     */
    public function sync_single_sku($sku) {
        // Fetch product from API
        $response = $this->api_client->get_products(['skus' => $sku]);

        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'sku' => $sku,
                'message' => $response->get_error_message(),
            ];
        }

        if (empty($response['result'])) {
            // Product not found in Dropshipzone API
            // Deactivate the WooCommerce product if option is enabled
            if ($this->stock_rules['deactivate_if_not_found']) {
                $deactivate_result = $this->deactivate_product_by_sku($sku);
                if ($deactivate_result['deactivated']) {
                    return [
                        'status' => 'deactivated',
                        'sku' => $sku,
                        'product_id' => $deactivate_result['product_id'],
                        'message' => 'Product not found in Dropshipzone API - set to draft',
                    ];
                }
            }
            
            return [
                'status' => 'not_found',
                'sku' => $sku,
                'message' => 'Product not found in Dropshipzone API',
            ];
        }

        return $this->sync_product_stock($response['result'][0]);
    }

    /**
     * Deactivate a product by SKU (set to draft status)
     *
     * @param string $sku Product SKU
     * @return array Result with deactivated status and product_id
     */
    public function deactivate_product_by_sku($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            return [
                'deactivated' => false,
                'product_id' => null,
                'message' => 'Product not found in WooCommerce',
            ];
        }

        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [
                'deactivated' => false,
                'product_id' => $product_id,
                'message' => 'Failed to load product',
            ];
        }

        // Only deactivate if currently published
        if ($product->get_status() !== 'publish') {
            return [
                'deactivated' => false,
                'product_id' => $product_id,
                'message' => 'Product already inactive',
            ];
        }

        // Set product to draft
        $product->set_status('draft');
        
        // Also set stock to 0 and out of stock
        $product->set_stock_quantity(0);
        $product->set_stock_status('outofstock');
        
        $product->save();

        $this->logger->warning('Product deactivated - not found in Dropshipzone API', [
            'sku' => $sku,
            'product_id' => $product_id,
            'product_name' => $product->get_name(),
        ]);

        return [
            'deactivated' => true,
            'product_id' => $product_id,
            'message' => 'Product set to draft',
        ];
    }

    /**
     * Sync stock for variable product variations
     *
     * @param int   $parent_id    Parent product ID
     * @param array $api_products API products data (keyed by SKU)
     * @return array Sync results
     */
    public function sync_variations($parent_id, $api_products) {
        $results = [
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $product = wc_get_product($parent_id);
        
        if (!$product || !$product->is_type('variable')) {
            return $results;
        }

        $variations = $product->get_children();

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            
            if (!$variation) {
                continue;
            }

            $sku = $variation->get_sku();
            
            if (empty($sku) || !isset($api_products[$sku])) {
                $results['skipped']++;
                continue;
            }

            $result = $this->sync_product_stock($api_products[$sku]);
            
            if ($result['status'] === 'updated') {
                $results['updated']++;
            } elseif ($result['status'] === 'error') {
                $results['errors']++;
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Get stock preview for a SKU
     *
     * @param int $supplier_stock Supplier stock quantity
     * @return array Stock preview
     */
    public function preview_stock($supplier_stock) {
        $original = intval($supplier_stock);
        $final = $this->calculate_stock($original);
        $status = ($final > 0) ? 'instock' : 'outofstock';

        return [
            'supplier_stock' => $original,
            'buffer_applied' => $this->stock_rules['buffer_enabled'],
            'buffer_amount' => $this->stock_rules['buffer_amount'],
            'final_stock' => $final,
            'stock_status' => $status,
            'auto_out_of_stock' => $this->stock_rules['auto_out_of_stock'],
        ];
    }

    /**
     * Bulk update stock status for out of stock products
     *
     * @return int Number of products updated
     */
    public function update_out_of_stock_status() {
        global $wpdb;

        if (!$this->stock_rules['auto_out_of_stock']) {
            return 0;
        }

        // Find products with 0 stock but 'instock' status
        $product_ids = $wpdb->get_col("
            SELECT p.ID 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock'
            INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = '_stock_status'
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND CAST(pm_stock.meta_value AS SIGNED) <= 0
            AND pm_status.meta_value = 'instock'
        ");

        $updated = 0;

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_stock_status('outofstock');
                $product->save();
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->logger->info('Bulk updated out of stock status', ['count' => $updated]);
        }

        return $updated;
    }
}
