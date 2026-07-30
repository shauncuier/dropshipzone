# Regression suites

Plain PHP harnesses — no PHPUnit, no WordPress bootstrap, no network. Each
suite installs its own WordPress and WooCommerce stubs as namespace-local
functions inside `Dropshipzone`, then loads the **real** file from
`includes/` and drives private methods through reflection. Assertions
therefore test the shipped code, not a copy of it.

## Running

```
php tests/run.php
```

Filter to one suite by name fragment:

```
php tests/run.php incremental
```

Suites can also be run directly (`php tests/sale-price-test.php`). The
runner uses a subprocess per suite because two suites cannot share a
process — they declare the same stub names in the same namespace.

Exit code is non-zero when any suite fails.

Any PHP 7.4+ binary works. On this machine, Local's bundled PHP:

```
"$env:APPDATA/Local/lightning-services/php-8.5.1+1/bin/win64/php.exe" tests/run.php
```

## Suites

| File | Covers | Assertions |
| --- | --- | --- |
| `sale-price-test.php` | `Cron::update_product_price()` — special starting, ending, repricing, promotion windows, non-discount specials, RRP capture, zero-cost payloads | 27 |
| `incremental-test.php` | `Cron::run_incremental_sync()` — cursor advance and clamping, pagination without `total_pages`, page cap, SKU intersection and de-duplication, lock handling, error retry; plus the EAN and brand guards in `update_product_catalog_fields()` | 46 |
| `memory-limit-test.php` | `dszsync_is_memory_near_limit()` — unlimited (`-1`) and empty limits, unit parsing, threshold boundary | 12 |

Total: 85.

## Notes

- Fixtures encode real API behaviour observed on 2026-07-28: `/stock` returns
  `total` / `page_no` / `limit` but **no** `total_pages`, and 74 of a
  100-product catalogue sample carry `eancode` of `"N/A"` with a brand of
  `"Does not apply"`. Do not "simplify" those cases away.
- `tests/` is excluded from the distribution zip by `build.ps1`.
- Adding a suite: name it `*-test.php`, end with `"{$pass} passed, {$fail} failed"`
  and `exit($fail > 0 ? 1 : 0)`. The runner picks it up automatically.
