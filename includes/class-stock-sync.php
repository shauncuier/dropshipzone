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
            'republish_on_restock' => true, // Re-publish draft products when they come back in stock
        ];

        $this->stock_rules = wp_parse_args(
            get_option('dszsync_stock_rules', []),
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
        return dszsync_calculate_stock(
            $supplier_stock,
            $this->stock_rules['buffer_enabled'],
            $this->stock_rules['buffer_amount']
        );
    }

}
