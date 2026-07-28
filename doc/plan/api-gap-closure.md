# API Gap Closure — correctness fixes now, incremental sync later

## Context

Re-reading `API-NOTES.md` against the current code surfaced a set of gaps
between what the Dropshipzone API offers and what the plugin actually
uses — plus four real defects, one of them revenue-affecting.

The plugin is currently **awaiting first review** on WordPress.org
(v3.3.3 submitted 26 July 2026). New versions can be uploaded on the
submission page **until a reviewer starts**, so Phase 1 lands now and gets
re-uploaded. Phase 2 is deferred until after approval.

---

## Phase 1 — Correctness — **DONE, shipped in 3.3.5 (2026-07-28)**

### 1.1 Sale prices never clear or update — **the important one**

`includes/class-cron.php:481-532`, `update_product_price()`.

Two compounding faults:

- **Early return at :507-509.** When the regular price is unchanged the
  method returns `false` before reaching the sale-price block at :523. A
  special that starts, changes, or ends on a cost-stable product is never
  applied or removed.
- **No clearing.** The block at :524 only runs when `special_price` is
  non-empty. When a special ends, the old sale price is left on the
  product.

Net effect: a product that goes on sale keeps selling below the intended
price indefinitely. Same failure class as the V2 shipping parser that
quoted $0 on every order.

**Fix:** restructure so sale handling always runs, independent of whether
the regular price moved:

- Compute `$new_price`; track whether the regular price changed
- Always resolve the desired sale state:
  - `special_price` present and below the calculated regular → set sale
    price, and set `set_date_on_sale_from()` / `set_date_on_sale_to()`
    from `special_price_from_date` / `special_price_end_date`
  - otherwise → `set_sale_price('')` and clear both dates
- Return `true` if **either** the regular or the sale state changed, so
  the caller still saves

The correct date handling already exists in
`includes/class-price-sync.php:329-333` — reuse that logic rather than
writing it fresh.

### 1.2 `min_stock_qty` default disagrees with itself

`$default_settings` says **10**
(`includes/class-auto-importer.php:44`), but three fallbacks say **100**:
`class-auto-importer.php:87`, `class-admin-ui.php:3634`, `:3769`.
Behaviour therefore depends on whether the option row exists yet.
`readme.txt:27` also advertises "100+ units".

**Fix:** make 10 the single value in all four places, and correct the
readme line to describe a configurable threshold rather than a fixed 100.

### 1.3 Two catalogue-search badges can never render

`assets/admin.js`:

- `:1377` tests `product.au_free_shipping || product.free_shipping`; the
  response field is `freeshipping` (string `"0"`/`"1"`)
- `:1437` reads `product.rrp`; the response fields are `RrpPrice` and
  `RRP.Standard`, so the RRP line at `:1441` is unreachable

**Fix:** read the real field names, treating `freeshipping` as the
string it is. Field names are documented in `API-NOTES.md:38-46`.

### 1.4 Token survives a credential change

`API_Client::clear_token()` (`includes/class-api-client.php:585`) is never
called. After changing the API password the previous token stays in
`dszsync_api_token` until it expires.

**Fix:** call it from the `case 'api'` branch of `ajax_save_settings()`
(`includes/class-admin-ui.php:1463`) whenever a new password is saved.

### 1.5 `$rrp_price` dead local

`includes/class-price-sync.php:271` assigns `RrpPrice` and never uses it.
Either drop the line or, preferably, store it as `_dszsync_rrp` meta so
the planned RRP work in Phase 2 has data to build on.

---

## Phase 2 — After WordPress.org approval

### 2.1 Incremental sync

The plugin re-fetches **every** mapped SKU on every scheduled pass
(`class-cron.php:196-472`; batch query at
`class-product-mapper.php:450-462` orders by `id` with no `last_synced`
or change filter). On a 10,000-SKU catalogue that is ~100
`/v2/products` calls per sweep against a 600/hour limit.

