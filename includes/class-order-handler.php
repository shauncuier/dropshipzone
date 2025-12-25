<?php
/**
 * Order Handler Class
 *
 * Handles order submission to Dropshipzone API
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Order Handler for Dropshipzone
 */
class Order_Handler {

    /**
     * Table name for order tracking
     */
    private $table_name;

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Product Mapper instance
     */
    private $product_mapper;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     *
     * @param API_Client     $api_client     API client instance
     * @param Product_Mapper $product_mapper Product mapper instance
     * @param Logger         $logger         Logger instance
     */
    public function __construct($api_client, $product_mapper, $logger) {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dsz_orders';
        $this->api_client = $api_client;
        $this->product_mapper = $product_mapper;
        $this->logger = $logger;
    }

    /**
     * Create orders tracking table
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dsz_orders';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wc_order_id bigint(20) NOT NULL,
            dsz_serial_number varchar(50) DEFAULT '',
            dsz_status varchar(50) DEFAULT 'not_submitted',
            submitted_at datetime DEFAULT NULL,
            last_checked datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY wc_order_id (wc_order_id),
            KEY dsz_serial_number (dsz_serial_number)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Submit a WooCommerce order to Dropshipzone
     *
     * @param int $wc_order_id WooCommerce order ID
     * @return array|WP_Error Result with DSZ serial number or error
     */
    public function submit_order($wc_order_id) {
        $order = wc_get_order($wc_order_id);
        
        if (!$order) {
            return new \WP_Error('order_not_found', __('WooCommerce order not found.', 'dropshipzone'));
        }

        // Check if already submitted
        $existing = $this->get_dsz_order($wc_order_id);
        if ($existing && !empty($existing['dsz_serial_number'])) {
            return new \WP_Error(
                'already_submitted',
                /* translators: %s: DSZ serial number */
                sprintf(__('Order already submitted to Dropshipzone (Serial: %s)', 'dropshipzone'), $existing['dsz_serial_number'])
            );
        }

        // Get DSZ order items (only mapped products)
        $dsz_items = $this->get_dsz_order_items($order);
        
        if (empty($dsz_items)) {
            return new \WP_Error('no_dsz_items', __('No Dropshipzone-mapped products in this order.', 'dropshipzone'));
        }

        // Map order data for API
        $order_data = $this->map_order_data($order, $dsz_items);

        $this->logger->info('Submitting order to Dropshipzone', [
            'wc_order_id' => $wc_order_id,
            'items' => count($dsz_items),
        ]);

        // Submit to API
        $result = $this->api_client->place_order($order_data);

        if (is_wp_error($result)) {
            // Save error
            $this->save_dsz_order($wc_order_id, '', 'error', $result->get_error_message());
            
            // Add order note
            $order->add_order_note(
                /* translators: %s: error message */
                sprintf(__('Dropshipzone submission failed: %s', 'dropshipzone'), $result->get_error_message()),
                false
            );
            
            return $result;
        }

        $serial_number = $result['serial_number'] ?? '';
        
        // Save success
        $this->save_dsz_order($wc_order_id, $serial_number, 'not_submitted');
        
        // Add order note
        $order->add_order_note(
            /* translators: %s: DSZ serial number */
            sprintf(__('Order submitted to Dropshipzone. Serial: %s (Status: Not Submitted - awaiting payment in DSZ)', 'dropshipzone'), $serial_number),
            false
        );

        // Save DSZ serial to order meta
        $order->update_meta_data('_dsz_serial_number', $serial_number);
        $order->update_meta_data('_dsz_submitted_at', current_time('mysql'));
        $order->save();

        $this->logger->info('Order submitted successfully', [
            'wc_order_id' => $wc_order_id,
            'dsz_serial' => $serial_number,
        ]);

        return [
            'success' => true,
            'serial_number' => $serial_number,
            'message' => __('Order submitted successfully', 'dropshipzone'),
        ];
    }

    /**
     * Get Dropshipzone order items from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order
     * @return array Array of DSZ order items with sku and qty
     */
    public function get_dsz_order_items($order) {
        $dsz_items = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $product_id = $product->get_id();
            
            // Check if product is mapped to DSZ
            $dsz_sku = $this->product_mapper->get_dsz_sku($product_id);
            
            if ($dsz_sku) {
                $dsz_items[] = [
                    'sku' => $dsz_sku,
                    'qty' => $item->get_quantity(),
                ];
            }
        }

