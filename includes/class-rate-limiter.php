<?php
/**
 * Rate Limiter / API Load Balancer Class
 *
 * Handles API rate limiting and load balancing to comply with Dropshipzone API throttle limits:
 * - Maximum 60 requests per minute
 * - Maximum 600 requests per hour
 *
 * Features:
 * - Adaptive delay algorithm based on current usage
 * - Smart waiting to prevent rate limit errors
 * - Request statistics tracking
 * - Proactive throttling before limits are hit
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rate Limiter / API Load Balancer for Dropshipzone API
 */
class Rate_Limiter {

    /**
     * Maximum requests per minute
     */
    const MAX_PER_MINUTE = 60;

    /**
     * Maximum requests per hour
     */
    const MAX_PER_HOUR = 600;

    /**
     * Option name for storing rate limit data
     */
    const OPTION_NAME = 'dsz_rate_limit_data';

    /**
     * Minimum delay between requests (seconds) to prevent bursting
     */
    const MIN_DELAY = 0.5;

    /**
     * Safety buffer - start throttling at this percentage of limit
     */
    const THROTTLE_THRESHOLD = 0.8;

    /**
     * Logger instance
     */
    private $logger;

    /**
     * Rate limit data
     */
    private $data = [];

    /**
     * Last request timestamp (microseconds)
     */
    private $last_request_time = 0;

    /**
     * Constructor
     *
     * @param Logger|null $logger Logger instance
     */
    public function __construct($logger = null) {
        $this->logger = $logger;
        $this->load_data();
        $this->cleanup_old_entries();
    }

    /**
     * Load rate limit data from database
     */
    private function load_data() {
        $this->data = get_option(self::OPTION_NAME, [
            'requests' => [],
            'last_cleanup' => time(),
            'stats' => [
                'total_requests' => 0,
                'total_waits' => 0,
                'total_wait_time' => 0,
            ],
        ]);

        // Ensure structure
        if (!isset($this->data['requests'])) {
            $this->data['requests'] = [];
        }
        if (!isset($this->data['last_cleanup'])) {
            $this->data['last_cleanup'] = time();
        }
        if (!isset($this->data['stats'])) {
            $this->data['stats'] = [
                'total_requests' => 0,
                'total_waits' => 0,
                'total_wait_time' => 0,
            ];
        }
        if (!isset($this->data['last_request_time'])) {
            $this->data['last_request_time'] = 0;
        }
        
        $this->last_request_time = $this->data['last_request_time'];
    }

    /**
     * Save rate limit data to database
     */
    private function save_data() {
        $this->data['last_request_time'] = $this->last_request_time;
        update_option(self::OPTION_NAME, $this->data, false);
    }

    /**
     * Cleanup old entries (older than 1 hour)
     */
    private function cleanup_old_entries() {
        $one_hour_ago = time() - 3600;
        
        // Only cleanup every 5 minutes
        if (($this->data['last_cleanup'] + 300) > time()) {
            return;
        }

        $this->data['requests'] = array_filter($this->data['requests'], function($timestamp) use ($one_hour_ago) {
            return $timestamp > $one_hour_ago;
        });

        $this->data['last_cleanup'] = time();
        $this->save_data();
    }

    /**
     * Get count of requests in the last minute
     *
     * @return int
     */
    public function get_minute_count() {
        $one_minute_ago = time() - 60;
        return count(array_filter($this->data['requests'], function($timestamp) use ($one_minute_ago) {
            return $timestamp > $one_minute_ago;
        }));
    }

    /**
     * Get count of requests in the last hour
     *
     * @return int
     */
    public function get_hour_count() {
        $one_hour_ago = time() - 3600;
        return count(array_filter($this->data['requests'], function($timestamp) use ($one_hour_ago) {
            return $timestamp > $one_hour_ago;
        }));
    }

    /**
     * Get current usage percentages
     *
     * @return array
     */
    public function get_usage() {
        return [
            'minute_usage' => $this->get_minute_count() / self::MAX_PER_MINUTE,
            'hour_usage' => $this->get_hour_count() / self::MAX_PER_HOUR,
        ];
    }

    /**
     * Check if we can make a request
     *
     * @return bool
     */
    public function can_make_request() {
        return $this->get_minute_count() < self::MAX_PER_MINUTE 
            && $this->get_hour_count() < self::MAX_PER_HOUR;
    }

