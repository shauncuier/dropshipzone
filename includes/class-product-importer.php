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
     * @return int|\WP_Error Product ID or error
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
                /* translators: %s: product SKU */
                return new \WP_Error('product_not_found', sprintf(__('Product with SKU %s not found in Dropshipzone API.', 'dropshipzone'), $sku));
            }

            $data = $response['result'][0];
        }

        // Validate required data
        if (empty($data['sku'])) {
            return new \WP_Error('missing_sku', __('Product data is missing SKU.', 'dropshipzone'));
        }

        $sku = trim($data['sku']);

        // Check if product already exists by SKU
        $existing_id = wc_get_product_id_by_sku($sku);
        if ($existing_id) {
            /* translators: %1$s: product SKU, %2$d: WooCommerce product ID */
            return new \WP_Error('product_exists', sprintf(__('Product with SKU %1$s already exists in WooCommerce (ID: %2$d).', 'dropshipzone'), $sku, $existing_id));
        }

        $this->logger->info('Starting product import', [
            'sku' => $sku, 
            'data_keys' => array_keys($data),
            'has_desc' => !empty($data['desc']),
            'desc_length' => !empty($data['desc']) ? strlen($data['desc']) : 0,
        ]);

        // Create the product
        $product = new \WC_Product_Simple();
        
        // Set product name - check multiple possible field names
        $product_name = $sku;
        if (!empty($data['title'])) {
            $product_name = $data['title'];
        } elseif (!empty($data['name'])) {
            $product_name = $data['name'];
        } elseif (!empty($data['product_name'])) {
            $product_name = $data['product_name'];
        }
        $product->set_name($product_name);
        
        // Get default status from settings
        $import_settings = get_option('dsz_sync_import_settings', ['default_status' => 'publish']);
        $product_status = isset($import_settings['default_status']) ? $import_settings['default_status'] : 'publish';
        $product->set_status($product_status); 

        // Set description - check multiple possible field names
        // Note: Dropshipzone API returns description in 'desc' field
        $description = '';
        if (!empty($data['desc'])) {
            $description = $data['desc'];
        } elseif (!empty($data['description'])) {
            $description = $data['description'];
        } elseif (!empty($data['long_description'])) {
            $description = $data['long_description'];
        }
        $product->set_description($description);
        
        // Set short description if available
        if (!empty($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }
        
        $product->set_sku($sku);
        
        // Use Price Sync logic to set price
        $cost_price = isset($data['price']) ? floatval($data['price']) : 0;
        $final_price = $this->price_sync->calculate_price($cost_price);
        $product->set_regular_price($final_price);

        // Use Stock Sync logic to set stock - check multiple possible field names
        $api_stock = 0;
        if (isset($data['stock_qty'])) {
            $api_stock = intval($data['stock_qty']);
        } elseif (isset($data['stock'])) {
            $api_stock = intval($data['stock']);
        } elseif (isset($data['qty'])) {
            $api_stock = intval($data['qty']);
        } elseif (isset($data['quantity'])) {
            $api_stock = intval($data['quantity']);
        }
        $final_stock = $this->stock_sync->calculate_stock($api_stock);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($final_stock);
        
        // Set stock status based on quantity
        $product->set_stock_status($final_stock > 0 ? 'instock' : 'outofstock');

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

        // Set Categories - check multiple possible field names
        // Note: Dropshipzone API returns category in 'Category' field (with path like "Appliances > Air Conditioners > Evaporative Coolers")
        // Also has l1_category_name, l2_category_name, l3_category_name for hierarchical data
        $category_string = '';
        if (!empty($data['Category'])) {
            // API returns full path like "Appliances > Air Conditioners > Evaporative Coolers"
            $category_string = $data['Category'];
        } elseif (!empty($data['l1_category_name'])) {
            // Build category path from hierarchical fields
            $cat_parts = [];
            if (!empty($data['l1_category_name'])) $cat_parts[] = $data['l1_category_name'];
            if (!empty($data['l2_category_name'])) $cat_parts[] = $data['l2_category_name'];
            if (!empty($data['l3_category_name'])) $cat_parts[] = $data['l3_category_name'];
            $category_string = implode(' > ', $cat_parts);
        } elseif (!empty($data['categories'])) {
            $category_string = $data['categories'];
        } elseif (!empty($data['category'])) {
            $category_string = $data['category'];
        }

        if (!empty($category_string)) {
            $category_ids = $this->create_categories($category_string);
            if (!empty($category_ids)) {
                $product->set_category_ids($category_ids);
            }
            $this->logger->info('Category assignment', [
                'sku' => $sku,
                'category_string' => $category_string,
                'category_ids' => $category_ids
            ]);
        }

        // Save the product to get an ID
        $product_id = $product->save();

        if (!$product_id) {
            $this->logger->error('Failed to create WooCommerce product', ['sku' => $sku]);
            return new \WP_Error('save_failed', __('Failed to save WooCommerce product.', 'dropshipzone'));
        }

        // Handle Image - check multiple possible field names
        $main_image = '';
        if (!empty($data['image_url'])) {
            $main_image = $data['image_url'];
        } elseif (!empty($data['image'])) {
            $main_image = $data['image'];
        } elseif (!empty($data['gallery']) && is_array($data['gallery']) && !empty($data['gallery'][0])) {
            // API returns images in 'gallery' field
            $main_image = $data['gallery'][0];
        } elseif (!empty($data['images']) && is_array($data['images']) && !empty($data['images'][0])) {
            $main_image = $data['images'][0];
        }

        $this->logger->info('Image handling', [
            'sku' => $sku,
            'main_image' => $main_image,
            'has_gallery' => !empty($data['gallery']),
            'gallery_count' => !empty($data['gallery']) ? count($data['gallery']) : 0
        ]);

        if (!empty($main_image)) {
            $attach_result = $this->attach_image_from_url($main_image, $product_id);
            $this->logger->info('Main image attachment result', ['sku' => $sku, 'result' => $attach_result]);
        }

        // Handle Gallery Images - check multiple possible field names
        $gallery_images = [];
        if (!empty($data['gallery_images']) && is_array($data['gallery_images'])) {
            $gallery_images = $data['gallery_images'];
        } elseif (!empty($data['gallery']) && is_array($data['gallery'])) {
            // API returns images in 'gallery' field
            $gallery_images = $data['gallery'];
        } elseif (!empty($data['images']) && is_array($data['images'])) {
            $gallery_images = $data['images'];
        }

        if (!empty($gallery_images)) {
            $this->attach_gallery_images($gallery_images, $product_id, $main_image);
        }

        // Create mapping
        $mapping_id = $this->product_mapper->map($product_id, $sku, isset($data['title']) ? $data['title'] : '');
        
        if (!$mapping_id) {
            $this->logger->error('Failed to create product mapping during import', [
                'sku' => $sku,
                'wc_id' => $product_id
            ]);
            // If mapping fails, we should still return the product ID but log the error
        }

        $this->logger->info('Product imported successfully', [
            'sku' => $sku,
            'wc_product_id' => $product_id,
            'price' => $final_price,
            'stock' => $final_stock
        ]);

        return $product_id;
    }

    /**
     * Resync an existing product with data from Dropshipzone API
     *
     * @param int          $product_id WooCommerce product ID
     * @param array|string $data       API product data or SKU string (optional - will fetch from mapping if not provided)
     * @param array        $options    Resync options (update_images, update_description, update_price, update_stock)
     * @return int|\WP_Error Product ID or error
     */
    public function resync_product($product_id, $data = null, $options = []) {
        // Default options
        $defaults = [
            'update_images' => true,
            'update_description' => true,
            'update_price' => true,
            'update_stock' => true,
            'update_categories' => true, // Update product categories from API
            'update_title' => false, // Don't update title by default (user might have customized it)
        ];
        $options = wp_parse_args($options, $defaults);

        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('product_not_found', __('WooCommerce product not found.', 'dropshipzone'));
        }

        $sku = $product->get_sku();

        // If no data provided, get SKU from product or mapping and fetch from API
        if (empty($data)) {
            if (empty($sku)) {
                // Try to get SKU from mapping
                $sku = $this->product_mapper->get_dsz_sku($product_id);
            }

            if (empty($sku)) {
                return new \WP_Error('no_sku', __('Product has no SKU or mapping to resync from.', 'dropshipzone'));
            }

            // Fetch from API
            $response = $this->api_client->get_products_by_skus([$sku]);
            
            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['result'])) {
                /* translators: %s: product SKU */
                return new \WP_Error('api_product_not_found', sprintf(__('Product with SKU %s not found in Dropshipzone API.', 'dropshipzone'), $sku));
            }

            $data = $response['result'][0];
        }

        // If data is still a string (SKU), fetch it
        if (is_string($data)) {
            $sku = $data;
            $response = $this->api_client->get_products_by_skus([$sku]);
            
            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['result'])) {
                /* translators: %s: product SKU */
                return new \WP_Error('api_product_not_found', sprintf(__('Product with SKU %s not found in Dropshipzone API.', 'dropshipzone'), $sku));
            }

            $data = $response['result'][0];
        }

        $this->logger->info('Starting product resync', [
            'product_id' => $product_id,
            'sku' => $sku,
            'options' => $options
        ]);

        // Update title if enabled
        if ($options['update_title']) {
            $product_name = '';
            if (!empty($data['title'])) {
                $product_name = $data['title'];
            } elseif (!empty($data['name'])) {
                $product_name = $data['name'];
            } elseif (!empty($data['product_name'])) {
                $product_name = $data['product_name'];
            }
            if (!empty($product_name)) {
                $product->set_name($product_name);
            }
        }

        // Update description if enabled
        if ($options['update_description']) {
            // Note: Dropshipzone API returns description in 'desc' field
            $description = '';
            if (!empty($data['desc'])) {
                $description = $data['desc'];
            } elseif (!empty($data['description'])) {
                $description = $data['description'];
            } elseif (!empty($data['long_description'])) {
                $description = $data['long_description'];
            }
            if (!empty($description)) {
                $product->set_description($description);
            }
            
            if (!empty($data['short_description'])) {
                $product->set_short_description($data['short_description']);
            }
        }

        // Update price if enabled
        if ($options['update_price']) {
            $cost_price = isset($data['price']) ? floatval($data['price']) : 0;
            if ($cost_price > 0) {
                $final_price = $this->price_sync->calculate_price($cost_price);
                $product->set_regular_price($final_price);
            }
        }

        // Update stock if enabled
        if ($options['update_stock']) {
            $api_stock = 0;
            if (isset($data['stock_qty'])) {
                $api_stock = intval($data['stock_qty']);
            } elseif (isset($data['stock'])) {
                $api_stock = intval($data['stock']);
            } elseif (isset($data['qty'])) {
                $api_stock = intval($data['qty']);
            } elseif (isset($data['quantity'])) {
                $api_stock = intval($data['quantity']);
            }
            $final_stock = $this->stock_sync->calculate_stock($api_stock);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($final_stock);
            $product->set_stock_status($final_stock > 0 ? 'instock' : 'outofstock');
        }

        // Update dimensions and weight
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

        // Update categories if enabled
        if ($options['update_categories']) {
            $category_string = '';
            if (!empty($data['Category'])) {
                $category_string = $data['Category'];
            } elseif (!empty($data['l1_category_name'])) {
                $cat_parts = [];
                if (!empty($data['l1_category_name'])) $cat_parts[] = $data['l1_category_name'];
                if (!empty($data['l2_category_name'])) $cat_parts[] = $data['l2_category_name'];
                if (!empty($data['l3_category_name'])) $cat_parts[] = $data['l3_category_name'];
                $category_string = implode(' > ', $cat_parts);
            } elseif (!empty($data['categories'])) {
                $category_string = $data['categories'];
            } elseif (!empty($data['category'])) {
                $category_string = $data['category'];
            }

            if (!empty($category_string)) {
                $category_ids = $this->create_categories($category_string);
                if (!empty($category_ids)) {
                    $product->set_category_ids($category_ids);
                }
            }
        }

        // Save the product
        $product->save();

        // Update images if enabled
        if ($options['update_images']) {
            // Get the main image URL
            $main_image = '';
            if (!empty($data['image_url'])) {
                $main_image = $data['image_url'];
            } elseif (!empty($data['image'])) {
                $main_image = $data['image'];
            } elseif (!empty($data['gallery']) && is_array($data['gallery']) && !empty($data['gallery'][0])) {
                $main_image = $data['gallery'][0];
            } elseif (!empty($data['images']) && is_array($data['images']) && !empty($data['images'][0])) {
                $main_image = $data['images'][0];
            }

            // Delete existing featured image and gallery
            $existing_thumbnail_id = get_post_thumbnail_id($product_id);
            if ($existing_thumbnail_id) {
                wp_delete_attachment($existing_thumbnail_id, true);
            }

            $existing_gallery = get_post_meta($product_id, '_product_image_gallery', true);
            if ($existing_gallery) {
                $gallery_ids = explode(',', $existing_gallery);
                foreach ($gallery_ids as $gallery_id) {
                    wp_delete_attachment(intval($gallery_id), true);
                }
                delete_post_meta($product_id, '_product_image_gallery');
            }

            // Attach new main image
            if (!empty($main_image)) {
                $this->attach_image_from_url($main_image, $product_id);
            }

            // Attach new gallery images
            $gallery_images = [];
            if (!empty($data['gallery_images']) && is_array($data['gallery_images'])) {
                $gallery_images = $data['gallery_images'];
            } elseif (!empty($data['gallery']) && is_array($data['gallery'])) {
                $gallery_images = $data['gallery'];
            } elseif (!empty($data['images']) && is_array($data['images'])) {
                $gallery_images = $data['images'];
            }

            if (!empty($gallery_images)) {
                $this->attach_gallery_images($gallery_images, $product_id, $main_image);
            }
        }

        // Update mapping last resynced time (full data resync)
        $this->product_mapper->update_last_resynced($product_id);

        $this->logger->info('Product resynced successfully', [
            'product_id' => $product_id,
            'sku' => $sku
        ]);

        return $product_id;
    }

    /**
     * Attach image from URL to a product
     *
     * @param string $url        Image URL
     * @param int    $product_id Product ID
     * @param bool   $set_featured Whether to set as featured image (default true)
     * @return int|bool Attachment ID or false
     */
    private function attach_image_from_url($url, $product_id, $set_featured = true) {
        // Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->warning('Invalid image URL provided', ['url' => $url]);
            return false;
        }

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

        // Extract filename from URL (handle query strings and special characters)
        $filename = $this->get_filename_from_url($url);
        
        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        // Sideload file into media library
        $id = media_handle_sideload($file_array, $product_id);

        if (is_wp_error($id)) {
            wp_delete_file($file_array['tmp_name']);
            $this->logger->warning('Failed to sideload product image', [
                'url' => $url,
                'error' => $id->get_error_message()
            ]);
            return false;
        }

        // Set as featured image only if requested
        if ($set_featured) {
            set_post_thumbnail($product_id, $id);
        }

        return $id;
    }

    /**
     * Extract clean filename from URL
     *
     * @param string $url Image URL
     * @return string Clean filename
     */
    private function get_filename_from_url($url) {
        // Parse URL and get path
        $parsed = wp_parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        
        // Get basename from path (excludes query string)
        $filename = basename($path);
        
        // If filename is empty or doesn't have an extension, generate one
        if (empty($filename) || strpos($filename, '.') === false) {
            $filename = 'product-image-' . uniqid() . '.jpg';
        }
        
        // Remove any URL encoding
        $filename = urldecode($filename);
        
        // Sanitize filename
        $filename = sanitize_file_name($filename);
        
        return $filename;
    }

    /**
     * Attach gallery images from URLs
     * 
     * @param array  $urls       Array of image URLs
     * @param int    $product_id Product ID
     * @param string $main_image Main/featured image URL to skip (optional)
     */
    private function attach_gallery_images($urls, $product_id, $main_image = '') {
        if (empty($urls) || !is_array($urls)) {
            return;
        }

        $gallery_ids = [];
        $featured_id = get_post_thumbnail_id($product_id);

        foreach ($urls as $index => $url) {
            // Skip empty URLs
            if (empty($url)) {
                continue;
            }

            // Skip the first image if it was already used as featured image
            // Also skip if URL matches the main image URL
            if ($index === 0 && $featured_id) {
                continue;
            }
            
            if (!empty($main_image) && $url === $main_image) {
                continue;
            }

            // Attach image but don't set as featured (pass false)
            $id = $this->attach_image_from_url($url, $product_id, false);
            if ($id && $id !== $featured_id) {
                $gallery_ids[] = $id;
            }
        }

        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    /**
     * Create or get categories by name/path
     * 
     * @param string|array $categories Category name, path or array
     * @return array Category IDs
     */
    private function create_categories($categories) {
        if (is_string($categories)) {
            // Check if it's a comma separated list
            if (strpos($categories, ',') !== false) {
                $categories = explode(',', $categories);
            } else {
                $categories = [$categories];
            }
        }

        $ids = [];
        foreach ($categories as $cat_string) {
            $cat_string = trim($cat_string);
            if (empty($cat_string)) continue;

            // Handle hierarchical categories (e.g. "Furniture > Sofas")
            $parts = explode('>', $cat_string);
            $parent = 0;

            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) continue;

                $existing = term_exists($part, 'product_cat', $parent);
                if ($existing) {
                    $parent = is_array($existing) ? $existing['term_id'] : $existing;
                } else {
                    $new_term = wp_insert_term($part, 'product_cat', ['parent' => $parent]);
                    if (!is_wp_error($new_term)) {
                        $parent = $new_term['term_id'];
                    } else {
                        // If it failed (maybe someone else created it meanwhile), try to get it again
                        $existing = term_exists($part, 'product_cat', $parent);
                        $parent = $existing ? (is_array($existing) ? $existing['term_id'] : $existing) : 0;
                    }
                }
            }

            if ($parent > 0) {
                $ids[] = $parent;
            }
        }

        return array_unique($ids);
    }
}