Two mechanisms already exist and are unused:

- **`API_Client::get_stock()`** (`class-api-client.php:324`) — the
  `/stock` change-log endpoint, fully implemented including the
  sub-10-day range guard, with **zero callers**
- **`updated_at`** — a per-product change timestamp in every product
  response, never read

Design: poll `/stock` for the window since the last successful sync,
refresh only the returned SKUs, and keep the full sweep as a scheduled
fallback (e.g. nightly) so nothing drifts permanently. Store the API's
`updated_at` alongside each mapping to support a second comparison.

### 2.2 Unused product fields worth mapping

| Field | Target |
|---|---|
| `eancode` | WooCommerce native GTIN/UPC/EAN field (WC 9.2+) |
| `brand` | WooCommerce native brands taxonomy (WC 9.4+) — currently only rendered in the JS search preview, never persisted |
| `RrpPrice` / `RRP.Standard` | store as meta; optionally drive a "compare at" display |

### 2.3 Dead code

Six of nineteen `API_Client` public methods have no callers:
`get_all_products`, `get_stock`, `clear_token`, `get_stats`,
`get_rate_limit_status`, `reset_rate_limit`.

Bigger: the whole batch-sync path inside `Price_Sync` and `Stock_Sync`
(`sync_batch()`, `sync_product_price()`, `sync_product_stock()` and their
single-product wrappers) is unreachable — only `get_rules()`,
`reload_rules()`, `calculate_price()` and `calculate_stock()` are called
from outside those classes. **This is why 1.1 exists: the correct sale-date
logic was written in the dead path while the live path in `Cron` never got
it.**

After 1.1 and 2.1, delete the dead paths rather than leaving two
divergent implementations. `clear_token` and `get_stock` become live
under 1.4 and 2.1, so keep those.

### 2.4 Auto Import filter gaps

- `nz_available` is never sent or exposed anywhere
- `on_promotion` exists in catalogue search only, not Auto Import
  settings (`class-admin-ui.php:3654-3694`)
- In catalogue search, `$in_stock` is read at `class-admin-ui.php:2290`
  but never added to `$api_params` — filtering happens PHP-side at
  `:2357-2363` instead

Also worth retesting `filter_in_stock` / `filter_new_arrival`, both
defaulted off because the API "returned zero results". The boolean
serialization fix (`API-NOTES.md:56`) may have been the real cause.

### 2.5 Tracking sync coverage

`Order_Handler::sync_tracking()` only ever queries by `order_ids`, within
a 14-day SQL window capped at 100 rows
(`class-order-handler.php:414-439`). Orders older than that never receive
tracking. The `/orders` date-range and `status` parameters, and the 14-day
validation in `get_orders()`, are never exercised.

Once the response schema is confirmed against real data, widen the window
and remove the "Schema discovery aid" debug log at
`class-order-handler.php:455-456`.

---

## Verification

**Phase 1**

- `php -l` every changed file; `node --check assets/admin.js`
- Extend the scratchpad regression harness with sale-price cases:
  special starts / special ends / special unchanged with cost unchanged —
  the last is the case the early return currently breaks
- Live on dropshipzone.local: map a product with an active special, run a
  sync, confirm sale price and dates set; clear the special in the API
  fixture, sync again, confirm the sale price is **removed**
- Confirm a saved API password change invalidates the stored token
- Catalogue search: confirm free-shipping and RRP now render
- Run **Plugin Check** — must stay at zero errors and zero warnings
- Build, verify zip root folder, bump version + changelog, re-upload to
  the WordPress.org submission page

**Phase 2** — plan separately once approved; incremental sync needs its
own verification against a real catalogue.

---

## Still outstanding from earlier (unchanged)

- The `dszsync_` prefix migration has never run against real data —
  install over an existing install and confirm credentials, rules,
  mappings and shipping zones survive
- `readme.txt` screenshots list 10 files that do not exist
- `.pot` is stale (dated 2024, predates three renames)
