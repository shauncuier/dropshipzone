<?php
/**
 * Helper Functions
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate price with markup
 *
 * @param float  $base_price     Original supplier price
 * @param string $markup_type    Type of markup (percentage or fixed)
 * @param float  $markup_value   Markup amount
 * @param bool   $gst_enabled    Whether to apply GST
 * @param string $gst_type       GST type (include or exclude)
 * @param bool   $round_enabled  Whether to round price
 * @param string $round_type     Rounding type (99, 95, nearest)
 * @return float Calculated final price
 */
function dszsync_calculate_price($base_price, $markup_type = 'percentage', $markup_value = 0, $gst_enabled = false, $gst_type = 'include', $round_enabled = false, $round_type = '99') {
    $price = floatval($base_price);
    
    // Apply markup
    if ($markup_type === 'percentage') {
        $price = $price * (1 + ($markup_value / 100));
    } else {
        $price = $price + $markup_value;
    }
    
    // Apply GST (Australian GST is 10%)
    if ($gst_enabled) {
        if ($gst_type === 'include') {
            // Price already includes GST, no change needed
        } else {
            // Add GST to price
            $price = $price * 1.10;
        }
    }
    
    // Apply rounding
    if ($round_enabled) {
        $price = dszsync_round_price($price, $round_type);
    }
    
    return round($price, 2);
}

/**
 * Round price to specified format
 *
 * @param float  $price      Price to round
 * @param string $round_type Rounding type (99, 95, nearest)
 * @return float Rounded price
 */
function dszsync_round_price($price, $round_type = '99') {
    $whole = floor($price);
    
    switch ($round_type) {
        case '99':
            return $whole + 0.99;
        case '95':
            return $whole + 0.95;
        case 'nearest':
            return round($price);
        default:
            return $price;
    }
}

/**
 * Calculate stock with buffer
 *
 * @param int  $stock         Original stock quantity
 * @param bool $buffer_enabled Whether buffer is enabled
 * @param int  $buffer_amount Buffer amount to subtract
 * @return int Final stock quantity (minimum 0)
 */
function dszsync_calculate_stock($stock, $buffer_enabled = false, $buffer_amount = 0) {
    $quantity = intval($stock);
    
    if ($buffer_enabled && $buffer_amount > 0) {
        $quantity = $quantity - $buffer_amount;
    }
    
    return max(0, $quantity);
}

/**
 * Check if current user can manage sync
 *
 * @return bool
 */
function dszsync_current_user_can_manage() {
    return current_user_can('manage_woocommerce');
}

/**
 * Get option with default
 *
 * @param string $key     Option key
 * @param mixed  $default Default value
 * @return mixed
 */
function dszsync_get_option($key, $default = null) {
    $value = get_option($key, $default);
    return $value !== false ? $value : $default;
}

/**
 * Sanitize and validate API credentials
 *
 * @param string $email    Email address
 * @param string $password Password
 * @return array|WP_Error Sanitized credentials or error
 */
function dszsync_validate_credentials($email, $password) {
    $email = sanitize_email($email);
    $password = sanitize_text_field($password);
    
    if (empty($email) || !is_email($email)) {
        return new \WP_Error('invalid_email', __('Please enter a valid email address.', '3s-soft-price-stock-sync-for-dropshipzone'));
    }
    
    if (empty($password)) {
        return new \WP_Error('empty_password', __('Password cannot be empty.', '3s-soft-price-stock-sync-for-dropshipzone'));
    }
    
    return [
        'email' => $email,
        'password' => $password,
    ];
}

/**
 * Format timestamp for display
 *
 * @param int|string $timestamp Timestamp or datetime string
 * @return string Formatted date/time
 */
function dszsync_format_datetime($timestamp) {
    if (empty($timestamp)) {
        return __('Never', '3s-soft-price-stock-sync-for-dropshipzone');
    }
    
    if (is_string($timestamp)) {
        $timestamp = strtotime($timestamp);
    }
    
    return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
}

/**
 * Get human-readable time difference
 *
 * @param string|int $datetime DateTime string or Unix timestamp
 * @return string Human-readable time difference
 */
function dszsync_time_ago($datetime) {
    if (empty($datetime)) {
        return __('Never', '3s-soft-price-stock-sync-for-dropshipzone');
    }
    
    // Convert datetime string to Unix timestamp if needed
    if (is_string($datetime) && !is_numeric($datetime)) {
        $timestamp = strtotime($datetime);
    } else {
        $timestamp = (int) $datetime;
    }
    
    // Check for invalid timestamp
    if (!$timestamp || $timestamp <= 0) {
        return __('Never', '3s-soft-price-stock-sync-for-dropshipzone');
    }
    
    return human_time_diff($timestamp, current_time('timestamp')) . ' ' . __('ago', '3s-soft-price-stock-sync-for-dropshipzone');
}

/**
 * Check memory usage and return if near limit
 *
 * @param int $threshold_percent Percentage of memory limit to trigger (default 80%)
 * @return bool True if near memory limit
 */
