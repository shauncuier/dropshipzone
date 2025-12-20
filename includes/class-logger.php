<?php
/**
 * Logger Class
 *
 * Handles logging for the plugin with database storage
 *
 * @package Dropshipzone
 */

namespace Dropshipzone;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Logger class for database-based logging
 */
class Logger {

    /**
     * Log levels
     */
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_DEBUG = 'debug';

    /**
     * Database table name
     */
    private $table_name;

    /**
     * Maximum log entries to keep
     */
    private $max_logs = 5000;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dsz_sync_logs';
    }

    /**
     * Log a message
     *
     * @param string $level   Log level
     * @param string $message Log message
     * @param array  $context Additional context data
     * @return bool Success
     */
    public function log($level, $message, $context = []) {
        global $wpdb;

        // Validate level
        $valid_levels = [self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_DEBUG];
        if (!in_array($level, $valid_levels)) {
            $level = self::LEVEL_INFO;
        }

        // Prepare context for storage
        $context_json = !empty($context) ? wp_json_encode($context) : null;

        // Insert log entry
        $result = $wpdb->insert(
            $this->table_name,
            [
                'level' => $level,
                'message' => sanitize_text_field($message),
                'context' => $context_json,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s']
        );

        // Cleanup old logs periodically
        $this->maybe_cleanup();

        return $result !== false;
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public function info($message, $context = []) {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public function warning($message, $context = []) {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public function error($message, $context = []) {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log debug message
     *
     * @param string $message Log message
     * @param array  $context Additional context
     */
    public function debug($message, $context = []) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->log(self::LEVEL_DEBUG, $message, $context);
        }
    }

    /**
     * Get log entries
     *
     * @param array $args Query arguments
     * @return array Log entries
     */
    public function get_logs($args = []) {
        global $wpdb;

        $defaults = [
            'level' => '',
            'search' => '',
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        // Build query
        $where = [];
        $values = [];

        if (!empty($args['level'])) {
            $where[] = 'level = %s';
            $values[] = $args['level'];
        }

        if (!empty($args['search'])) {
            $where[] = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Sanitize orderby
        $allowed_orderby = ['id', 'level', 'message', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build full query
        $query = "SELECT * FROM {$this->table_name} {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = intval($args['limit']);
        $values[] = intval($args['offset']);

        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }

        $results = $wpdb->get_results($query, ARRAY_A);

        // Decode context for each result
        if ($results) {
            foreach ($results as &$row) {
                if (!empty($row['context'])) {
                    $row['context'] = json_decode($row['context'], true);
                }
            }
        }

        return $results ?: [];
    }

    /**
     * Get total log count
     *
     * @param string $level Filter by level (optional)
     * @return int Total count
     */
    public function get_count($level = '') {
        global $wpdb;

        if (!empty($level)) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE level = %s",
                $level
            ));
        } else {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        }

        return intval($count);
    }

    /**
     * Clear all logs
     *
     * @return bool Success
     */
    public function clear_logs() {
        global $wpdb;
        return $wpdb->query("TRUNCATE TABLE {$this->table_name}") !== false;
    }

    /**
     * Clear logs by level
     *
     * @param string $level Log level to clear
     * @return int|false Number of rows deleted or false
     */
    public function clear_by_level($level) {
        global $wpdb;
        return $wpdb->delete($this->table_name, ['level' => $level], ['%s']);
    }

    /**
     * Maybe cleanup old logs
     */
    private function maybe_cleanup() {
        // Only cleanup 1% of the time to reduce overhead
        if (mt_rand(1, 100) !== 1) {
            return;
        }

        $this->cleanup_old_logs();
    }

    /**
     * Cleanup old logs to maintain max limit
     */
    public function cleanup_old_logs() {
        global $wpdb;

        $count = $this->get_count();

        if ($count > $this->max_logs) {
            $delete_count = $count - $this->max_logs;
            
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table_name} ORDER BY created_at ASC LIMIT %d",
                $delete_count
            ));
        }
    }

    /**
     * Get log level badge HTML
     *
     * @param string $level Log level
     * @return string HTML badge
     */
    public static function get_level_badge($level) {
        $badges = [
            self::LEVEL_INFO => '<span class="dsz-badge dsz-badge-info">Info</span>',
            self::LEVEL_WARNING => '<span class="dsz-badge dsz-badge-warning">Warning</span>',
            self::LEVEL_ERROR => '<span class="dsz-badge dsz-badge-error">Error</span>',
            self::LEVEL_DEBUG => '<span class="dsz-badge dsz-badge-debug">Debug</span>',
        ];

        return isset($badges[$level]) ? $badges[$level] : '<span class="dsz-badge">' . esc_html($level) . '</span>';
    }

    /**
     * Export logs to CSV
     *
     * @param array $args Query arguments for filtering
     * @return string CSV content
     */
    public function export_csv($args = []) {
        $args['limit'] = 10000; // Max export
        $logs = $this->get_logs($args);

        $output = fopen('php://temp', 'r+');
        
        // CSV header
        fputcsv($output, ['ID', 'Level', 'Message', 'Context', 'Created At']);

        // Data rows
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['level'],
                $log['message'],
                is_array($log['context']) ? wp_json_encode($log['context']) : $log['context'],
                $log['created_at'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
