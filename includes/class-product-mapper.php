<?php
/**
 * Product Mapper Class
 *
 * Handles mapping between WooCommerce products and Dropshipzone SKUs
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Mapper
 */
class Product_Mapper {

    /**
     * Table name
     */
    private $table_name;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct(Logger $logger) {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dsz_product_mapping';
        $this->logger = $logger;
    }

    /**
     * Create mapping table
     */
    public static function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'dsz_product_mapping';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wc_product_id bigint(20) NOT NULL,
            dsz_sku varchar(100) NOT NULL,
            dsz_product_name varchar(255) DEFAULT '',
            last_synced datetime DEFAULT NULL,
            sync_enabled tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY wc_product_id (wc_product_id),
            UNIQUE KEY dsz_sku (dsz_sku),
            KEY sync_enabled (sync_enabled)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get mapping by WooCommerce product ID
     *
     * @param int $wc_product_id WooCommerce product ID
     * @return array|null Mapping data or null
     */
    public function get_by_wc_product($wc_product_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE wc_product_id = %d",
            $wc_product_id
        ), ARRAY_A);
    }

    /**
     * Get mapping by Dropshipzone SKU
     *
     * @param string $dsz_sku Dropshipzone SKU
     * @return array|null Mapping data or null
     */
    public function get_by_dsz_sku($dsz_sku) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE dsz_sku = %s",
            $dsz_sku
        ), ARRAY_A);
    }

    /**
     * Create or update mapping
     *
     * @param int    $wc_product_id     WooCommerce product ID
     * @param string $dsz_sku           Dropshipzone SKU
     * @param string $dsz_product_name  Dropshipzone product name
     * @return int|false Mapping ID or false
     */
    public function map($wc_product_id, $dsz_sku, $dsz_product_name = '') {
        global $wpdb;

        // Check if mapping already exists for this WC product
        $existing = $this->get_by_wc_product($wc_product_id);
        
        if ($existing) {
            // Update existing mapping
            $result = $wpdb->update(
                $this->table_name,
                [
                    'dsz_sku' => $dsz_sku,
                    'dsz_product_name' => $dsz_product_name,
                    'updated_at' => current_time('mysql'),
                ],
                ['wc_product_id' => $wc_product_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
            
            if ($result !== false) {
                $this->logger->info('Product mapping updated', [
                    'wc_product_id' => $wc_product_id,
                    'dsz_sku' => $dsz_sku,
                ]);
                return $existing['id'];
            }
            return false;
        }

        // Create new mapping
        $result = $wpdb->insert(
            $this->table_name,
            [
                'wc_product_id' => $wc_product_id,
                'dsz_sku' => $dsz_sku,
                'dsz_product_name' => $dsz_product_name,
                'sync_enabled' => 1,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s']
        );

        if ($result) {
            $this->logger->info('Product mapping created', [
                'wc_product_id' => $wc_product_id,
                'dsz_sku' => $dsz_sku,
            ]);
            return $wpdb->insert_id;
        }

        $this->logger->error('Failed to create mapping', [
            'wc_product_id' => $wc_product_id,
            'dsz_sku' => $dsz_sku,
            'error' => $wpdb->last_error,
        ]);
        return false;
    }

    /**
     * Remove mapping
     *
     * @param int $wc_product_id WooCommerce product ID
     * @return bool Success
     */
    public function unmap($wc_product_id) {
        global $wpdb;
        $result = $wpdb->delete(
            $this->table_name,
            ['wc_product_id' => $wc_product_id],
            ['%d']
        );
        
        if ($result) {
            $this->logger->info('Product mapping removed', ['wc_product_id' => $wc_product_id]);
        }
        return $result !== false;
    }

    /**
     * Toggle sync enabled
     *
     * @param int  $wc_product_id WooCommerce product ID
     * @param bool $enabled       Enabled status
     * @return bool Success
     */
    public function set_sync_enabled($wc_product_id, $enabled) {
        global $wpdb;
        return $wpdb->update(
            $this->table_name,
            ['sync_enabled' => $enabled ? 1 : 0],
            ['wc_product_id' => $wc_product_id],
            ['%d'],
            ['%d']
        ) !== false;
    }

    /**
     * Get all mappings with product details
     *
     * @param array $args Query arguments
     * @return array Mappings with product info
     */
    public function get_mappings($args = []) {
        global $wpdb;

        $defaults = [
            'limit' => 50,
            'offset' => 0,
            'search' => '',
            'sync_enabled' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        $where = "1=1";
        $values = [];

        if ($args['sync_enabled'] !== null) {
            $where .= " AND m.sync_enabled = %d";
            $values[] = $args['sync_enabled'] ? 1 : 0;
        }

        if (!empty($args['search'])) {
            $where .= " AND (m.dsz_sku LIKE %s OR m.dsz_product_name LIKE %s OR p.post_title LIKE %s)";
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $orderby = in_array($args['orderby'], ['created_at', 'dsz_sku', 'last_synced']) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT m.*, p.post_title as wc_product_name
                FROM {$this->table_name} m
                LEFT JOIN {$wpdb->posts} p ON m.wc_product_id = p.ID
                WHERE {$where}
                ORDER BY m.{$orderby} {$order}
                LIMIT %d OFFSET %d";

        $values[] = $args['limit'];
        $values[] = $args['offset'];

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Get total count of mappings
     *
     * @param array $args Query arguments
     * @return int Count
     */
    public function get_count($args = []) {
        global $wpdb;

        $where = "1=1";
        $values = [];

        if (isset($args['sync_enabled'])) {
            $where .= " AND sync_enabled = %d";
            $values[] = $args['sync_enabled'] ? 1 : 0;
        }

        if (!empty($args['search'])) {
            $where .= " AND (dsz_sku LIKE %s OR dsz_product_name LIKE %s)";
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search;
            $values[] = $search;
        }

        $sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where}";

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get all mapped DSZ SKUs for sync
     *
     * @param int $limit  Limit
     * @param int $offset Offset
     * @return array Array of ['wc_product_id' => X, 'dsz_sku' => Y]
     */
    public function get_mapped_skus_for_sync($limit = 100, $offset = 0) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT wc_product_id, dsz_sku 
             FROM {$this->table_name} 
             WHERE sync_enabled = 1 
             ORDER BY id ASC 
             LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A);
    }

    /**
     * Get total count of syncable mappings
     *
     * @return int Count
     */
    public function get_syncable_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE sync_enabled = 1"
        );
    }

    /**
     * Auto-map products by matching SKUs
     *
     * @return array Results with 'mapped' and 'skipped' counts
     */
    public function auto_map_by_sku() {
        global $wpdb;
        
        $results = [
            'mapped' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        // Get all WooCommerce products with SKUs that aren't mapped yet
        $wc_products = $wpdb->get_results("
            SELECT p.ID, pm.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$this->table_name} m ON p.ID = m.wc_product_id
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            AND pm.meta_value IS NOT NULL
            AND m.id IS NULL
        ", ARRAY_A);

        foreach ($wc_products as $product) {
            // Create mapping using WC SKU as DSZ SKU (assumes they match)
            $mapping_id = $this->map($product['ID'], $product['sku'], '');
            
            if ($mapping_id) {
                $results['mapped']++;
                $results['details'][] = [
                    'wc_product_id' => $product['ID'],
                    'sku' => $product['sku'],
                    'status' => 'mapped',
                ];
            } else {
                $results['skipped']++;
            }
        }

        $this->logger->info('Auto-mapping completed', $results);
        return $results;
    }

    /**
     * Update last synced timestamp
     *
     * @param int $wc_product_id WooCommerce product ID
     * @return bool Success
     */
    public function update_last_synced($wc_product_id) {
        global $wpdb;
        return $wpdb->update(
            $this->table_name,
            ['last_synced' => current_time('mysql')],
            ['wc_product_id' => $wc_product_id],
            ['%s'],
            ['%d']
        ) !== false;
    }

    /**
     * Search WooCommerce products for mapping
     *
     * @param string $search Search term
     * @param int    $limit  Limit
     * @return array Products
     */
    public function search_wc_products($search, $limit = 20) {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($search) . '%';

        return $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as sku,
                   CASE WHEN m.id IS NOT NULL THEN 1 ELSE 0 END as is_mapped,
                   m.dsz_sku as mapped_to
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$this->table_name} m ON p.ID = m.wc_product_id
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND (p.post_title LIKE %s OR pm.meta_value LIKE %s OR p.ID = %d)
            ORDER BY p.post_title ASC
            LIMIT %d
        ", $like, $like, intval($search), $limit), ARRAY_A);
    }

    /**
     * Get unmapped WooCommerce products count
     *
     * @return int Count
     */
    public function get_unmapped_count() {
        global $wpdb;
        return (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$this->table_name} m ON p.ID = m.wc_product_id
            WHERE p.post_type IN ('product', 'product_variation')
            AND p.post_status = 'publish'
            AND pm.meta_value != ''
            AND pm.meta_value IS NOT NULL
            AND m.id IS NULL
        ");
    }

    /**
     * Clear all mappings
     *
     * @return bool Success
     */
    public function clear_all() {
        global $wpdb;
        $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        if ($result !== false) {
            $this->logger->info('All mappings cleared');
        }
        return $result !== false;
    }
}