function dszsync_is_memory_near_limit($threshold_percent = 80) {
    $memory_limit = ini_get('memory_limit');
    
    // Convert to bytes
    if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
        $memory_limit = $matches[1];
        switch (strtoupper($matches[2])) {
            case 'G':
                $memory_limit *= 1024;
                // fall through
            case 'M':
                $memory_limit *= 1024;
                // fall through
            case 'K':
                $memory_limit *= 1024;
        }
    }
    
    $current_usage = memory_get_usage(true);
    $threshold = ($memory_limit * $threshold_percent) / 100;
    
    return $current_usage >= $threshold;
}

/**
 * Get WooCommerce product by SKU
 *
 * @param string $sku Product SKU
 * @return WC_Product|null Product object or null if not found
 */
function dszsync_get_product_by_sku($sku) {
    $product_id = wc_get_product_id_by_sku($sku);
    
    if ($product_id) {
        return wc_get_product($product_id);
    }
    
    return null;
}

/**
 * Encrypt sensitive data for storage
 *
 * @param string $data Data to encrypt
 * @return string Encrypted data
 */
function dszsync_encrypt($data) {
    if (empty($data)) {
        return '';
    }

    $key = wp_salt('auth');
    $iv = openssl_random_pseudo_bytes(16);
    $ciphertext = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    if ($ciphertext === false) {
        return '';
    }

    // Version-prefixed format with random IV prepended to the ciphertext
    return 'v2:' . base64_encode($iv . $ciphertext);
}

/**
 * Decrypt sensitive data
 *
 * @param string $encrypted_data Encrypted data
 * @return string Decrypted data
 */
function dszsync_decrypt($encrypted_data) {
    if (empty($encrypted_data)) {
        return '';
    }

    $key = wp_salt('auth');

    // Current format: "v2:" prefix, random IV prepended to raw ciphertext
    if (strpos($encrypted_data, 'v2:') === 0) {
        $raw = base64_decode(substr($encrypted_data, 3), true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $decrypted = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return ($decrypted === false) ? '' : $decrypted;
    }

    // Legacy format: static IV derived from the secure_auth salt
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    $decrypted = openssl_decrypt(base64_decode($encrypted_data), 'AES-256-CBC', $key, 0, $iv);

    return ($decrypted === false) ? '' : $decrypted;
}

/**
 * Get the supplier cost from Dropshipzone API product data
 *
 * The API returns the wholesale cost in both `cost` and `price` fields
 * (`price` is documented as "Product cost price"). Prefer `cost` and fall
 * back to `price` so every pricing path uses the same source value.
 *
 * @param array $api_data Product data from the Dropshipzone API
 * @return float Supplier cost (0 if unavailable)
 */
function dszsync_get_api_cost($api_data) {
    $cost = isset($api_data['cost']) ? floatval($api_data['cost']) : 0;

    if ($cost <= 0) {
        $cost = isset($api_data['price']) ? floatval($api_data['price']) : 0;
    }

    return $cost;
}

/**
 * Recursively sanitize an API product payload received from client input.
 *
 * Product data is cached browser-side during catalogue searches and posted
 * back on import to avoid a second API call. It must never be trusted:
 * every scalar is sanitized and the structure depth is bounded.
 *
 * @param mixed $data  Raw decoded payload
 * @param int   $depth Current recursion depth (internal)
 * @return mixed Sanitized payload
 */
function dszsync_sanitize_api_product($data, $depth = 0) {
    if ($depth > 5) {
        return null;
    }

    if (is_array($data)) {
        $clean = [];
        foreach ($data as $key => $value) {
            // Keys keep their original case - the API uses mixed-case field
            // names (Category, RrpPrice) that the importer looks up verbatim
            $clean_key = is_int($key) ? $key : preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
            if ($clean_key === '') {
                continue;
            }
            $clean[$clean_key] = dszsync_sanitize_api_product($value, $depth + 1);
        }
        return $clean;
    }

    if (is_bool($data) || is_int($data) || is_float($data) || is_null($data)) {
        return $data;
    }

    if (!is_string($data)) {
        return null;
    }

    // Image and product URLs must survive sanitization intact
    if (preg_match('#^https?://#i', $data)) {
        return esc_url_raw($data);
    }

    // Product descriptions carry markup; keep it but strip anything unsafe.
    // Everything else is plain text.
    if ($data !== wp_strip_all_tags($data)) {
        return wp_kses_post($data);
    }

    return sanitize_text_field($data);
}

/**
 * Log message (wrapper for logger)
 *
 * @param string $level   Log level (info, warning, error)
 * @param string $message Log message
 * @param array  $context Additional context
 */
function dszsync_log($level, $message, $context = []) {
    $plugin = dszsync_sync();
    
    if ($plugin && isset($plugin->logger)) {
        $plugin->logger->log($level, $message, $context);
    }
}

/**
 * Get sync status summary
 *
 * @return array Sync status data
 */
function dszsync_get_sync_status() {
    $settings = dszsync_get_option('dszsync_settings', []);
    $token_expiry = dszsync_get_option('dszsync_token_expiry', 0);
    
    return [
        'last_sync' => isset($settings['last_sync']) ? $settings['last_sync'] : null,
        'in_progress' => isset($settings['sync_in_progress']) ? $settings['sync_in_progress'] : false,
        'products_updated' => isset($settings['products_updated']) ? $settings['products_updated'] : 0,
        'errors_count' => isset($settings['errors_count']) ? $settings['errors_count'] : 0,
        'token_valid' => $token_expiry > time(),
        'token_expiry' => $token_expiry,
    ];
}