        return $dsz_items;
    }

    /**
     * Map WooCommerce order data to Dropshipzone API format
     *
     * @param WC_Order $order     WooCommerce order
     * @param array    $dsz_items DSZ order items
     * @return array API-formatted order data
     */
    public function map_order_data($order, $dsz_items) {
        // Use shipping address if available, otherwise billing
        $use_shipping = $order->has_shipping_address();

        return [
            'your_order_no' => (string) $order->get_id(),
            'first_name' => $use_shipping ? $order->get_shipping_first_name() : $order->get_billing_first_name(),
            'last_name' => $use_shipping ? $order->get_shipping_last_name() : $order->get_billing_last_name(),
            'address1' => $use_shipping ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
            'address2' => $use_shipping ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
            'suburb' => $use_shipping ? $order->get_shipping_city() : $order->get_billing_city(),
            'state' => $this->map_state($use_shipping ? $order->get_shipping_state() : $order->get_billing_state()),
            'postcode' => $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
            'telephone' => $order->get_billing_phone(),
            'comment' => $order->get_customer_note(),
            'order_items' => $dsz_items,
        ];
    }

    /**
     * Map Australian state codes to full names
     *
     * @param string $state State code (e.g., NSW)
     * @return string Full state name
     */
    private function map_state($state) {
        $states = [
            'ACT' => 'Australian Capital Territory',
            'NSW' => 'New South Wales',
            'NT' => 'Northern Territory',
            'QLD' => 'Queensland',
            'SA' => 'South Australia',
            'TAS' => 'Tasmania',
            'VIC' => 'Victoria',
            'WA' => 'Western Australia',
        ];

        return $states[$state] ?? $state;
    }

    /**
     * Save DSZ order tracking data
     *
     * @param int    $wc_order_id   WooCommerce order ID
     * @param string $serial_number DSZ serial number
     * @param string $status        DSZ status
     * @param string $error_message Error message if any
     * @return bool Success
     */
    public function save_dsz_order($wc_order_id, $serial_number, $status = 'not_submitted', $error_message = '') {
        global $wpdb;

        $existing = $this->get_dsz_order($wc_order_id);

        if ($existing) {
            return $wpdb->update(
                $this->table_name,
                [
                    'dsz_serial_number' => $serial_number,
                    'dsz_status' => $status,
                    'submitted_at' => current_time('mysql'),
                    'error_message' => $error_message,
                ],
                ['wc_order_id' => $wc_order_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            ) !== false;
        }

        return $wpdb->insert(
            $this->table_name,
            [
                'wc_order_id' => $wc_order_id,
                'dsz_serial_number' => $serial_number,
                'dsz_status' => $status,
                'submitted_at' => current_time('mysql'),
                'error_message' => $error_message,
            ],
            ['%d', '%s', '%s', '%s', '%s']
        ) !== false;
    }

    /**
     * Get DSZ order by WC order ID
     *
     * @param int $wc_order_id WooCommerce order ID
     * @return array|null Order data or null
     */
    public function get_dsz_order($wc_order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $this->table_name . " WHERE wc_order_id = %d",
            $wc_order_id
        ), ARRAY_A);
    }

    /**
     * Check if order has DSZ products
     *
     * @param int $wc_order_id WooCommerce order ID
     * @return bool True if order has DSZ-mapped products
     */
    public function order_has_dsz_products($wc_order_id) {
        $order = wc_get_order($wc_order_id);
        if (!$order) {
            return false;
        }
        
        $dsz_items = $this->get_dsz_order_items($order);
        return !empty($dsz_items);
    }

    /**
     * Get all DSZ orders
     *
     * @param array $args Query args (limit, offset, status)
     * @return array Orders
     */
    public function get_all_dsz_orders($args = []) {
        global $wpdb;

        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'status' => '',
        ];
        $args = wp_parse_args($args, $defaults);

        $where = "1=1";
        $values = [];

        if (!empty($args['status'])) {
            $where .= " AND dsz_status = %s";
            $values[] = $args['status'];
        }

        $sql = "SELECT * FROM " . $this->table_name . " WHERE " . $where . " ORDER BY submitted_at DESC LIMIT %d OFFSET %d";
        $values[] = intval($args['limit']);
        $values[] = intval($args['offset']);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
    }
}
