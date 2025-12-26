<?php
/**
 * Dropshipzone Shipping Method
 *
 * WooCommerce shipping method that calculates shipping rates based on
 * Dropshipzone zone mapping and per-product zone rates.
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DSZ Shipping Method Class
 */
class Shipping_Method extends \WC_Shipping_Method {

    /**
     * API Client instance
     *
     * @var API_Client
     */
    private $api_client;

    /**
     * Logger instance
     *
     * @var Logger
     */
    private $logger;

    /**
     * Cached zone mapping
     *
     * @var array
     */
    private $zone_cache = [];

    /**
     * Cached zone rates
     *
     * @var array
     */
    private $rates_cache = [];

    /**
     * Constructor
     *
     * @param int $instance_id Shipping method instance ID.
     */
    public function __construct($instance_id = 0) {
        $this->id = 'dsz_shipping';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Dropshipzone Shipping', 'dropshipzone');
        $this->method_description = __('Calculate shipping rates based on Dropshipzone zone mapping and per-product rates.', 'dropshipzone');
        $this->supports = [
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        ];

        $this->init();

        // Get API client and logger from main plugin instance
        $plugin = \Dropshipzone\dsz_sync();
        if ($plugin) {
            $this->api_client = $plugin->api_client;
            $this->logger = $plugin->logger;
        }
    }

    /**
     * Initialize settings
     */
    public function init() {
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', $this->method_title);
        $this->enabled = $this->get_option('enabled', 'yes');
        $this->fallback_cost = $this->get_option('fallback_cost', '');

        // Save settings
        add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
    }

    /**
     * Define settings fields
     */
    public function init_form_fields() {
        $this->instance_form_fields = [
            'title' => [
                'title' => __('Method Title', 'dropshipzone'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'dropshipzone'),
                'default' => __('Dropshipzone Shipping', 'dropshipzone'),
                'desc_tip' => true,
            ],
            'fallback_cost' => [
                'title' => __('Fallback Cost', 'dropshipzone'),
                'type' => 'price',
                'description' => __('Cost to use when zone rates are unavailable. Leave empty to hide shipping if rates cannot be calculated.', 'dropshipzone'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => __('N/A', 'dropshipzone'),
            ],
            'free_shipping_threshold' => [
                'title' => __('Free Shipping Threshold', 'dropshipzone'),
                'type' => 'price',
                'description' => __('Offer free shipping when cart total exceeds this amount. Leave empty to disable.', 'dropshipzone'),
                'default' => '',
                'desc_tip' => true,
                'placeholder' => __('No threshold', 'dropshipzone'),
            ],
            'handling_fee' => [
                'title' => __('Handling Fee', 'dropshipzone'),
                'type' => 'price',
                'description' => __('Additional handling fee to add to the shipping cost.', 'dropshipzone'),
                'default' => '0',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Check if shipping method is available
     *
     * @param array $package Shipping package.
     * @return bool
     */
    public function is_available($package) {
        if ($this->enabled !== 'yes') {
            return false;
        }

        // Check if we have DSZ products in cart
        $has_dsz_products = false;
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $sku = $product->get_sku();
            if (!empty($sku) && $this->is_dsz_product($item['product_id'])) {
                $has_dsz_products = true;
                break;
            }
        }

        return $has_dsz_products;
    }

    /**
     * Check if product is a DSZ product (has mapping)
     *
     * @param int $product_id WooCommerce product ID.
     * @return bool
     */
    private function is_dsz_product($product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dsz_product_mapping';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $mapping = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE wc_product_id = %d",
            $product_id
        ));

        return !empty($mapping);
    }

    /**
     * Calculate shipping rates
     *
     * @param array $package Shipping package.
     */
    public function calculate_shipping($package = []) {
        $destination = $package['destination'];
        $postcode = isset($destination['postcode']) ? sanitize_text_field($destination['postcode']) : '';
        $country = isset($destination['country']) ? sanitize_text_field($destination['country']) : '';

        // Only support Australia for now
        if ($country !== 'AU' || empty($postcode)) {
            $this->add_fallback_rate();
            return;
        }

        // Get zone mapping for postcode
        $zone_info = $this->get_zone_for_postcode($postcode);
        if (is_wp_error($zone_info) || empty($zone_info)) {
            $this->logger->warning('Could not determine zone for postcode', ['postcode' => $postcode]);
            $this->add_fallback_rate();
            return;
        }

        // Collect SKUs from cart
        $skus = [];
        $quantities = [];
        foreach ($package['contents'] as $item) {
            $product = $item['data'];
            $sku = $product->get_sku();
            if (!empty($sku) && $this->is_dsz_product($item['product_id'])) {
                $skus[] = $sku;
                $quantities[$sku] = isset($quantities[$sku]) ? $quantities[$sku] + $item['quantity'] : $item['quantity'];
            }
        }

        if (empty($skus)) {
            $this->add_fallback_rate();
            return;
        }

        // Get zone rates for SKUs
        $zone_rates = $this->get_zone_rates_for_skus($skus);
        if (is_wp_error($zone_rates) || empty($zone_rates)) {
            $this->logger->warning('Could not get zone rates for SKUs', ['skus' => $skus]);
            $this->add_fallback_rate();
            return;
        }

        // Calculate total shipping cost
        $total_cost = 0;
        $undeliverable = false;
        $zone_id = isset($zone_info['zone_id']) ? $zone_info['zone_id'] : '';

        foreach ($skus as $sku) {
            if (!isset($zone_rates[$sku])) {
                continue;
            }

            $rate = $this->get_rate_for_zone($zone_rates[$sku], $zone_id, $zone_info);
            
            if ($rate === 9999 || $rate === '9999') {
                $undeliverable = true;
                break;
            }

            $quantity = isset($quantities[$sku]) ? $quantities[$sku] : 1;
            $total_cost += floatval($rate) * $quantity;
        }

        // If any product is undeliverable, don't show shipping
        if ($undeliverable) {
            $this->logger->info('Zone is undeliverable', ['postcode' => $postcode, 'zone' => $zone_id]);
            return;
        }

        // Check free shipping threshold
        $threshold = $this->get_option('free_shipping_threshold', '');
        if (!empty($threshold) && $package['contents_cost'] >= floatval($threshold)) {
            $total_cost = 0;
        }

        // Add handling fee
        $handling_fee = floatval($this->get_option('handling_fee', 0));
        if ($handling_fee > 0 && $total_cost > 0) {
            $total_cost += $handling_fee;
        }

        // Add the rate
        $this->add_rate([
            'id' => $this->get_rate_id(),
            'label' => $this->title,
            'cost' => $total_cost,
            'calc_tax' => 'per_order',
            'package' => $package,
        ]);

        $this->logger->debug('Calculated DSZ shipping', [
            'postcode' => $postcode,
            'zone' => $zone_id,
            'total_cost' => $total_cost,
            'skus_count' => count($skus),
        ]);
    }

    /**
     * Get zone for postcode from API
     *
     * @param string $postcode Australian postcode.
     * @return array|WP_Error Zone info or error.
     */
    private function get_zone_for_postcode($postcode) {
        // Check cache
        if (isset($this->zone_cache[$postcode])) {
            return $this->zone_cache[$postcode];
        }

        // Check transient cache (1 hour)
        $cache_key = 'dsz_zone_' . $postcode;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            $this->zone_cache[$postcode] = $cached;
            return $cached;
        }

        // Fetch from API
        $response = $this->api_client->get_zone_mapping($postcode);
        
        if (is_wp_error($response)) {
            return $response;
        }

        $zone_info = [];
        if (!empty($response['result']) && is_array($response['result'])) {
            $zone_info = $response['result'][0] ?? [];
        }

        // Cache for 1 hour
        set_transient($cache_key, $zone_info, HOUR_IN_SECONDS);
        $this->zone_cache[$postcode] = $zone_info;

        return $zone_info;
    }

