<?php
/**
 * Product Importer Class
 *
 * Handles importing products from Dropshipzone API to WooCommerce
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Product Importer
 */
class Product_Importer {

    /**
     * API Client instance
     */
    private $api_client;

    /**
     * Price Sync instance
     */
    private $price_sync;

    /**
     * Stock Sync instance
     */
    private $stock_sync;

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
     */
    public function __construct(API_Client $api_client, Price_Sync $price_sync, Stock_Sync $stock_sync, Product_Mapper $product_mapper, Logger $logger) {
        $this->api_client = $api_client;
        $this->price_sync = $price_sync;
        $this->stock_sync = $stock_sync;
        $this->product_mapper = $product_mapper;
        $this->logger = $logger;
    }

    /**
     * Import a single product by SKU or API data
     *
     * @param array|string $data API product data or SKU string
     * @return int|WP_Error Product ID or error
     */
    public function import_product($data) {
        // If only SKU is provided, fetch data from API
        if (is_string($data)) {
            $sku = $data;
            $response = $this->api_client->get_products_by_skus([$sku]);
            
            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['result'])) {
                return new \WP_Error('product_not_found', sprintf(__('Product with SKU %s not found in Dropshipzone API.', 'dropshipzone-sync'), $sku));
            }

            $data = $response['result'][0];
        }

        // Validate required data
        if (empty($data['sku'])) {
            return new \WP_Error('missing_sku', __('Product data is missing SKU.', 'dropshipzone-sync'));
        }

        $sku = $data['sku'];

        // Check if product already exists by SKU
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            return new \WP_Error('product_exists', sprintf(__('Product with SKU %s already exists in WooCommerce (ID: %d).', 'dropshipzone-sync'), $sku, $existing_id));
        }

        $this->logger->info('Starting product import', ['sku' => $sku]);

        // Create the product
        $product = new \WC_Product_Simple();
        $product->set_name(isset($data['title']) ? $data['title'] : $sku);
        $product->set_status('publish'); // Default to publish or draft? 
        $product->set_description(isset($data['description']) ? $data['description'] : '');
        $product->set_sku($sku);
        
        // Use Price Sync logic to set price
        $cost_price = isset($data['price']) ? floatval($data['price']) : 0;
        $final_price = $this->price_sync->calculate_price($cost_price);
        $product->set_regular_price($final_price);

        // Use Stock Sync logic to set stock
        $api_stock = isset($data['stock']) ? intval($data['stock']) : 0;
        $final_stock = $this->stock_sync->calculate_stock($api_stock);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($final_stock);

        // Set dimensions and weight if available
        if (isset($data['weight'])) {
            $product->set_weight($data['weight']);
        }
        if (isset($data['length'])) {
            $product->set_length($data['length']);
        }
        if (isset($data['width'])) {
            $product->set_width($data['width']);
        }
        if (isset($data['height'])) {
            $product->set_height($data['height']);
        }

        // Save the product to get an ID
        $product_id = $product->save();

        if (!$product_id) {
            $this->logger->error('Failed to create WooCommerce product', ['sku' => $sku]);
            return new \WP_Error('save_failed', __('Failed to save WooCommerce product.', 'dropshipzone-sync'));
        }

        // Handle Image
        if (!empty($data['image_url'])) {
            $this->attach_image_from_url($data['image_url'], $product_id);
        }

        // Create mapping
        $this->product_mapper->map($product_id, $sku, isset($data['title']) ? $data['title'] : '');

        $this->logger->info('Product imported successfully', [
            'sku' => $sku,
            'wc_product_id' => $product_id,
            'price' => $final_price,
            'stock' => $final_stock
        ]);

        return $product_id;
    }

    /**
     * Attach image from URL to a product
     *
     * @param string $url        Image URL
     * @param int    $product_id Product ID
     * @return int|bool Attachment ID or false
     */
    private function attach_image_from_url($url, $product_id) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Download file to temp location
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            $this->logger->warning('Failed to download product image', [
                'url' => $url,
                'error' => $tmp->get_error_message()
            ]);
            return false;
        }

        $file_array = [
            'name'     => basename($url),
            'tmp_name' => $tmp,
        ];

        // Sideload file into media library
        $id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            $this->logger->warning('Failed to sideload product image', [
                'url' => $url,
                'error' => $id->get_error_message()
            ]);
            return false;
        }

        // Set as featured image
        set_post_thumbnail($product_id, $id);

        return $id;
    }
}
