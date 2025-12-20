<?php
/**
 * API Client Class
 *
 * Handles all communication with the Dropshipzone API
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Client for Dropshipzone
 */
class API_Client {

    /**
     * API Base URL
     */
    const API_BASE_URL = 'https://api.dropshipzone.com.au';

    /**
     * Token expiry buffer (refresh 2 minutes before expiry)
     */
    const TOKEN_BUFFER_SECONDS = 120;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Current API token
     */
    private $token = null;

    /**
     * Token expiry timestamp
     */
    private $token_expiry = 0;

    /**
     * Max retry attempts
     */
    private $max_retries = 3;

    /**
     * Constructor
     *
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
        $this->load_token();
    }

    /**
     * Load stored token from database
     */
    private function load_token() {
        $this->token = get_option('dsz_sync_api_token', '');
        $this->token_expiry = intval(get_option('dsz_sync_token_expiry', 0));
    }

    /**
     * Check if token is valid
     *
     * @return bool
     */
    public function is_token_valid() {
        return !empty($this->token) && ($this->token_expiry - self::TOKEN_BUFFER_SECONDS) > time();
    }

    /**
     * Get current token status
     *
     * @return array Token status info
     */
    public function get_token_status() {
        return [
            'has_token' => !empty($this->token),
            'is_valid' => $this->is_token_valid(),
            'expires_at' => $this->token_expiry,
            'expires_in' => max(0, $this->token_expiry - time()),
        ];
    }

    /**
     * Authenticate with API
     *
     * @param string $email    API email (optional, uses stored if not provided)
     * @param string $password API password (optional, uses stored if not provided)
     * @return array|WP_Error Token data or error
     */
    public function authenticate($email = null, $password = null) {
        // Use stored credentials if not provided
        if (empty($email)) {
            $email = get_option('dsz_sync_api_email', '');
        }
        if (empty($password)) {
            $encrypted_password = get_option('dsz_sync_api_password', '');
            $password = dsz_decrypt($encrypted_password);
        }

        // Validate credentials
        if (empty($email) || empty($password)) {
            $this->logger->error('Authentication failed: Missing credentials');
            return new \WP_Error('missing_credentials', __('API email and password are required.', 'dropshipzone-sync'));
        }

        // Make authentication request
        $response = $this->make_request('POST', '/auth', [
            'email' => $email,
            'password' => $password,
        ], false); // Don't use auth header for auth request

        if (is_wp_error($response)) {
            $this->logger->error('Authentication failed', ['error' => $response->get_error_message()]);
            return $response;
        }

        // Extract token data
        if (isset($response['token'])) {
            $this->token = $response['token'];
            $this->token_expiry = isset($response['exp']) ? intval($response['exp']) : (time() + 900); // 15 minutes default

            // Store token
            update_option('dsz_sync_api_token', $this->token);
            update_option('dsz_sync_token_expiry', $this->token_expiry);

            $this->logger->info('Authentication successful', [
                'expires_at' => date('Y-m-d H:i:s', $this->token_expiry),
            ]);

            return [
                'success' => true,
                'token' => $this->token,
                'expires_at' => $this->token_expiry,
            ];
        }

        $this->logger->error('Authentication failed: Invalid response', ['response' => $response]);
        return new \WP_Error('invalid_response', __('Invalid API response. Please check your credentials.', 'dropshipzone-sync'));
    }

