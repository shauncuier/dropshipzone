# Shipping Method Hardening (class-shipping-method.php)

## Context

The DSZ shipping method ([includes/class-shipping-method.php](D:/Plugin Development/dropshipzone/includes/class-shipping-method.php)) was recently fixed to parse the V2 zone-rate schema, but a full review found remaining correctness, performance, and UX gaps. Goal: make checkout shipping quotes accurate (no silent free shipping), resilient when the DSZ API is slow/down, and extend coverage (variations, NZ).

## Findings → fixes (all in class-shipping-method.php)

### 1. Silent free shipping on missing rates (correctness, revenue risk)
- `calculate_shipping()` line ~229: SKU absent from `$zone_rates` → `continue` → item ships free.
- `get_rate_for_zone()` returns `0` when no scheme has the zone key (or schemes inactive) → free.
- **Fix:** `get_rate_for_zone()` returns `null` when no rate resolves (0 stays a legitimate price). In the totalling loop, a `null`/missing rate marks the quote incomplete. Incomplete quote → behave like rate-unavailable: use `fallback_cost` if configured, otherwise hide the method (matches the existing "Fallback Cost" setting description).

### 2. Per-item DB queries, run twice (performance)
- `is_dsz_product()` fires one query per cart item in both `is_available()` and `calculate_shipping()`.
- **Fix:** new `get_dsz_skus_for_package($package)` — collect all candidate product/variation IDs, single `WHERE wc_product_id IN (...)` query, return id→sku set. Reuse from both callers (memoize per request on the package hash).

### 3. Variations not matched (correctness)
- Checks only `$item['product_id']` (parent). Variation-level mappings/SKUs missed.
- **Fix:** check `variation_id` first, fall back to `product_id`, in the batched query above. Use the mapping row's `dsz_sku` rather than the WC SKU (mapping is the source of truth; WC SKU can drift).

### 4. API failure hammering + slow checkout (resilience)
- API error → nothing cached → every shipping recalculation re-hits a down/rate-limited API (and the rate limiter can block up to 15s inside checkout).
- **Fix:** negative caching — on WP_Error from zone-mapping or zone-rates, set a 5-minute transient (`dsz_zone_err_{postcode}` / `dsz_rates_err_{hash}`); while present, skip the API call and go straight to fallback behavior. Successful lookups keep the existing 1-hour transients.

### 5. NZ support (coverage)
- Docs: `standard` scheme carries an `nz` rate; currently `country !== 'AU'` → fallback only.
- **Fix:** for `NZ` destinations skip postcode mapping and resolve `standard['nz']` per SKU (9999 sentinel → undeliverable, as for AU zones). AU logic unchanged; other countries keep fallback.

### 6. Minor polish
- Trim/validate postcode (AU: 4 digits) before the API call.
- `is_available()` reuses the batched lookup (no duplicate scan).
- Log once per calculation, not per SKU miss; include which SKUs lacked rates.

## Reuse
- `API_Client::get_zone_mapping()` / `get_zone_rates()` (already V2, limit-clamped) — unchanged.
- Existing transient scheme `dsz_zone_*` / `dsz_rates_*` kept; only additive negative-cache keys.
- `intval($rate) === 9999` sentinel check stays.

## Verification
- `php -l` the file.
- Isolated harness (scratchpad PHP, stub WC classes) unit-checking `get_rate_for_zone()`: resolves advanced→defined→standard, honors `active`, returns null when unresolved, passes "9999" through; and the quote loop: missing rate → fallback/hide, 0-rate → $0 line (not hidden).
- Live smoke (user): cart with mapped product + AU metro postcode → real rate; postcode in no-rate zone → fallback or hidden (no free shipping); NZ address → nz rate; kill API creds → fallback within one request, no repeated API calls for 5 min (log shows negative-cache hit).
- Copy this plan to `doc/plan/shipping-hardening.md` per project rule.
