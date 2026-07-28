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
            get_option('dszsync_price_rules', []),
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
     * When $context (raw API product data) is supplied, advanced price rules
     * are consulted first — the first matching rule (category / supplier /
     * SKU prefix) overrides the global rules. Without context, or when no
     * rule matches, the global rules apply.
     *
     * @param float $supplier_price Original supplier price
     * @param array $context        Optional raw API product data for rule matching
     * @return float Final calculated price
     */
    public function calculate_price($supplier_price, $context = []) {
        $rules = $this->get_rules_for_context($context);

        return dszsync_calculate_price(
            $supplier_price,
            $rules['markup_type'],
            $rules['markup_value'],
            $rules['gst_enabled'],
            $rules['gst_type'],
            $rules['rounding_enabled'],
            $rules['rounding_type']
        );
    }

    /**
     * Resolve the effective rule set for a product context.
     *
     * @param array $context Raw API product data (category ids/path, vendor_id, sku)
     * @return array Rule set in the global-rules shape
     */
    public function get_rules_for_context($context) {
        if (empty($context) || !is_array($context)) {
            return $this->price_rules;
        }

        $advanced = get_option('dszsync_price_rules_v2', []);
        if (empty($advanced['rules']) || !is_array($advanced['rules'])) {
            return $this->price_rules;
        }

        foreach ($advanced['rules'] as $rule) {
            if ($this->rule_matches($rule, $context)) {
                // Fill any missing keys from the global rules
                return wp_parse_args($rule, $this->price_rules);
            }
        }

        return $this->price_rules;
    }

    /**
     * Check whether an advanced rule matches a product context.
     *
     * @param array $rule    Rule (match_type, match_value + pricing keys)
     * @param array $context Raw API product data
     * @return bool
     */
    private function rule_matches($rule, $context) {
        $type = isset($rule['match_type']) ? $rule['match_type'] : '';
        $value = isset($rule['match_value']) ? trim((string) $rule['match_value']) : '';

        if ($value === '') {
            return false;
        }

        switch ($type) {
            case 'category':
                // Numeric: match any category level id; otherwise match the
                // "A > B > C" path prefix case-insensitively
                if (is_numeric($value)) {
                    foreach (['l1_category_id', 'l2_category_id', 'l3_category_id'] as $key) {
                        if (isset($context[$key]) && intval($context[$key]) === intval($value)) {
                            return true;
                        }
                    }
                    return false;
                }
                $path = isset($context['Category']) ? $context['Category'] : '';
                return $path !== '' && stripos($path, $value) === 0;

            case 'supplier':
                return isset($context['vendor_id']) && (string) $context['vendor_id'] === $value;

            case 'sku_prefix':
                $sku = isset($context['sku']) ? $context['sku'] : '';
                return $sku !== '' && stripos($sku, $value) === 0;
        }

        return false;
    }

}
