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
        // Reload token from database in case it was updated elsewhere
        $this->load_token();
        
        if ($this->is_token_valid()) {
            $this->logger->debug('Token is valid', [
                'expires_in' => $this->token_expiry - time(),
            ]);
            return true;
        }

        // Token expired or missing, need to refresh
        $this->logger->info('Token expired or missing, refreshing...', [
            'had_token' => !empty($this->token),
            'expiry_was' => $this->token_expiry > 0 ? date('Y-m-d H:i:s', $this->token_expiry) : 'never set',
        ]);
        
        // Try to refresh token
        $result = $this->authenticate();
        
        if (is_wp_error($result)) {
            $this->logger->error('Token refresh failed', [
                'error' => $result->get_error_message(),
            ]);
            return $result;
        }

        $this->logger->info('Token refreshed successfully', [
            'expires_at' => date('Y-m-d H:i:s', $this->token_expiry),
            'expires_in' => $this->token_expiry - time(),
        ]);

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

        // Default parameters - don't include enabled when searching by SKU
        $defaults = [
            'page_no' => 1,
            'limit' => 200, // Max allowed
        ];
        
        // Only add enabled filter if not searching by SKUs
        if (empty($params['skus'])) {
            $defaults['enabled'] = true;
        }

        $params = wp_parse_args($params, $defaults);
        
        // Log the API request details
        $this->logger->debug('Making products API request', [
            'params' => $params,
        ]);

        // Make request
        $response = $this->make_request('GET', '/v2/products', $params, true);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to fetch products', [
                'error' => $response->get_error_message(),
                'params' => $params,
            ]);
            return $response;
        }

        // Log success with more details
        $this->logger->debug('Products fetched successfully', [
            'page' => $params['page_no'],
            'total' => isset($response['total']) ? $response['total'] : 0,
            'result_count' => isset($response['result']) ? count($response['result']) : 0,
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
            return ['result' => []];
        }

        // API allows max 100 SKUs
        $skus = array_slice($skus, 0, 100);
        $skus_string = implode(',', $skus);
        
        $this->logger->debug('Fetching products by SKUs', [
            'sku_count' => count($skus),
            'sample_skus' => array_slice($skus, 0, 5),
        ]);
        
        $response = $this->get_products([
            'skus' => $skus_string,
            'limit' => 200,
        ]);
        
        // Log the response for debugging
        if (!is_wp_error($response)) {
            $result_count = isset($response['result']) ? count($response['result']) : 0;
            $this->logger->debug('API response for SKUs', [
                'requested' => count($skus),
                'returned' => $result_count,
                'total_in_api' => isset($response['total']) ? $response['total'] : 0,
            ]);
            
            // If no results, log the first few SKUs that weren't found
            if ($result_count === 0) {
                $this->logger->warning('No products found in API for requested SKUs', [
                    'skus_requested' => array_slice($skus, 0, 10),
                ]);
            }
        }
        
        return $response;
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
     * Make API request with rate limit protection
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
            'timeout' => 60, // Increased timeout for large requests
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
        
        // Log the actual API URL for debugging
        $this->logger->debug('API request', [
            'method' => $method,
            'url' => $url,
        ]);

        // Make request
        $response = wp_remote_request($url, $args);

        // Check for WP error
        if (is_wp_error($response)) {
            // Retry on connection errors with exponential backoff
            if ($retry < $this->max_retries) {
                $wait_time = pow(2, $retry) * 2; // 2, 4, 8 seconds
                $this->logger->warning('Request failed, retrying...', [
                    'endpoint' => $endpoint,
                    'retry' => $retry + 1,
                    'wait_seconds' => $wait_time,
                    'error' => $response->get_error_message(),
                ]);
                sleep($wait_time);
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
            // Token expired during request
            if ($use_auth && $retry < $this->max_retries) {
                $this->logger->info('Token expired during request (401), refreshing...', [
                    'endpoint' => $endpoint,
                    'retry' => $retry + 1,
                ]);
                $auth_result = $this->authenticate();
                if (!is_wp_error($auth_result)) {
                    $this->logger->info('Token refreshed, retrying request...');
                    sleep(1); // Small delay after re-auth
                    return $this->make_request($method, $endpoint, $data, $use_auth, $retry + 1);
                }
                $this->logger->error('Token refresh failed during retry', [
                    'error' => $auth_result->get_error_message(),
                ]);
            }
            return new \WP_Error('unauthorized', __('Authentication failed. Please check your credentials.', 'dropshipzone-sync'));
        }

        if ($response_code === 429) {
            // Rate limited - use exponential backoff with longer delays
            if ($retry < $this->max_retries) {
                $wait_times = [10, 30, 60]; // 10 seconds, 30 seconds, 60 seconds
                $wait_time = isset($wait_times[$retry]) ? $wait_times[$retry] : 60;
                
                $this->logger->warning('Rate limited, waiting...', [
                    'endpoint' => $endpoint,
                    'retry' => $retry + 1,
                    'wait_seconds' => $wait_time,
                ]);
                sleep($wait_time);
                return $this->make_request($method, $endpoint, $data, $use_auth, $retry + 1);
            }
            return new \WP_Error('rate_limited', __('API rate limit exceeded. Please try again later.', 'dropshipzone-sync'));
        }

        if ($response_code >= 400) {
            $error_message = isset($response_data['message']) ? $response_data['message'] : __('API request failed.', 'dropshipzone-sync');
            return new \WP_Error('api_error', $error_message, ['code' => $response_code, 'response' => $response_data]);
        }

        // Add small delay after successful request to prevent rate limiting
        // Only for product/stock endpoints, not auth
        if ($endpoint !== '/auth') {
            usleep(500000); // 0.5 second delay between requests
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