    /**
     * Ensure we have a valid token, refresh if needed
     *
     * @return bool|WP_Error True if valid, error otherwise
     */
    public function ensure_valid_token() {
        if ($this->is_token_valid()) {
            return true;
        }

        // Try to refresh token
        $result = $this->authenticate();
        
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Get products from API
     *
     * @param array $params Query parameters
     * @return array|WP_Error Products data or error
     */
    public function get_products($params = []) {
        // Ensure valid token
        $token_check = $this->ensure_valid_token();
        if (is_wp_error($token_check)) {
            return $token_check;
        }

        // Default parameters
        $defaults = [
            'page_no' => 1,
            'limit' => 200, // Max allowed
            'enabled' => true,
        ];

        $params = wp_parse_args($params, $defaults);

        // Make request
        $response = $this->make_request('GET', '/v2/products', $params, true);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to fetch products', [
                'error' => $response->get_error_message(),
                'params' => $params,
            ]);
            return $response;
        }

        // Log success
        $this->logger->debug('Products fetched successfully', [
            'page' => $params['page_no'],
            'total' => isset($response['total']) ? $response['total'] : 0,
        ]);

        return $response;
    }

    /**
     * Get all products (paginated)
     *
     * @param int   $page_no Page number
     * @param int   $limit   Items per page
     * @param array $filters Additional filters
     * @return array|WP_Error Products data or error
     */
    public function get_all_products($page_no = 1, $limit = 200, $filters = []) {
        $params = array_merge([
            'page_no' => $page_no,
            'limit' => min($limit, 200), // Max 200 per API docs
        ], $filters);

        return $this->get_products($params);
    }

    /**
     * Get products by SKUs
     *
     * @param array $skus Array of SKUs (max 100)
     * @return array|WP_Error Products data or error
     */
    public function get_products_by_skus($skus) {
        if (empty($skus)) {
            return [];
        }

        // API allows max 100 SKUs
        $skus = array_slice($skus, 0, 100);
        
        return $this->get_products([
            'skus' => implode(',', $skus),
            'limit' => 200,
        ]);
    }

    /**
     * Get stock data (historical)
     *
     * @param array  $skus      Array of SKUs
     * @param string $start_time Start time (Y-m-d H:i:s)
     * @param string $end_time   End time (Y-m-d H:i:s)
     * @param int    $page_no    Page number
     * @param int    $limit      Items per page
     * @return array|WP_Error Stock data or error
     */
    public function get_stock($skus, $start_time = null, $end_time = null, $page_no = 1, $limit = 160) {
        // Ensure valid token
        $token_check = $this->ensure_valid_token();
        if (is_wp_error($token_check)) {
            return $token_check;
        }

        // Default time range (last 10 days - API max)
        if (empty($start_time)) {
            $start_time = date('Y-m-d H:i:s', strtotime('-9 days'));
        }
        if (empty($end_time)) {
            $end_time = date('Y-m-d H:i:s');
        }

        $body = [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'page_no' => $page_no,
            'limit' => min($limit, 160), // Max 160 per API docs
        ];

        if (!empty($skus)) {
            $body['skus'] = implode(',', array_slice($skus, 0, 100));
        }

        $response = $this->make_request('POST', '/stock', $body, true);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to fetch stock data', [
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        return $response;
    }

    /**
     * Test API connection
     *
     * @param string $email    API email
     * @param string $password API password
     * @return array|WP_Error Connection test result
     */
    public function test_connection($email, $password) {
        $result = $this->authenticate($email, $password);

        if (is_wp_error($result)) {
            return $result;
        }

        // Try to fetch a single product to verify full access
        $products = $this->get_products(['limit' => 40, 'page_no' => 1]);

        if (is_wp_error($products)) {
            return $products;
        }

        return [
            'success' => true,
            'message' => __('Connection successful!', 'dropshipzone-sync'),
            'products_available' => isset($products['total']) ? $products['total'] : 0,
        ];
    }

    /**
     * Make API request
     *
     * @param string $method   HTTP method
     * @param string $endpoint API endpoint
     * @param array  $data     Request data
     * @param bool   $use_auth Whether to include auth header
     * @param int    $retry    Current retry attempt
     * @return array|WP_Error Response data or error
     */
    private function make_request($method, $endpoint, $data = [], $use_auth = true, $retry = 0) {
        $url = self::API_BASE_URL . $endpoint;

        // Build headers
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($use_auth && !empty($this->token)) {
            $headers['Authorization'] = 'jwt ' . $this->token;
        }

        // Build request args
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true,
        ];

        // Add body or query params based on method
        if ($method === 'GET') {
            if (!empty($data)) {
                $url = add_query_arg($data, $url);
            }
        } else {
            $args['body'] = wp_json_encode($data);
        }

        // Make request
        $response = wp_remote_request($url, $args);

        // Check for WP error
        if (is_wp_error($response)) {
            // Retry on connection errors
            if ($retry < $this->max_retries) {
                $this->logger->warning('Request failed, retrying...', [
                    'endpoint' => $endpoint,
                    'retry' => $retry + 1,
                    'error' => $response->get_error_message(),
                ]);
                sleep(1); // Wait 1 second before retry
                return $this->make_request($method, $endpoint, $data, $use_auth, $retry + 1);
            }
            return $response;
        }

        // Get response code and body
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Handle different response codes
        if ($response_code === 401) {
            // Token expired
            if ($use_auth && $retry < $this->max_retries) {
                $this->logger->info('Token expired, refreshing...');
                $auth_result = $this->authenticate();
                if (!is_wp_error($auth_result)) {
                    return $this->make_request($method, $endpoint, $data, $use_auth, $retry + 1);
                }
            }
            return new \WP_Error('unauthorized', __('Authentication failed. Please check your credentials.', 'dropshipzone-sync'));
        }

        if ($response_code === 429) {
            // Rate limited
            if ($retry < $this->max_retries) {
                $this->logger->warning('Rate limited, waiting...', ['endpoint' => $endpoint]);
                sleep(5); // Wait 5 seconds
                return $this->make_request($method, $endpoint, $data, $use_auth, $retry + 1);
            }
            return new \WP_Error('rate_limited', __('API rate limit exceeded. Please try again later.', 'dropshipzone-sync'));
        }

        if ($response_code >= 400) {
            $error_message = isset($response_data['message']) ? $response_data['message'] : __('API request failed.', 'dropshipzone-sync');
            return new \WP_Error('api_error', $error_message, ['code' => $response_code, 'response' => $response_data]);
        }

        return $response_data;
    }

    /**
     * Clear stored token
     */
    public function clear_token() {
        $this->token = null;
        $this->token_expiry = 0;
        delete_option('dsz_sync_api_token');
        delete_option('dsz_sync_token_expiry');
    }

    /**
     * Get API statistics
     *
     * @return array API stats
     */
    public function get_stats() {
        $products = $this->get_products(['limit' => 40, 'page_no' => 1]);
        
        if (is_wp_error($products)) {
            return [
                'total_products' => 0,
                'error' => $products->get_error_message(),
            ];
        }

        return [
            'total_products' => isset($products['total']) ? $products['total'] : 0,
            'total_pages' => isset($products['total_pages']) ? $products['total_pages'] : 0,
        ];
    }
}
