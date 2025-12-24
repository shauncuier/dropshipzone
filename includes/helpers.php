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
function dsz_calculate_price($base_price, $markup_type = 'percentage', $markup_value = 0, $gst_enabled = false, $gst_type = 'include', $round_enabled = false, $round_type = '99') {
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
        $price = dsz_round_price($price, $round_type);
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
function dsz_round_price($price, $round_type = '99') {
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
function dsz_calculate_stock($stock, $buffer_enabled = false, $buffer_amount = 0) {
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
function dsz_current_user_can_manage() {
    return current_user_can('manage_woocommerce');
}

/**
 * Get option with default
 *
 * @param string $key     Option key
 * @param mixed  $default Default value
 * @return mixed
 */
function dsz_get_option($key, $default = null) {
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
function dsz_validate_credentials($email, $password) {
    $email = sanitize_email($email);
    $password = sanitize_text_field($password);
    
    if (empty($email) || !is_email($email)) {
        return new \WP_Error('invalid_email', __('Please enter a valid email address.', 'dropshipzone'));
    }
    
    if (empty($password)) {
        return new \WP_Error('empty_password', __('Password cannot be empty.', 'dropshipzone'));
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
function dsz_format_datetime($timestamp) {
    if (empty($timestamp)) {
        return __('Never', 'dropshipzone');
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
function dsz_time_ago($datetime) {
    if (empty($datetime)) {
        return __('Never', 'dropshipzone');
    }
    
    // Convert datetime string to Unix timestamp if needed
    if (is_string($datetime) && !is_numeric($datetime)) {
        $timestamp = strtotime($datetime);
    } else {
        $timestamp = (int) $datetime;
    }
    
    // Check for invalid timestamp
    if (!$timestamp || $timestamp <= 0) {
        return __('Never', 'dropshipzone');
    }
    
    return human_time_diff($timestamp, current_time('timestamp')) . ' ' . __('ago', 'dropshipzone');
}

/**
 * Check memory usage and return if near limit
 *
 * @param int $threshold_percent Percentage of memory limit to trigger (default 80%)
 * @return bool True if near memory limit
 */
function dsz_is_memory_near_limit($threshold_percent = 80) {
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
function dsz_get_product_by_sku($sku) {
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
function dsz_encrypt($data) {
    if (empty($data)) {
        return '';
    }
    
    $key = wp_salt('auth');
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    
    return base64_encode(openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv));
}

/**
 * Decrypt sensitive data
 *
 * @param string $encrypted_data Encrypted data
 * @return string Decrypted data
 */
function dsz_decrypt($encrypted_data) {
    if (empty($encrypted_data)) {
        return '';
    }
    
    $key = wp_salt('auth');
    $iv = substr(hash('sha256', wp_salt('secure_auth')), 0, 16);
    
    return openssl_decrypt(base64_decode($encrypted_data), 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Log message (wrapper for logger)
 *
 * @param string $level   Log level (info, warning, error)
 * @param string $message Log message
 * @param array  $context Additional context
 */
function dsz_log($level, $message, $context = []) {
    $plugin = dsz_sync();
    
    if ($plugin && isset($plugin->logger)) {
        $plugin->logger->log($level, $message, $context);
    }
}

/**
 * Get sync status summary
 *
 * @return array Sync status data
 */
function dsz_get_sync_status() {
    $settings = dsz_get_option('dsz_sync_settings', []);
    $token_expiry = dsz_get_option('dsz_sync_token_expiry', 0);
    
    return [
        'last_sync' => isset($settings['last_sync']) ? $settings['last_sync'] : null,
        'in_progress' => isset($settings['sync_in_progress']) ? $settings['sync_in_progress'] : false,
        'products_updated' => isset($settings['products_updated']) ? $settings['products_updated'] : 0,
        'errors_count' => isset($settings['errors_count']) ? $settings['errors_count'] : 0,
        'token_valid' => $token_expiry > time(),
        'token_expiry' => $token_expiry,
    ];
}
