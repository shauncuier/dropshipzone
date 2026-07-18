# Roadmap Master Plan — implement "all" from README

## Context

README.md carries a large roadmap (High/Medium/Low priority + Technical) plus a Features/Hooks section. Audit found: (a) the 4 documented developer hooks (`dsz_calculated_price`, `dsz_calculated_stock`, `dsz_sync_completed`, `dsz_price_updated`) **do not exist in code** — docs lie today; (b) README references the deleted `assets/banner-1544x500.png` and stale v2.0.0 install steps; (c) ~25 roadmap items unimplemented. Goal: implement every feasible roadmap item in phased milestones, fix the README to match reality at each step.

## Feasibility verdicts — excluded items (say why, don't pretend)

| Item | Verdict |
|---|---|
| Webhook Support | **Blocked** — DSZ offers no webhooks. Substitute: faster polling in Tracking Sync. Keep on roadmap as "waiting on DSZ". |
| Auto-Repricing (competitor analysis) | **Dropped** — no competitor data source exists in the API. |
| Multi-supplier Support | **Dropped** — different product; architecture rewrite. |
| Multi-currency | **Dropped** — API is AUD-only; currency plugins handle display conversion. |
| Redis/Memcached layer | **Already effectively done** — all caching uses transients, which are object-cache backed when Redis drop-in present. Document, close. |
| WooCommerce Blocks | **No-op** — plugin has no frontend render surface. Close. |

Everything else ships, in 5 milestones below.

---

## Milestone 1 (v2.8.0) — Truth + quick wins

1. **Developer hooks (README already documents them — make them real):**
   - `apply_filters('dsz_calculated_price', $price, $product_id, $supplier_price)` — in `Price_Sync::calculate_price()` call sites where product context exists ([class-cron.php](D:/Plugin Development/dropshipzone/includes/class-cron.php) `update_product_price`, [class-price-sync.php](D:/Plugin Development/dropshipzone/includes/class-price-sync.php) `sync_product_price`, importer paths).
   - `apply_filters('dsz_calculated_stock', $stock, $product_id, $supplier_stock)` — mirror in stock paths.
   - `do_action('dsz_sync_completed', $stats)` — in `Cron::complete_sync()`.
   - `do_action('dsz_price_updated', $product_id, $old_price, $new_price)` — cron + price-sync update points.
2. **DB indexes** (bump `dsz_mapping_schema_version` → 3): `dsz_product_mapping` add index on `dsz_sku`, composite `(sync_enabled, last_synced)`; `dsz_sync_logs` index `(level, created_at)`. Use `maybe_add_*` migration pattern already in [class-product-mapper.php](D:/Plugin Development/dropshipzone/includes/class-product-mapper.php).
3. **Scheduled maintenance**: daily cron `dsz_maintenance_hook` — delete logs older than N days (setting, default 30; replaces the 1%-probabilistic cleanup), remove orphaned mappings (product deleted), purge expired `dsz_rates_*`/`dsz_zone_*` transients.
4. **Supplier blacklist**: `exclude_supplier_ids` (API-native param, ≤50 ids) — Auto Import setting + Import page filter field.
5. **Import templates**: named filter presets stored in `dsz_import_templates` option; save/load dropdown on Import + Auto Import pages.
6. **Export tools**: CSV export of mappings (reuse the CSV pattern from `Logger` export, [class-logger.php](D:/Plugin Development/dropshipzone/includes/class-logger.php) ~302) + products-with-DSZ-data export.
7. **README/readme.txt corrections**: drop broken banner image, fix v2.0.0 install refs, roadmap table statuses, hooks section verified accurate.

## Milestone 2 (v2.9.0) — Pricing engine

1. **Advanced Price Rules** (absorbs "Markup by Category"): ordered rule list, first match wins, fallback = current global rules.
   - Match dimensions: DSZ category (l1/l2/l3 id or path prefix — fields already in product payloads), supplier (`vendor_id`), SKU prefix.
   - Per-rule: markup type/value, GST mode, rounding — same shape as existing rules.
   - Refactor `Price_Sync::calculate_price($cost)` → `calculate_price($cost, $context = [])` where context carries api_data; update all call sites (cron, importer, resync, preview AJAX). Storage `dsz_price_rules_v2` with silent migration from `dsz_sync_price_rules`.
   - UI: rule repeater on Price Rules page + per-rule live preview (reuse existing preview AJAX).
2. **Profit foundation**: persist `_dsz_cost` meta on every import/sync price write (uses `dsz_get_api_cost()`, [helpers.php](D:/Plugin Development/dropshipzone/includes/helpers.php)).