    /**
     * Get wait time until next request is allowed (in seconds)
     *
     * @return int Seconds to wait (0 if no wait needed)
     */
    public function get_wait_time() {
        // Check minute limit
        if ($this->get_minute_count() >= self::MAX_PER_MINUTE) {
            // Find oldest request within the last minute
            $one_minute_ago = time() - 60;
            $minute_requests = array_filter($this->data['requests'], function($timestamp) use ($one_minute_ago) {
                return $timestamp > $one_minute_ago;
            });
            
            if (!empty($minute_requests)) {
                $oldest = min($minute_requests);
                $wait = ($oldest + 60) - time() + 1; // +1 for safety
                
                if ($this->logger) {
                    $this->logger->debug('Rate limit: Minute limit reached', [
                        'minute_count' => count($minute_requests),
                        'wait_seconds' => $wait,
                    ]);
                }
                
                return max(1, $wait);
            }
        }

        // Check hour limit
        if ($this->get_hour_count() >= self::MAX_PER_HOUR) {
            // Find oldest request within the last hour
            $one_hour_ago = time() - 3600;
            $hour_requests = array_filter($this->data['requests'], function($timestamp) use ($one_hour_ago) {
                return $timestamp > $one_hour_ago;
            });
            
            if (!empty($hour_requests)) {
                $oldest = min($hour_requests);
                $wait = ($oldest + 3600) - time() + 1;
                
                if ($this->logger) {
                    $this->logger->warning('Rate limit: Hour limit reached', [
                        'hour_count' => count($hour_requests),
                        'wait_seconds' => $wait,
                    ]);
                }
                
                return max(1, $wait);
            }
        }

        return 0;
    }

    /**
     * Calculate adaptive delay based on current usage
     * 
     * This proactively slows down before hitting limits to ensure
     * smooth, continuous operation.
     *
     * @return float Seconds to delay (can be fractional)
     */
    public function get_adaptive_delay() {
        $usage = $this->get_usage();
        $minute_usage = $usage['minute_usage'];
        $hour_usage = $usage['hour_usage'];
        
        // Take the higher of the two usage percentages
        $max_usage = max($minute_usage, $hour_usage);
        
        // Critical zone (>90% of either limit)
        if ($max_usage > 0.9) {
            return 5.0;
        }
        
        // High usage zone (>80% of either limit) 
        if ($max_usage > 0.8) {
            return 3.0;
        }
        
        // Medium usage zone (>60% of either limit)
        if ($max_usage > 0.6) {
            return 2.0;
        }
        
        // Low usage zone (>40% of either limit)
        if ($max_usage > 0.4) {
            return 1.5;
        }
        
        // Normal zone - slight buffer
        return 1.0;
    }

    /**
     * Smart wait - combines rate limit wait and adaptive delay
     * 
     * This is the main method API client should call before each request.
     * It handles:
     * 1. Hard rate limit enforcement (if limits hit, wait until allowed)
     * 2. Adaptive delay (proactive throttling based on usage)
     * 3. Minimum delay enforcement (prevent bursting)
     *
     * @return array Wait info including time waited and reason
     */
    public function smart_wait() {
        $wait_info = [
            'waited' => 0,
            'reason' => 'none',
            'usage' => $this->get_usage(),
        ];
        
        // First check: Hard rate limit
        $hard_wait = $this->get_wait_time();
        if ($hard_wait > 0) {
            if ($this->logger) {
                $this->logger->info('Load balancer: Hard rate limit wait', [
                    'wait_seconds' => $hard_wait,
                    'minute_count' => $this->get_minute_count(),
                    'hour_count' => $this->get_hour_count(),
                ]);
            }
            
            sleep($hard_wait);
            $wait_info['waited'] = $hard_wait;
            $wait_info['reason'] = 'rate_limit';
            
            // Track stats
            $this->data['stats']['total_waits']++;
            $this->data['stats']['total_wait_time'] += $hard_wait;
            
            // Reload data in case another process made requests
            $this->load_data();
            
            return $wait_info;
        }
        
        // Second check: Adaptive delay based on usage
        $adaptive_delay = $this->get_adaptive_delay();
        
        // Third check: Minimum time since last request
        $time_since_last = microtime(true) - $this->last_request_time;
        $min_delay_remaining = max(0, self::MIN_DELAY - $time_since_last);
        
        // Use the larger of adaptive delay or minimum delay
        $actual_delay = max($adaptive_delay, $min_delay_remaining);
        
        if ($actual_delay > 0.1) { // Only delay if > 100ms
            if ($this->logger) {
                $this->logger->debug('Load balancer: Adaptive delay', [
                    'delay_seconds' => round($actual_delay, 2),
                    'adaptive_delay' => round($adaptive_delay, 2),
                    'min_delay_remaining' => round($min_delay_remaining, 2),
                    'usage' => $wait_info['usage'],
                ]);
            }
            
            usleep((int)($actual_delay * 1000000));
            $wait_info['waited'] = $actual_delay;
            $wait_info['reason'] = 'adaptive';
        }
        
        return $wait_info;
    }

