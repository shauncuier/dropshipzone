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
            return new \WP_Error('order_not_found', __('WooCommerce order not found.', '3s-product-sync-for-dropshipzone'));
        }

        // Check if already submitted
        $existing = $this->get_dsz_order($wc_order_id);
        if ($existing && !empty($existing['dsz_serial_number'])) {
            return new \WP_Error(
                'already_submitted',
                /* translators: %s: DSZ serial number */
                sprintf(__('Order already submitted to Dropshipzone (Serial: %s)', '3s-product-sync-for-dropshipzone'), $existing['dsz_serial_number'])
            );
        }

        // Get DSZ order items (only mapped products)
        $dsz_items = $this->get_dsz_order_items($order);
        
        if (empty($dsz_items)) {
            return new \WP_Error('no_dsz_items', __('No Dropshipzone-mapped products in this order.', '3s-product-sync-for-dropshipzone'));
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
                sprintf(__('Dropshipzone submission failed: %s', '3s-product-sync-for-dropshipzone'), $result->get_error_message()),
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
            sprintf(__('Order submitted to Dropshipzone. Serial: %s (Status: Not Submitted - awaiting payment in DSZ)', '3s-product-sync-for-dropshipzone'), $serial_number),
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
            'message' => __('Order submitted successfully', '3s-product-sync-for-dropshipzone'),
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

    /**
     * Get paid WC orders with DSZ products that have not been submitted yet
     *
     * @param int $limit Max orders to return
     * @return int[] WooCommerce order IDs
     */
    public function get_pending_order_ids($limit = 50) {
        global $wpdb;

        $orders = wc_get_orders([
            'status' => ['processing'],
            'limit' => max(1, min(200, intval($limit)) * 3), // Over-fetch: some orders have no DSZ items
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'ids',
        ]);

        if (empty($orders)) {
            return [];
        }

        // Exclude orders already submitted (have a serial)
        $placeholders = implode(',', array_fill(0, count($orders), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $submitted = $wpdb->get_col($wpdb->prepare(
            "SELECT wc_order_id FROM {$this->table_name} WHERE dsz_serial_number != '' AND wc_order_id IN ({$placeholders})",
            $orders
        ));
        $submitted = array_map('intval', (array) $submitted);

        $pending = [];
        foreach ($orders as $order_id) {
            if (in_array(intval($order_id), $submitted, true)) {
                continue;
            }
            if ($this->order_has_dsz_products($order_id)) {
                $pending[] = intval($order_id);
            }
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /**
     * Poll Dropshipzone for tracking/status updates on submitted orders.
     *
     * The /orders response schema is not documented, so field extraction is
     * defensive; the first raw entry is logged at debug level for discovery.
     *
     * @return array { checked: int, updated: int, errors: int }
     */
    public function sync_tracking() {
        global $wpdb;

        $results = ['checked' => 0, 'updated' => 0, 'errors' => 0];

        // Serials submitted in the last 14 days that aren't complete yet
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            "SELECT wc_order_id, dsz_serial_number FROM {$this->table_name}
             WHERE dsz_serial_number != ''
             AND dsz_status NOT IN ('complete', 'cancelled')
             AND submitted_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             ORDER BY submitted_at ASC
             LIMIT 100",
            ARRAY_A
        );

        if (empty($rows)) {
            return $results;
        }

        $serial_to_order = [];
        foreach ($rows as $row) {
            $serial_to_order[$row['dsz_serial_number']] = intval($row['wc_order_id']);
        }

        $response = $this->api_client->get_orders([
            'order_ids' => implode(',', array_keys($serial_to_order)),
            'limit' => 160,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Tracking sync: order lookup failed', ['error' => $response->get_error_message()]);
            $results['errors']++;
            return $results;
        }

        $entries = [];
        if (isset($response['result']) && is_array($response['result'])) {
            $entries = $response['result'];
        } elseif (is_array($response) && isset($response[0])) {
            $entries = $response;
        }

        if (!empty($entries)) {
            // Schema discovery aid — remove once field names are confirmed
            $this->logger->debug('Tracking sync: raw order entry', ['entry' => $entries[0]]);
        }

        $settings = get_option('dsz_order_settings', []);
        $auto_complete = !empty($settings['tracking_autocomplete']);

        foreach ($entries as $entry) {
            $serial = '';
            foreach (['serial_number', 'order_id', 'order_no', 'id'] as $key) {
                if (!empty($entry[$key]) && isset($serial_to_order[(string) $entry[$key]])) {
                    $serial = (string) $entry[$key];
                    break;
                }
            }

            if ($serial === '') {
                continue;
            }

            $wc_order_id = $serial_to_order[$serial];
            $order = wc_get_order($wc_order_id);
            if (!$order) {
                continue;
            }

            $results['checked']++;

            // Defensive tracking field extraction
            $tracking = '';
            foreach (['tracking_number', 'tracking_no', 'tracking', 'consignment_number', 'con_note'] as $key) {
                if (!empty($entry[$key]) && is_string($entry[$key])) {
                    $tracking = $entry[$key];
                    break;
                }
            }
            $courier = '';
            foreach (['courier', 'carrier', 'shipping_company', 'freight_company'] as $key) {
                if (!empty($entry[$key]) && is_string($entry[$key])) {
                    $courier = $entry[$key];
                    break;
                }
            }
            $dsz_status = isset($entry['status']) ? strtolower((string) $entry['status']) : '';

            $changed = false;

            if ($tracking !== '' && $order->get_meta('_dsz_tracking_number') !== $tracking) {
                $order->update_meta_data('_dsz_tracking_number', $tracking);
                if ($courier !== '') {
                    $order->update_meta_data('_dsz_courier', $courier);
                }
                $order->add_order_note(sprintf(
                    /* translators: %1$s: tracking number, %2$s: courier */
                    __('Dropshipzone tracking: %1$s %2$s', '3s-product-sync-for-dropshipzone'),
                    $tracking,
                    $courier !== '' ? '(' . $courier . ')' : ''
                ));
                $changed = true;
            }

            if ($dsz_status !== '' && in_array($dsz_status, ['processing', 'complete', 'cancelled'], true)) {
                $this->save_dsz_order($wc_order_id, $serial, $dsz_status);

                if ($dsz_status === 'complete' && $auto_complete && $order->get_status() === 'processing') {
                    $order->update_status('completed', __('Dropshipzone order complete.', '3s-product-sync-for-dropshipzone'));
                    $changed = true;
                }
            }

            if ($changed) {
                $order->save();
                $results['updated']++;
            }
        }

        $this->logger->info('Tracking sync completed', $results);

        return $results;
    }
}