    /**
     * Get zone rates for SKUs from API
     *
     * @param array $skus Product SKUs.
     * @return array|WP_Error Rates indexed by SKU or error.
     */
    private function get_zone_rates_for_skus($skus) {
        // Check cache for all SKUs
        $uncached_skus = [];
        $rates = [];
        
        foreach ($skus as $sku) {
            if (isset($this->rates_cache[$sku])) {
                $rates[$sku] = $this->rates_cache[$sku];
            } else {
                $cache_key = 'dsz_rates_' . md5($sku);
                $cached = get_transient($cache_key);
                if ($cached !== false) {
                    $rates[$sku] = $cached;
                    $this->rates_cache[$sku] = $cached;
                } else {
                    $uncached_skus[] = $sku;
                }
            }
        }

        // Fetch uncached from API
        if (!empty($uncached_skus)) {
            $response = $this->api_client->get_zone_rates($uncached_skus);
            
            if (is_wp_error($response)) {
                return $response;
            }

            if (!empty($response['result']) && is_array($response['result'])) {
                foreach ($response['result'] as $item) {
                    $sku = $item['sku'] ?? '';
                    if (!empty($sku)) {
                        $rates[$sku] = $item;
                        $this->rates_cache[$sku] = $item;
                        // Cache for 1 hour
                        set_transient('dsz_rates_' . md5($sku), $item, HOUR_IN_SECONDS);
                    }
                }
            }
        }

        return $rates;
    }

    /**
     * Get rate for specific zone from rate data
     *
     * @param array  $rate_data Rate data for a SKU.
     * @param string $zone_id   Zone ID.
     * @param array  $zone_info Zone info from mapping.
     * @return float Shipping rate.
     */
    private function get_rate_for_zone($rate_data, $zone_id, $zone_info) {
        // Try to find the rate for this zone
        // The API returns different zone types: standard, defined, advanced
        
        $zone_type = $zone_info['zone_type'] ?? 'standard';
        
        // Look for specific zone rate
        if (isset($rate_data['zones']) && is_array($rate_data['zones'])) {
            foreach ($rate_data['zones'] as $zone) {
                if (isset($zone['zone_id']) && $zone['zone_id'] === $zone_id) {
                    return floatval($zone['rate'] ?? 0);
                }
            }
        }

        // Fallback to default rate if available
        if (isset($rate_data['default_rate'])) {
            return floatval($rate_data['default_rate']);
        }

        // Fallback to shipping_cost field
        if (isset($rate_data['shipping_cost'])) {
            return floatval($rate_data['shipping_cost']);
        }

        return 0;
    }

    /**
     * Add fallback rate if configured
     */
    private function add_fallback_rate() {
        $fallback = $this->get_option('fallback_cost', '');
        
        if ($fallback !== '' && is_numeric($fallback)) {
            $this->add_rate([
                'id' => $this->get_rate_id(),
                'label' => $this->title,
                'cost' => floatval($fallback),
                'calc_tax' => 'per_order',
            ]);
        }
    }
}