    /**
     * Wait until we can make a request (legacy method)
     *
     * @param int $max_wait Maximum seconds to wait (default 120)
     * @return bool True if we can proceed, false if waited too long
     */
    public function wait_if_needed($max_wait = 120) {
        $wait_time = $this->get_wait_time();
        
        if ($wait_time <= 0) {
            return true;
        }

        if ($wait_time > $max_wait) {
            if ($this->logger) {
                $this->logger->error('Rate limit: Wait time exceeds maximum', [
                    'wait_seconds' => $wait_time,
                    'max_wait' => $max_wait,
                ]);
            }
            return false;
        }

        if ($this->logger) {
            $this->logger->info('Rate limit: Waiting before next request', [
                'wait_seconds' => $wait_time,
            ]);
        }

        sleep($wait_time);
        
        // Track stats
        $this->data['stats']['total_waits']++;
        $this->data['stats']['total_wait_time'] += $wait_time;
        
        // Reload data in case another process made requests
        $this->load_data();
        
        return true;
    }

    /**
     * Record a request
     */
    public function record_request() {
        $this->data['requests'][] = time();
        $this->data['stats']['total_requests']++;
        $this->last_request_time = microtime(true);
        $this->save_data();
    }

    /**
     * Get current rate limit status
     *
     * @return array
     */
    public function get_status() {
        $usage = $this->get_usage();
        
        return [
            'minute_count' => $this->get_minute_count(),
            'minute_limit' => self::MAX_PER_MINUTE,
            'minute_remaining' => max(0, self::MAX_PER_MINUTE - $this->get_minute_count()),
            'minute_usage_percent' => round($usage['minute_usage'] * 100, 1),
            'hour_count' => $this->get_hour_count(),
            'hour_limit' => self::MAX_PER_HOUR,
            'hour_remaining' => max(0, self::MAX_PER_HOUR - $this->get_hour_count()),
            'hour_usage_percent' => round($usage['hour_usage'] * 100, 1),
            'can_request' => $this->can_make_request(),
            'wait_time' => $this->get_wait_time(),
            'recommended_delay' => round($this->get_adaptive_delay(), 2),
        ];
    }

    /**
     * Get load balancer statistics
     *
     * @return array
     */
    public function get_stats() {
        return [
            'total_requests' => $this->data['stats']['total_requests'],
            'total_waits' => $this->data['stats']['total_waits'],
            'total_wait_time' => $this->data['stats']['total_wait_time'],
            'avg_wait_time' => $this->data['stats']['total_waits'] > 0 
                ? round($this->data['stats']['total_wait_time'] / $this->data['stats']['total_waits'], 2) 
                : 0,
        ];
    }

    /**
     * Reset rate limit data (for testing/emergency)
     */
    public function reset() {
        $this->data = [
            'requests' => [],
            'last_cleanup' => time(),
            'stats' => [
                'total_requests' => 0,
                'total_waits' => 0,
                'total_wait_time' => 0,
            ],
            'last_request_time' => 0,
        ];
        $this->last_request_time = 0;
        $this->save_data();
        
        if ($this->logger) {
            $this->logger->info('Rate limit data reset');
        }
    }

    /**
     * Get recommended delay between requests to stay within limits (legacy method)
     * 
     * @return float Seconds to delay (can be fractional)
     * @deprecated Use get_adaptive_delay() instead
     */
    public function get_recommended_delay() {
        return $this->get_adaptive_delay();
    }
}

