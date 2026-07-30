<?php
/**
 * Regression harness for Cron::run_incremental_sync().
 *
 * Drives the real class-cron.php against stubbed WordPress, API client and
 * mapper so the /stock window, cursor advance, pagination cap and SKU
 * intersection are exercised without a live site.
 *
 * Run with: php tests/incremental-test.php  (or tests/run.php for all suites)
 */

namespace Dropshipzone;

class WP_Error {
    private $code; private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
\class_alias('Dropshipzone\WP_Error', 'WP_Error');

\define('ABSPATH', \dirname(__DIR__) . '/');
\define('HOUR_IN_SECONDS', 3600);
\define('DAY_IN_SECONDS', 86400);
\define('MINUTE_IN_SECONDS', 60);

// --- option / transient store ---------------------------------------------
$GLOBALS['opts'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['calls'] = ['stock' => [], 'products' => []];

function get_option($k, $d = false) { return isset($GLOBALS['opts'][$k]) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return isset($GLOBALS['transients'][$k]) ? $GLOBALS['transients'][$k] : false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) {
    if ($tag === 'dszsync_incremental_max_pages' && isset($GLOBALS['max_pages_override'])) {
        return $GLOBALS['max_pages_override'];
    }
    return $value;
}
function do_action() {}
function wp_next_scheduled() { return false; }
function wp_schedule_event() {}
function wp_schedule_single_event() {}
function wp_clear_scheduled_hook() {}
function current_time() { return \gmdate('Y-m-d H:i:s'); }
function wp_get_object_terms() { return []; }
function wp_set_object_terms($id, $terms, $tax, $append = false) {
    $GLOBALS['brand_terms'][] = $terms;
    return [1];
}
function taxonomy_exists() { return !empty($GLOBALS['brands_active']); }
function get_post_meta() { return ''; }
function memory_get_usage($real = false) { return 65011712; }
function ini_get($k) { return $k === 'memory_limit' ? $GLOBALS['mem_limit'] : \ini_get($k); }
function wp_convert_hr_to_bytes($v) {
    $v = strtolower(trim((string) $v));
    $bytes = (int) $v;
    if (str_contains($v, 'g')) { $bytes *= 1073741824; }
    elseif (str_contains($v, 'm')) { $bytes *= 1048576; }
    elseif (str_contains($v, 'k')) { $bytes *= 1024; }
    return $bytes;
}
function update_post_meta() { return true; }
function sanitize_text_field($s) { return $s; }
function is_wp_error($t) { return ($t instanceof WP_Error); }
function dszsync_is_memory_near_limit() { return false; }
function dszsync_get_api_cost($d) {
    $c = isset($d['cost']) ? (float) $d['cost'] : 0;
    return $c > 0 ? $c : (isset($d['price']) ? (float) $d['price'] : 0);
}
function __($s) { return $s; }

// --- doubles ---------------------------------------------------------------
class Logger {
    public function info() {} public function debug() {}
    public function warning() {} public function error() {}
}
class Stock_Sync {
    public function get_rules() {
        return [
            'deactivate_if_not_found' => false,
            'buffer_enabled' => false,
            'buffer_amount' => 0,
            'zero_on_unavailable' => false,
            'auto_out_of_stock' => true,
            'republish_on_restock' => false,
        ];
    }
    public function calculate_stock($qty) { return max(0, intval($qty)); }
}
class Price_Sync {
    public function calculate_price($cost, $ctx = []) { return \round($cost * 1.5, 2); }
}

class FakeProduct {
    public $id; public $regular_price = '0'; public $sale_price = '';
    public $stock = 0; public $meta = [];
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_regular_price() { return $this->regular_price; }
    public function set_regular_price($v) { $this->regular_price = (string) $v; }
    public function get_sale_price() { return $this->sale_price; }
    public function set_sale_price($v) { $this->sale_price = (string) $v; }
    public function set_date_on_sale_from($v) {} public function set_date_on_sale_to($v) {}
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_stock_quantity() { return $this->stock; }
    public function set_stock_quantity($v) { $this->stock = $v; }
    public function get_manage_stock() { return true; }
    public function set_manage_stock($v) {}
    public function get_stock_status() { return 'instock'; }
    public function set_stock_status($v) {}
    public function get_status() { return 'publish'; }
    public function set_status($v) {}
    public $gtin = '';
    public function get_global_unique_id() { return $this->gtin; }
    public function set_global_unique_id($v) { $this->gtin = (string) $v; }
    public function save() { $GLOBALS['saved'][] = $this->id; return $this->id; }
}

function wc_get_product($id) {
    if (!isset($GLOBALS['products'][$id])) {
        $GLOBALS['products'][$id] = new FakeProduct($id);
    }
    return $GLOBALS['products'][$id];
}

/** Scripted API client. $GLOBALS['stock_pages'] drives /stock responses. */
class FakeApiClient {
    public function get_stock($skus, $start, $end, $page, $limit) {
        $GLOBALS['calls']['stock'][] = ['start' => $start, 'end' => $end, 'page' => $page];

        if (isset($GLOBALS['stock_error'])) {
            return new WP_Error('boom', $GLOBALS['stock_error']);
        }

        $pages = $GLOBALS['stock_pages'];
        $idx = $page - 1;

        $rows = isset($pages[$idx]) ? $pages[$idx] : [];

        if (!empty($GLOBALS['emit_total_pages'])) {
            return ['result' => $rows, 'total_pages' => count($pages)];
        }

        // Live shape, confirmed against the API on 2026-07-28: the /stock
        // response carries total, page_no and limit but no total_pages.
        $total = 0;
        foreach ($pages as $pg) { $total += count($pg); }

        return [
            'result'  => $rows,
            'total'   => $total,
            'page_no' => $page,
            'limit'   => 1,
        ];
    }

    public function get_products_by_skus($skus) {
        $GLOBALS['calls']['products'][] = $skus;
        $out = [];
        foreach ($skus as $sku) {
            $out[] = ['sku' => $sku, 'cost' => 100, 'stock_qty' => 5, 'status' => 'In Stock'];
        }
        return ['result' => $out];
    }
}

class FakeMapper {
    public function get_syncable_by_skus($skus) {
        $rows = [];
        foreach ($skus as $sku) {
            if (isset($GLOBALS['mapped'][$sku])) {
                $rows[] = ['wc_product_id' => $GLOBALS['mapped'][$sku], 'dsz_sku' => $sku];
            }
        }
        return $rows;
    }
    public function update_last_synced($id) { return true; }
}

class FakePlugin {
    public $api_client; public $product_mapper;
    public function __construct() {
        $this->api_client = new FakeApiClient();
        $this->product_mapper = new FakeMapper();
    }
}

function dszsync_sync() { return $GLOBALS['plugin']; }

require \dirname(__DIR__) . '/includes/class-cron.php';

// --- harness ---------------------------------------------------------------
$pass = 0; $fail = 0;
function check($label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    echo "FAIL: {$label}\n  expected: " . \var_export($expected, true)
        . "\n  actual:   " . \var_export($actual, true) . "\n";
}

function reset_world() {
    $GLOBALS['opts'] = ['dszsync_settings' => ['incremental_enabled' => true]];
    $GLOBALS['transients'] = [];
    $GLOBALS['calls'] = ['stock' => [], 'products' => []];
    $GLOBALS['products'] = [];
    $GLOBALS['saved'] = [];
    $GLOBALS['mapped'] = [];
    $GLOBALS['stock_pages'] = [];
    $GLOBALS['brand_terms'] = [];
    $GLOBALS['brands_active'] = true;
    $GLOBALS['mem_limit'] = '256M';
    $GLOBALS['plugin'] = new FakePlugin();
    unset($GLOBALS['stock_error'], $GLOBALS['max_pages_override'], $GLOBALS['emit_total_pages']);
}

$ref = new \ReflectionClass(Cron::class);
$cron = $ref->newInstanceWithoutConstructor();
foreach (['price_sync' => new Price_Sync(), 'stock_sync' => new Stock_Sync(), 'logger' => new Logger()] as $prop => $val) {
    $p = $ref->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($cron, $val);
}

echo "=== Cron::run_incremental_sync() regression ===\n\n";

// 1. Disabled by default — no API traffic.
reset_world();
$GLOBALS['opts']['dszsync_settings'] = [];
$r = $cron->run_incremental_sync();
check('1a disabled: status', $r['status'], 'skipped');
check('1b disabled: no /stock call', count($GLOBALS['calls']['stock']), 0);

// 2. Full sweep holds the lock — incremental stands down.
reset_world();
set_transient('dszsync_batch_lock', 1);
$r = $cron->run_incremental_sync();
check('2a locked: status', $r['status'], 'skipped');
check('2b locked: no /stock call', count($GLOBALS['calls']['stock']), 0);

// 3. Only mapped SKUs are fetched from /v2/products.
reset_world();
$GLOBALS['stock_pages'] = [[
    ['sku' => 'AAA', 'new_qty' => 3],
    ['sku' => 'BBB', 'new_qty' => 0],
    ['sku' => 'ZZZ', 'new_qty' => 7],
]];
$GLOBALS['mapped'] = ['AAA' => 11, 'BBB' => 22];
$r = $cron->run_incremental_sync();
check('3a status', $r['status'], 'complete');
check('3b one products call', count($GLOBALS['calls']['products']), 1);
check('3c unmapped SKU not requested', $GLOBALS['calls']['products'][0], ['AAA', 'BBB']);
check('3d both products refreshed', $r['refreshed'], 2);
check('3e cursor advanced', isset($GLOBALS['opts']['dszsync_incremental_state']['window_end_ts']), true);
check('3f lock released', get_transient('dszsync_incremental_lock'), false);

// 4. Changed SKUs this store does not map cost nothing beyond the /stock read.
reset_world();
$GLOBALS['stock_pages'] = [[['sku' => 'NOPE']]];
$r = $cron->run_incremental_sync();
check('4a no products call', count($GLOBALS['calls']['products']), 0);
check('4b nothing refreshed', $r['refreshed'], 0);

// 5. Duplicate SKUs across change rows are de-duplicated.
reset_world();
$GLOBALS['stock_pages'] = [[
    ['sku' => 'AAA'], ['sku' => 'AAA'], ['sku' => 'AAA'],
]];
$GLOBALS['mapped'] = ['AAA' => 11];
$r = $cron->run_incremental_sync();
check('5 SKU requested once', $GLOBALS['calls']['products'][0], ['AAA']);

// 6. Pagination is followed to the end.
reset_world();
$GLOBALS['stock_pages'] = [
    [['sku' => 'A1']], [['sku' => 'A2']], [['sku' => 'A3']],
];
$GLOBALS['mapped'] = ['A1' => 1, 'A2' => 2, 'A3' => 3];
$r = $cron->run_incremental_sync();
check('6a all pages read', count($GLOBALS['calls']['stock']), 3);
check('6b all refreshed', $r['refreshed'], 3);

// 7. The page cap stops a runaway window.
reset_world();
$GLOBALS['max_pages_override'] = 2;
$GLOBALS['stock_pages'] = [
    [['sku' => 'A1']], [['sku' => 'A2']], [['sku' => 'A3']], [['sku' => 'A4']],
];
$GLOBALS['mapped'] = ['A1' => 1, 'A2' => 2, 'A3' => 3, 'A4' => 4];
$r = $cron->run_incremental_sync();
check('7a capped at 2 pages', count($GLOBALS['calls']['stock']), 2);
check('7b only capped SKUs refreshed', $r['refreshed'], 2);

// 8. A /stock failure leaves the cursor alone so the window is retried.
reset_world();
$GLOBALS['opts']['dszsync_incremental_state'] = ['window_end_ts' => 1000, 'last_run' => 1000];
$GLOBALS['stock_error'] = 'gateway timeout';
$r = $cron->run_incremental_sync();
check('8a status', $r['status'], 'error');
check('8b cursor unchanged', $GLOBALS['opts']['dszsync_incremental_state']['window_end_ts'], 1000);
check('8c lock released', get_transient('dszsync_incremental_lock'), false);

// 9. Cursor resumes from the previous window end, not a fixed hour.
reset_world();
$two_days_ago = time() - (2 * DAY_IN_SECONDS);
$GLOBALS['opts']['dszsync_incremental_state'] = ['window_end_ts' => $two_days_ago];
$GLOBALS['stock_pages'] = [[]];
$cron->run_incremental_sync();
check('9 window starts at the stored cursor',
    $GLOBALS['calls']['stock'][0]['start'], gmdate('Y-m-d H:i:s', $two_days_ago));

// 10. A cursor older than the API's 10-day cap is clamped, not passed through.
reset_world();
$GLOBALS['opts']['dszsync_incremental_state'] = ['window_end_ts' => time() - (40 * DAY_IN_SECONDS)];
$GLOBALS['stock_pages'] = [[]];
$cron->run_incremental_sync();
$span = strtotime($GLOBALS['calls']['stock'][0]['end']) - strtotime($GLOBALS['calls']['stock'][0]['start']);
check('10a window under the 10-day limit', $span < (10 * DAY_IN_SECONDS), true);
check('10b window is the full 9 days', $span, 9 * DAY_IN_SECONDS);

// 11. First run with no cursor polls the last hour.
reset_world();
$GLOBALS['stock_pages'] = [[]];
$cron->run_incremental_sync();
$span = strtotime($GLOBALS['calls']['stock'][0]['end']) - strtotime($GLOBALS['calls']['stock'][0]['start']);
check('11 first run polls one hour', $span, HOUR_IN_SECONDS);

// 12. Page count derived from total/limit when total_pages is absent — which
//     is what the live /stock endpoint actually returns. Assuming total_pages
//     meant reading page 1 only and dropping the rest of the window.
reset_world();
$GLOBALS['stock_pages'] = [
    [['sku' => 'B1']], [['sku' => 'B2']], [['sku' => 'B3']], [['sku' => 'B4']],
];
$GLOBALS['mapped'] = ['B1' => 1, 'B2' => 2, 'B3' => 3, 'B4' => 4];
$r = $cron->run_incremental_sync();
check('12a all pages read without total_pages', count($GLOBALS['calls']['stock']), 4);
check('12b every changed SKU refreshed', $r['refreshed'], 4);

// 13. A response that does carry total_pages is still honoured.
reset_world();
$GLOBALS['emit_total_pages'] = true;
$GLOBALS['stock_pages'] = [[['sku' => 'C1']], [['sku' => 'C2']]];
$GLOBALS['mapped'] = ['C1' => 1, 'C2' => 2];
$r = $cron->run_incremental_sync();
check('13 total_pages still respected', $r['refreshed'], 2);

// --- catalogue field guards -----------------------------------------------
// Live catalogue sample (100 products, 2026-07-28): eancode is the literal
// string "N/A" on 74 of them, and brand is "Does not apply" on the same 74.
// Without guards the sync writes a bogus GTIN and files three quarters of the
// catalogue under a brand that is not a brand.

$catalog = $ref->getMethod('update_product_catalog_fields');
$catalog->setAccessible(true);

function field_run($cron, $catalog, array $api) {
    reset_world();
    $p = new FakeProduct(99);
    $changed = $catalog->invoke($cron, $p, $api);
    return ['product' => $p, 'changed' => $changed, 'terms' => $GLOBALS['brand_terms']];
}

echo "\n--- EAN guard ---\n";
foreach ([
    ['12345678', true, '8 digits'],
    ['123456789012', true, '12 digits'],
    ['9350062860571', true, '13 digits'],
    ['78632227560495', true, '14 digits'],
    ['N/A', false, 'literal N/A (74% of the catalogue)'],
    ['6907596137122011', false, '16 digits'],
    ['123', false, '3 digits'],
    ['', false, 'empty'],
] as $i => $case) {
    list($ean, $expect, $label) = $case;
    $r = field_run($cron, $catalog, ['eancode' => $ean]);
    check("E" . ($i + 1) . " {$label}", $r['product']->gtin !== '', $expect);
}

echo "\n--- brand guard ---\n";
foreach ([
    ['Rigo', true, 'real brand'],
    ['Jingle Jollys', true, 'real brand with a space'],
    ['Does not apply', false, 'marketplace filler (74% of the catalogue)'],
    ['does not apply', false, 'filler, lower case'],
    ['N/A', false, 'N/A'],
    ['Unbranded', false, 'Unbranded'],
    ['none', false, 'none'],
    ['-', false, 'dash'],
    ['  ', false, 'whitespace only'],
] as $i => $case) {
    list($brand, $expect, $label) = $case;
    $r = field_run($cron, $catalog, ['brand' => $brand]);
    check("B" . ($i + 1) . " {$label}", !empty($r['terms']), $expect);
}

echo "\n--- guards do not fire without WooCommerce support ---\n";
reset_world();
$GLOBALS['brands_active'] = false;
$p = new FakeProduct(99);
$catalog->invoke($cron, $p, ['brand' => 'Rigo', 'eancode' => '9350062860571']);
check('G1 brand skipped when the taxonomy is absent', empty($GLOBALS['brand_terms']), true);
check('G2 GTIN still set (WooCommerce supports it)', $p->gtin, '9350062860571');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
