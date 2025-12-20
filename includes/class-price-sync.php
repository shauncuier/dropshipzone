<?php
/**
 * Price Sync Class
 *
 * Handles syncing product prices from Dropshipzone API
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Price Sync Engine
 */
class Price_Sync {

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Price rules
     */
    private $price_rules;

    /**
     * Constructor
     *
     * @param API_Client $api_client API client instance
     * @param Logger     $logger     Logger instance
     */
    public function __construct(API_Client $api_client, Logger $logger) {
        $this->api_client = $api_client;
        $this->logger = $logger;
        $this->load_price_rules();
    }

    /**
     * Load price rules from options
     */
    private function load_price_rules() {
        $defaults = [
            'markup_type' => 'percentage',
            'markup_value' => 30,
            'rounding_enabled' => true,
            'rounding_type' => '99',
            'gst_enabled' => true,
            'gst_type' => 'include',
        ];

        $this->price_rules = wp_parse_args(
            get_option('dsz_sync_price_rules', []),
            $defaults
        );
    }

    /**
     * Reload price rules
     */
    public function reload_rules() {
        $this->load_price_rules();
    }

    /**
     * Get current price rules
     *
     * @return array Price rules
     */
    public function get_rules() {
        return $this->price_rules;
    }

    /**
     * Calculate final price based on rules
     *
     * @param float $supplier_price Original supplier price
     * @return float Final calculated price
     */
    public function calculate_price($supplier_price) {
        return dsz_calculate_price(
            $supplier_price,
            $this->price_rules['markup_type'],
            $this->price_rules['markup_value'],
            $this->price_rules['gst_enabled'],
            $this->price_rules['gst_type'],
            $this->price_rules['rounding_enabled'],
            $this->price_rules['rounding_type']
        );
    }

    /**
     * Sync prices for a batch of products from API data
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

            $result = $this->sync_product_price($api_product);
            
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
     * Sync price for a single product
     *
     * @param array $api_product Product data from API
     * @return array Sync result
     */
    public function sync_product_price($api_product) {
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
            // Get supplier prices
            $supplier_price = isset($api_product['price']) ? floatval($api_product['price']) : 0;
            $special_price = isset($api_product['special_price']) ? floatval($api_product['special_price']) : null;
            $rrp_price = isset($api_product['RrpPrice']) ? floatval($api_product['RrpPrice']) : null;

            if ($supplier_price <= 0) {
                return [
                    'status' => 'skipped',
                    'sku' => $sku,
                    'message' => 'Invalid supplier price',
                ];
            }

            // Calculate final prices
            $calculated_regular = $this->calculate_price($supplier_price);
            $calculated_sale = null;

            // If there's a special/sale price from supplier
            if ($special_price && $special_price > 0 && $special_price < $supplier_price) {
                $calculated_sale = $this->calculate_price($special_price);
            }

            // Get current prices for comparison
            $current_regular = floatval($product->get_regular_price());
            $current_sale = $product->get_sale_price() ? floatval($product->get_sale_price()) : null;

            // Check if update is needed
            $needs_update = false;
            
            if (abs($current_regular - $calculated_regular) > 0.01) {
                $needs_update = true;
            }
            
            if ($calculated_sale !== null && ($current_sale === null || abs($current_sale - $calculated_sale) > 0.01)) {
                $needs_update = true;
            }

            if (!$needs_update) {
                return [
                    'status' => 'skipped',
                    'sku' => $sku,
                    'message' => 'Price unchanged',
                    'price' => $calculated_regular,
                ];
            }

            // Update prices
            $product->set_regular_price($calculated_regular);
            
            if ($calculated_sale !== null) {
                $product->set_sale_price($calculated_sale);
                
                // Handle special price dates if available
                if (!empty($api_product['special_price_from_date'])) {
                    $product->set_date_on_sale_from(strtotime($api_product['special_price_from_date']));
                }
                if (!empty($api_product['special_price_end_date'])) {
                    $product->set_date_on_sale_to(strtotime($api_product['special_price_end_date']));
                }
            } else {
                // Clear sale price if no special price
                $product->set_sale_price('');
                $product->set_date_on_sale_from('');
                $product->set_date_on_sale_to('');
            }

            // Save product
            $product->save();

            $this->logger->info('Price updated', [
                'sku' => $sku,
                'product_id' => $product_id,
                'old_price' => $current_regular,
                'new_price' => $calculated_regular,
                'sale_price' => $calculated_sale,
            ]);

            return [
                'status' => 'updated',
                'sku' => $sku,
                'product_id' => $product_id,
                'message' => 'Price updated successfully',
                'old_price' => $current_regular,
                'new_price' => $calculated_regular,
                'sale_price' => $calculated_sale,
            ];

        } catch (\Exception $e) {
            $this->logger->error('Price sync error', [
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
     * Sync price for a single SKU (fetch from API first)
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
            return [
                'status' => 'not_found',
                'sku' => $sku,
                'message' => 'Product not found in Dropshipzone',
            ];
        }

        return $this->sync_product_price($response['result'][0]);
    }

    /**
     * Preview price calculation
     *
     * @param float $supplier_price Supplier price
     * @return array Price breakdown
     */
    public function preview_price($supplier_price) {
        $base = floatval($supplier_price);
        
        // Calculate with markup
        $after_markup = $base;
        if ($this->price_rules['markup_type'] === 'percentage') {
            $after_markup = $base * (1 + ($this->price_rules['markup_value'] / 100));
        } else {
            $after_markup = $base + $this->price_rules['markup_value'];
        }

        // Calculate with GST
        $after_gst = $after_markup;
        if ($this->price_rules['gst_enabled'] && $this->price_rules['gst_type'] === 'exclude') {
            $after_gst = $after_markup * 1.10;
        }

        // Calculate final with rounding
        $final = $after_gst;
        if ($this->price_rules['rounding_enabled']) {
            $final = dsz_round_price($after_gst, $this->price_rules['rounding_type']);
        }

        return [
            'supplier_price' => $base,
            'after_markup' => round($after_markup, 2),
            'markup_type' => $this->price_rules['markup_type'],
            'markup_value' => $this->price_rules['markup_value'],
            'after_gst' => round($after_gst, 2),
            'gst_applied' => $this->price_rules['gst_enabled'] && $this->price_rules['gst_type'] === 'exclude',
            'final_price' => round($final, 2),
            'rounding_applied' => $this->price_rules['rounding_enabled'],
            'rounding_type' => $this->price_rules['rounding_type'],
        ];
    }
}
