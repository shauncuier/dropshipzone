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
            'markup_percent' => [
                'title' => __('Shipping Markup (%)', 'dropshipzone'),
                'type' => 'number',
                'description' => __('Percentage added on top of the Dropshipzone shipping cost. Dropshipzone bills you their rate when the order is placed — markup is your margin. 0 passes their cost straight through.', 'dropshipzone'),
                'default' => '0',
                'desc_tip' => true,
                'custom_attributes' => ['min' => '0', 'step' => '0.1'],
            ],
            'free_shipping_threshold' => [
                'title' => __('Free Shipping Threshold', 'dropshipzone'),
                'type' => 'price',
                'description' => __('Offer free shipping when cart total exceeds this amount. Warning: Dropshipzone still charges you their shipping rate on the order — this comes out of your margin. Leave empty to disable.', 'dropshipzone'),
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
            'zero_rate_unavailable' => [
                'title' => __('Treat $0 rates as unavailable', 'dropshipzone'),
                'type' => 'checkbox',
                'label' => __('Use the fallback cost when the API returns a $0 rate for a zone', 'dropshipzone'),
                'description' => __('Dropshipzone rate data often shows $0 for zones a supplier has not priced. Enable this if $0 rates are producing unwanted free shipping.', 'dropshipzone'),
                'default' => 'no',
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Per-request cache of package → DSZ SKU sets
     *
     * @var array
     */
    private $package_sku_cache = [];

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

        $dsz = $this->get_dsz_skus_for_package($package);

        return !empty($dsz['skus']);
    }

    /**
     * Resolve the DSZ SKUs for a package with one batched mapping query.
     *
     * Variations are matched on their own ID first, falling back to the
     * parent product ID. The mapping table's dsz_sku is used (source of
     * truth) rather than the WC SKU, which can drift.
     *
     * @param array $package Shipping package.
     * @return array { skus: string[], quantities: array<string,int> }
     */
    private function get_dsz_skus_for_package($package) {
        $empty = ['skus' => [], 'quantities' => []];

        if (empty($package['contents']) || !is_array($package['contents'])) {
            return $empty;
        }

        // Candidate IDs per cart line: variation first, then parent
        $lines = [];
        $all_ids = [];
        foreach ($package['contents'] as $item) {
            $ids = [];
            if (!empty($item['variation_id'])) {
                $ids[] = intval($item['variation_id']);
            }
            if (!empty($item['product_id'])) {
                $ids[] = intval($item['product_id']);
            }
            if (empty($ids)) {
                continue;
            }
            $lines[] = [
                'ids' => $ids,
                'quantity' => isset($item['quantity']) ? intval($item['quantity']) : 1,
            ];
            $all_ids = array_merge($all_ids, $ids);
        }

        if (empty($all_ids)) {
            return $empty;
        }

        $all_ids = array_values(array_unique($all_ids));
        $cache_key = md5(wp_json_encode([$all_ids, wp_list_pluck($lines, 'quantity')]));

        if (isset($this->package_sku_cache[$cache_key])) {
            return $this->package_sku_cache[$cache_key];
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'dsz_product_mapping';
        $placeholders = implode(',', array_fill(0, count($all_ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT wc_product_id, dsz_sku FROM {$table_name} WHERE wc_product_id IN ({$placeholders})", $all_ids),
            ARRAY_A
        );

        $id_to_sku = [];
        foreach ((array) $rows as $row) {
            if (!empty($row['dsz_sku'])) {
                $id_to_sku[intval($row['wc_product_id'])] = $row['dsz_sku'];
            }
        }

        $skus = [];
        $quantities = [];
        foreach ($lines as $line) {
            foreach ($line['ids'] as $id) {
                if (isset($id_to_sku[$id])) {
                    $sku = $id_to_sku[$id];
                    $quantities[$sku] = (isset($quantities[$sku]) ? $quantities[$sku] : 0) + $line['quantity'];
                    if (!in_array($sku, $skus, true)) {
                        $skus[] = $sku;
                    }
                    break; // First (most specific) match wins
                }
            }
        }

        $result = ['skus' => $skus, 'quantities' => $quantities];
        $this->package_sku_cache[$cache_key] = $result;

        return $result;
    }

    /**
     * Calculate shipping rates
     *
     * @param array $package Shipping package.
     */
    public function calculate_shipping($package = []) {
        $destination = isset($package['destination']) ? $package['destination'] : [];
        $postcode = isset($destination['postcode']) ? trim(sanitize_text_field($destination['postcode'])) : '';
        $country = isset($destination['country']) ? sanitize_text_field($destination['country']) : '';

        $dsz = $this->get_dsz_skus_for_package($package);
        $skus = $dsz['skus'];
        $quantities = $dsz['quantities'];

        if (empty($skus)) {
            return;
        }

        if ($country === 'AU') {
            // AU postcodes are 4 digits — don't waste an API call on garbage
            if (!preg_match('/^\d{4}$/', $postcode)) {
                $this->add_fallback_rate();
                return;
            }

            $zone_info = $this->get_zone_for_postcode($postcode);
            if (is_wp_error($zone_info) || empty($zone_info)) {
                $this->logger->warning('Could not determine zone for postcode', ['postcode' => $postcode]);
                $this->add_fallback_rate();
                return;
            }
        } elseif ($country === 'NZ') {
            // NZ has a flat per-SKU rate under the standard scheme ("nz" key);
            // no postcode mapping applies
            $zone_info = ['standard' => 'nz'];
        } else {
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
        $missing_rate_skus = [];
        $zone_id = isset($zone_info['standard']) ? $zone_info['standard'] : '';
        $zero_unavailable = ($this->get_option('zero_rate_unavailable', 'no') === 'yes');

        foreach ($skus as $sku) {
            if (!isset($zone_rates[$sku])) {
                $missing_rate_skus[] = $sku;
                continue;
            }

            $rate = $this->get_rate_for_zone($zone_rates[$sku], $zone_id, $zone_info);

            if ($rate === null) {
                $missing_rate_skus[] = $sku;
                continue;
            }

            // Optional: a $0 zone rate can mean "supplier never priced this
            // zone" rather than free shipping — treat as unavailable if the
            // merchant enabled that interpretation
            if ($zero_unavailable && floatval($rate) == 0) {
                $missing_rate_skus[] = $sku;
                continue;
            }

            // "9999" is the API sentinel for zones the product cannot ship to
            if (intval($rate) === 9999) {
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

        // Incomplete quote: never silently ship items for free. Use the
        // configured fallback, or hide the method when none is set.
        if (!empty($missing_rate_skus)) {
            $this->logger->warning('No zone rate for some cart SKUs - using fallback behavior', [
                'postcode' => $postcode,
                'zone' => $zone_id,
                'missing_skus' => $missing_rate_skus,
            ]);
            $this->add_fallback_rate();
            return;
        }

        // Apply shipping markup (merchant margin on top of DSZ's cost)
        $markup = floatval($this->get_option('markup_percent', 0));
        if ($markup > 0 && $total_cost > 0) {
            $total_cost = round($total_cost * (1 + $markup / 100), 2);
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

        // Negative cache: while the API is failing, don't re-hit it on every
        // shipping recalculation (rate limiter waits would slow checkout)
        if (get_transient('dsz_zone_err_' . $postcode)) {
            return new \WP_Error('dsz_zone_unavailable', __('Zone lookup temporarily unavailable.', 'dropshipzone'));
        }

        // Fetch from API
        $response = $this->api_client->get_zone_mapping($postcode);

        if (is_wp_error($response)) {
            set_transient('dsz_zone_err_' . $postcode, 1, 5 * MINUTE_IN_SECONDS);
            return $response;
        }

        // Match the entry for the requested postcode explicitly rather than
        // trusting result order
        $zone_info = [];
        if (!empty($response['result']) && is_array($response['result'])) {
            foreach ($response['result'] as $entry) {
                if (isset($entry['postcode']) && (string) $entry['postcode'] === (string) $postcode) {
                    $zone_info = $entry;
                    break;
                }
            }
            if (empty($zone_info)) {
                $zone_info = $response['result'][0] ?? [];
            }
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
            // Negative cache mirrors get_zone_for_postcode(): back off for
            // 5 minutes after a failure instead of hammering the API
            $err_key = 'dsz_rates_err_' . md5(implode(',', $uncached_skus));
            if (get_transient($err_key)) {
                return new \WP_Error('dsz_rates_unavailable', __('Rate lookup temporarily unavailable.', 'dropshipzone'));
            }

            $response = $this->api_client->get_zone_rates($uncached_skus);

            if (is_wp_error($response)) {
                set_transient($err_key, 1, 5 * MINUTE_IN_SECONDS);
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

            // Cache a "no rates" marker for requested SKUs the API didn't
            // return, so they aren't refetched on every recalculation. The
            // marker has no scheme keys, so rate resolution yields null and
            // the fallback path applies.
            foreach ($uncached_skus as $sku) {
                if (!isset($rates[$sku])) {
                    $marker = ['sku' => $sku];
                    $rates[$sku] = $marker;
                    $this->rates_cache[$sku] = $marker;
                    set_transient('dsz_rates_' . md5($sku), $marker, HOUR_IN_SECONDS);
                }
            }
        }

        return $rates;
    }

    /**
     * Get rate for specific zone from rate data
     *
     * @param array  $rate_data Rate data for a SKU.
     * @param string $zone_id   Zone ID (for logging context).
     * @param array  $zone_info Zone info from mapping (slug per scheme).
     * @return string|float|null Raw rate value, or null when no rate resolves.
     */
    private function get_rate_for_zone($rate_data, $zone_id, $zone_info) {
        // V2 API schema: rate data carries per-scheme objects (standard/defined/
        // advanced) keyed by lowercase zone slug, each with an "active" flag.
        // The V2 zone mapping gives the matching slug per scheme for a postcode.
        // Prefer the most specific active scheme that has a rate for this zone.
        foreach (['advanced', 'defined', 'standard'] as $scheme) {
            if (empty($rate_data[$scheme]) || !is_array($rate_data[$scheme])) {
                continue;
            }

            $scheme_rates = $rate_data[$scheme];
            if (isset($scheme_rates['active']) && !$scheme_rates['active']) {
                continue;
            }

            $zone_key = isset($zone_info[$scheme]) ? strtolower($zone_info[$scheme]) : '';
            if ($zone_key !== '' && isset($scheme_rates[$zone_key])) {
                // Raw value ("9999" means undeliverable) — caller interprets it
                return $scheme_rates[$zone_key];
            }
        }

        // No scheme resolved a rate — caller must NOT treat this as free
        return null;
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