## Milestone 3 (v3.0.0) — Orders

1. **Tracking Number Sync**: cron twice daily → `API_Client::get_orders()` for serials of submitted orders (≤14-day windows, respect documented range cap) → store tracking/consignment into order meta `_dsz_tracking`, add order note, optional WC customer email, optional auto-complete order on DSZ "complete". **First step: live-verify `/orders` response fields — docs omit the response schema** (log one raw response behind WP_DEBUG).
2. **Bulk Order Submission**: orders-list bulk action (HPOS + legacy screens) + "Submit all pending" tool in Sync Center; chunked like resync (5 orders/request, JS continuation, rate-limit retry via existing `retry_after` plumbing).
3. **Auto-submit option** (off by default): submit to DSZ on `woocommerce_order_status_processing` — the true middleman flow.
4. **Profit Calculator**: order line + order total profit (price − `_dsz_cost` − DSZ shipping estimate) as order meta box addition; margin column on products list; dashboard stat card.

## Milestone 4 (v3.1.0) — Notifications, variations, import UX

1. **Email Notifications** (+ absorbs Inventory Alerts): settings (recipient, per-event toggles) — sync failure (immediate), daily digest (sync summary, auto-import results, low-stock list under threshold, price changes > X%). Collector pattern: events appended to an option during runs, flushed by daily cron via `wp_mail`.
2. **Product Variations**: mapping UI can target variations (WC product search returns variations; mapper table already keyed by any wc id; shipping already matches variation ids). Sync path: `wc_get_product` on a variation id works today — verify price/stock setters, add variation label in mapping table.
3. **Product Compare**: mapping row action → modal diffing WC vs live DSZ data (price, stock, title, image count) — one `get_products_by_skus` call.
4. **Import Scheduling**: auto-import "daily at HH:MM" (timestamp-aligned `wp_schedule_event`) alongside existing intervals.
5. **Category Mapping**: `dsz_category_map` option (DSZ path → WC term id) + management UI on Import settings; `Product_Importer::create_categories()` consults map before creating terms.
6. **Auto-Discontinue polish**: existing `deactivate_if_not_found` reported in sync results + dashboard counter (mechanism already exists — surface it).

## Milestone 5 (v3.2.0) — Platform

1. **Background Processing**: when Action Scheduler exists (WC bundles it), run scheduled sync batches / resync-all / auto-import as AS jobs (progress written to the same options the UI polls). Current chunked-AJAX + cron-continuation paths stay as fallback — additive, no regression risk.
2. **REST API**: `dsz/v1` — `GET /status`, `POST /sync`, `POST /import`, `GET /mappings`; `permission_callback` = `dsz_current_user_can_manage` ([helpers.php](D:/Plugin Development/dropshipzone/includes/helpers.php)); auth via application passwords.
3. **WP-CLI**: `wp dsz sync|import|status|map` behind `defined('WP_CLI')` guard.
4. **Sync Analytics Dashboard**: aggregate `dsz_sync_logs` + import history into dashboard charts — inline SVG sparklines (no external libs; CSP-safe, matches design system).
5. **Unit tests**: promote existing scratchpad harnesses (crypto roundtrip, shipping rate resolution) into `tests/unit/` as PHPUnit; add price/stock calc + helper coverage. Adjust `.gitignore` (currently ignores `tests/` — remove that line) and add `tests` to build.ps1 excludes + CI workflow.

---

## Cross-cutting rules (every milestone)
- Respect API constraints via existing `API_Client` (limits 40–200/160, 100-SKU chunks, 14-day/10-day ranges, retry_after plumbing) — no new raw HTTP.
- Version bump + CHANGELOG entry + README roadmap table update + readme.txt sync per milestone.
- `php -l` + `node --check` + unit harness green before calling a milestone done; copy plan into `doc/plan/roadmap-master-plan.md`.
- New options all `autoload => false` unless read on every request.

## Verification
- M1: hooks — mu-plugin snippet registering each filter/action, run manual sync, observe effects in logs; indexes via `SHOW INDEX`; maintenance cron via `wp cron event run`.
- M2: rule engine unit harness (category/supplier/prefix precedence) + preview UI.
- M3: live DSZ sandbox order → serial stored → tracking cron populates meta; bulk submit on 3 test orders.
- M4: variation mapped → sync updates variation price/stock; test email digest to mailbox.
- M5: AS queue visible under WooCommerce → Status → Scheduled Actions; REST smoke via application password + curl; `wp dsz status` output.
- Each milestone ends with the plugin zip building clean (`build.ps1`) and a user smoke pass on dropshipzone.local.
