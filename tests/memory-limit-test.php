<?php
/**
 * Regression harness for dszsync_is_memory_near_limit().
 *
 * The real helpers.php is loaded; ini_get() and memory_get_usage() are
 * shadowed in the plugin's namespace so the limit can be varied.
 *
 * Run with: php tests/memory-limit-test.php  (or tests/run.php for all suites)
 */

namespace Dropshipzone;

\define('ABSPATH', \dirname(__DIR__) . '/');

$GLOBALS['mem_limit'] = '256M';
$GLOBALS['mem_usage'] = 65011712; // 62 MB, what WP-CLI actually reported

function ini_get($key) {
    return ($key === 'memory_limit') ? $GLOBALS['mem_limit'] : \ini_get($key);
}
function memory_get_usage($real = false) { return $GLOBALS['mem_usage']; }

// WordPress core helper, reproduced (wp-includes/load.php)
function wp_convert_hr_to_bytes($value) {
    $value = \strtolower(\trim((string) $value));
    $bytes = (int) $value;
    if (\str_contains($value, 'g')) { $bytes *= 1024 * 1024 * 1024; }
    elseif (\str_contains($value, 'm')) { $bytes *= 1024 * 1024; }
    elseif (\str_contains($value, 'k')) { $bytes *= 1024; }
    return \min($bytes, PHP_INT_MAX);
}

// Stubs for the rest of helpers.php (definitions only at include time)
function __($s) { return $s; }
function esc_html($s) { return $s; }
function apply_filters($t, $v) { return $v; }
function get_option($k, $d = false) { return $d; }
function human_time_diff($a, $b = 0) { return ''; }
function current_time($t) { return \time(); }
function sanitize_text_field($s) { return $s; }
function sanitize_key($s) { return $s; }
function wp_kses_post($s) { return $s; }
function absint($n) { return \abs((int) $n); }

require \dirname(__DIR__) . '/includes/helpers.php';

$pass = 0; $fail = 0;
function check($label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    echo "FAIL: {$label}\n  expected: " . \var_export($expected, true)
        . "\n  actual:   " . \var_export($actual, true) . "\n";
}

echo "=== dszsync_is_memory_near_limit() regression ===\n\n";

// The bug: memory_limit = -1 means "no limit", which is the WP-CLI default
// and common on managed hosts. The old regex left the value at -1, making
// the threshold negative, so every call reported "near the limit" and every
// batch loop broke after one item.
$GLOBALS['mem_limit'] = '-1';
$GLOBALS['mem_usage'] = 65011712;
check('1 unlimited (-1) never throttles', dszsync_is_memory_near_limit(85), false);

$GLOBALS['mem_limit'] = '0';
check('2 zero limit never throttles', dszsync_is_memory_near_limit(85), false);

$GLOBALS['mem_limit'] = '';
check('3 empty limit never throttles', dszsync_is_memory_near_limit(85), false);

// Real limits still throttle correctly
$GLOBALS['mem_limit'] = '256M';
$GLOBALS['mem_usage'] = 62 * 1024 * 1024;   // ~24%
check('4 256M at 24% is fine', dszsync_is_memory_near_limit(85), false);

$GLOBALS['mem_usage'] = 230 * 1024 * 1024;  // ~90%
check('5 256M at 90% throttles', dszsync_is_memory_near_limit(85), true);

// ceil, not cast: (int) truncates to just below the threshold and the
// comparison is >=, so a cast would test the wrong side of the boundary.
$GLOBALS['mem_usage'] = (int) \ceil(256 * 1024 * 1024 * 0.85);
check('6 exactly at the threshold throttles', dszsync_is_memory_near_limit(85), true);

$GLOBALS['mem_usage'] = (int) (256 * 1024 * 1024 * 0.85) - 1;
check('6b just under the threshold does not', dszsync_is_memory_near_limit(85), false);

// Unit parsing
$GLOBALS['mem_limit'] = '1G';
$GLOBALS['mem_usage'] = 900 * 1024 * 1024;
check('7 1G at 88% throttles', dszsync_is_memory_near_limit(85), true);

$GLOBALS['mem_usage'] = 100 * 1024 * 1024;
check('8 1G at 10% is fine', dszsync_is_memory_near_limit(85), false);

$GLOBALS['mem_limit'] = '524288K';
$GLOBALS['mem_usage'] = 500 * 1024 * 1024;
check('9 K suffix parsed', dszsync_is_memory_near_limit(85), true);

// A bare byte count carries no unit
$GLOBALS['mem_limit'] = '134217728';
$GLOBALS['mem_usage'] = 130 * 1024 * 1024;
check('10 bare byte count parsed', dszsync_is_memory_near_limit(85), true);

$GLOBALS['mem_usage'] = 10 * 1024 * 1024;
check('11 bare byte count, low usage', dszsync_is_memory_near_limit(85), false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
