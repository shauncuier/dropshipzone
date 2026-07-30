<?php
/**
 * Regression harness for Cron::update_product_price() sale-price handling.
 *
 * Loads the real includes/class-cron.php against WordPress/WooCommerce stubs
 * and drives update_product_price() through reflection, so the assertions test
 * the shipped code rather than a copy of it.
 *
 * Run with: php tests/sale-price-test.php  (or tests/run.php for all suites)
 */

namespace Dropshipzone;

\define('ABSPATH', \dirname(__DIR__) . '/');

// --- WordPress stubs (namespace-local; unqualified calls resolve here) -----
function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) { return $value; }
function do_action() {}
function wp_next_scheduled() { return false; }
function dszsync_get_api_cost($api_data) {
    $cost = isset($api_data['cost']) ? (float) $api_data['cost'] : 0;
    if ($cost <= 0) {
        $cost = isset($api_data['price']) ? (float) $api_data['price'] : 0;
    }
    return $cost;
}

// --- Constructor dependencies ---------------------------------------------
class Logger {
    public function info() {}
    public function debug() {}
    public function warning() {}
    public function error() {}
}

class Stock_Sync {}

class Price_Sync {
    /** Flat 1.5x markup — proves ordering, not the rules engine. */
    public function calculate_price($cost, $context = []) {
        return \round($cost * 1.5, 2);
    }
}

/** Minimal WC_Product stand-in recording the state the sync sets. */
class FakeProduct {
    public $regular_price = '';
    public $sale_price = '';
    public $date_from = '';
    public $date_to = '';
    public $meta = [];
    public $_returned = null;

    public function get_id() { return 42; }
    public function get_regular_price() { return $this->regular_price; }
    public function set_regular_price($v) { $this->regular_price = (string) $v; }
    public function get_sale_price() { return $this->sale_price; }
    public function set_sale_price($v) { $this->sale_price = (string) $v; }
    public function set_date_on_sale_from($v) { $this->date_from = $v; }
    public function set_date_on_sale_to($v) { $this->date_to = $v; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
}

require \dirname(__DIR__) . '/includes/class-cron.php';

// --- Harness ---------------------------------------------------------------
$reflection = new \ReflectionClass(Cron::class);
$cron = $reflection->newInstanceWithoutConstructor();

$price_sync_prop = $reflection->getProperty('price_sync');
$price_sync_prop->setAccessible(true);
$price_sync_prop->setValue($cron, new Price_Sync());

$logger_prop = $reflection->getProperty('logger');
$logger_prop->setAccessible(true);
$logger_prop->setValue($cron, new Logger());

$method = $reflection->getMethod('update_product_price');
$method->setAccessible(true);

$pass = 0;
$fail = 0;

function check($label, $actual, $expected) {
    global $pass, $fail;
    $ok = ($actual === $expected);
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL: {$label}\n  expected: " . \var_export($expected, true)
            . "\n  actual:   " . \var_export($actual, true) . "\n";
    }
}

/**
 * @param array $state    Starting product state
 * @param array $api      API payload
 * @return FakeProduct
 */
function run($cron, $method, array $state, array $api) {
    $p = new FakeProduct();
    foreach ($state as $k => $v) {
        $p->$k = $v;
    }
    $p->_returned = $method->invoke($cron, $p, $api);
    return $p;
}

echo "=== Cron::update_product_price() sale-price regression ===\n\n";

// 1. Special starts while the supplier cost is unchanged.
//    This is the case the old early return broke entirely.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => ''],
    ['cost' => 100, 'special_price' => 80]
);
check('1a special starts: sale price set', $p->sale_price, '120');
check('1b special starts: regular untouched', $p->regular_price, '150.00');
check('1c special starts: returns changed', $p->_returned, true);

// 2. Special ends — cost unchanged, no special_price in the payload.
//    Old code left the stale sale price in place forever.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => '120', 'date_to' => 12345],
    ['cost' => 100]
);
check('2a special ends: sale price cleared', $p->sale_price, '');
check('2b special ends: sale-to date cleared', $p->date_to, '');
check('2c special ends: returns changed', $p->_returned, true);

// 3. Special unchanged and cost unchanged — genuinely nothing to do.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => '120'],
    ['cost' => 100, 'special_price' => 80]
);
check('3a steady state: sale price held', $p->sale_price, '120');
check('3b steady state: returns unchanged', $p->_returned, false);

// 4. Special changes value while the cost holds.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => '120'],
    ['cost' => 100, 'special_price' => 70]
);
check('4a special repriced: sale price updated', $p->sale_price, '105');
check('4b special repriced: returns changed', $p->_returned, true);

// 5. Promotion window carried from the supplier dates.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => ''],
    [
        'cost' => 100,
        'special_price' => 80,
        'special_price_from_date' => '2026-08-01 00:00:00',
        'special_price_end_date'  => '2026-08-31 23:59:59',
    ]
);
check('5a window: from date set', $p->date_from, \strtotime('2026-08-01 00:00:00'));
check('5b window: to date set', $p->date_to, \strtotime('2026-08-31 23:59:59'));

// 6. Special at or above the calculated regular price is not a discount.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => ''],
    ['cost' => 100, 'special_price' => 100]
);
check('6a non-discount special ignored', $p->sale_price, '');
check('6b non-discount special: returns unchanged', $p->_returned, false);

// 7. Regular price moves and a special is active — both apply.
$p = run($cron, $method,
    ['regular_price' => '150.00', 'sale_price' => ''],
    ['cost' => 120, 'special_price' => 90]
);
check('7a cost rise: regular updated', $p->regular_price, '180');
check('7b cost rise: sale applied', $p->sale_price, '135');
check('7c cost rise: returns changed', $p->_returned, true);

// 8. special_price of 0 / null is treated as no special.
foreach ([0, null, '', '0'] as $i => $empty) {
    $p = run($cron, $method,
        ['regular_price' => '150.00', 'sale_price' => '120'],
        ['cost' => 100, 'special_price' => $empty]
    );
    check("8." . ($i + 1) . " empty special (" . \var_export($empty, true) . ") clears sale", $p->sale_price, '');
}

// 9. RRP capture from both response shapes.
$p = run($cron, $method, ['regular_price' => '150.00'], ['cost' => 100, 'RrpPrice' => 249.95]);
check('9a RrpPrice stored', $p->meta['_dszsync_rrp'], 249.95);
$p = run($cron, $method, ['regular_price' => '150.00'], ['cost' => 100, 'RRP' => ['Standard' => 199.0]]);
check('9b RRP.Standard stored', $p->meta['_dszsync_rrp'], 199.0);
$p = run($cron, $method, ['regular_price' => '150.00'], ['cost' => 100]);
check('9c no RRP: meta absent', isset($p->meta['_dszsync_rrp']), false);

// 10. Cost of zero is a bad payload — bail without touching anything.
$p = run($cron, $method, ['regular_price' => '150.00', 'sale_price' => '120'], ['cost' => 0, 'price' => 0]);
check('10a zero cost: regular untouched', $p->regular_price, '150.00');
check('10b zero cost: sale untouched', $p->sale_price, '120');
check('10c zero cost: returns unchanged', $p->_returned, false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
