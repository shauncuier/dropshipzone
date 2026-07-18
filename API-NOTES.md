# Dropshipzone API — Official Docs Notes

Source: https://www.dropshipzone.com.au/apidoc/index.html (doc v1.0.1, apidoc 0.23.0, generated 2025-08-30). Fetched and verified 2026-07-18. The page is JS-rendered — plain HTTP fetch returns only "Loading...", use a real browser to re-read it.

Base URL: `https://api.dropshipzone.com.au`

Throttle limits (enforced by API gateway, excess requests fail): **60 requests/minute, 600 requests/hour**.

## Authentication

`POST /auth` with JSON `{email, password}` → `{iat, exp, token}`.

- All other endpoints use header `Authorization: jwt <token>` (lowercase `jwt` prefix) plus `Content-Type: application/json`.
- Docs prose claims the token "will be expired in 15 mins", but the official example shows `exp - iat` = 61 hours. **Trust the `exp` field from the response**, not the prose. The plugin already does this (stores `dsz_sync_token_expiry`, refreshes with a 120s buffer).

## Endpoint reference

| Endpoint | Method | Purpose | Constraints |
|---|---|---|---|
| `/auth` | POST | Create JWT token | — |
| `/v2/categories` | GET | Flat category list | Fields: `category_id`, `title`, `parent_id`, `path`, `is_anchor`, `is_active`, `include_in_menu` |
| `/v2/products` | GET | Product catalog | `limit` default 40, **min 40, max 200**; `skus` ≤ 100 comma-separated; `keywords` ≤ 20; `supplier_ids`/`exclude_supplier_ids` ≤ 50; `sort_by` only accepts `price` |
| `/stock` | POST | Stock-change log between two datetimes | **Time range ≤ 10 days**; `limit` 40–160; returns `{sku, created_at, new_qty, status}` |
| `/orders` | GET | Order lookup | **Time range ≤ 14 days** (HTTP 404 + `errmsg` otherwise); `status` ∈ `processing,complete,cancelled`; `limit` 40–160 |
| `/placingOrder` | POST | Place order | Creates a **"Not Submitted"** order — user must log in to the DSZ website and pay manually. See error semantics below |
| `/v2/get_zone_mapping` | POST | Postcode → shipping zones | Returns `{postcode, standard, defined?, advanced?}` per postcode; `limit` 40–160 |
| `/v2/get_zone_rates` | POST | SKU → per-zone shipping rates | Returns three schemes per SKU: `standard` (incl. `nz`, `active`), `defined`, `advanced`; `limit` 40–160 |
| `/get_zone_mapping`, `/get_zone_rates` (V1) | POST | **Deprecated after 30 Sep 2025** | Do not use. Plugin is already on the V2 endpoints (`class-api-client.php:737,764`) — do not reintroduce V1 paths |

Pagination on list endpoints: response carries `total`, `total_pages`, `current_page` (zone endpoints also `page_size`, `code`, `message`).

## `/v2/products` filter params

`category_id` (Number), `enabled` (Boolean, default `true`), `in_stock` (Boolean), `au_free_shipping` (Boolean), `nz_available` (Boolean), `on_promotion` (Boolean), `new_arrival` (Boolean), `supplier_ids`, `exclude_supplier_ids`, `skus`, `keywords`, `page_no`, `limit`, `sort_by`, `sort_order`.

Note the naming mismatch: request param is `new_arrival`, but the response field is `is_new_arrival`.

## Product response fields (post-30-Sep-2025 schema)

- **`price` and `cost` are both the wholesale cost** (official sample: both `29.31`). `RrpPrice` (number) and `RRP: {"Standard": ...}` (object) carry the recommended retail price. This is why the plugin's `dsz_get_api_cost()` helper (prefer `cost`, fall back to `price`) is the single pricing source.
- `in_stock` is a **string `"1"`/`"0"` in responses** but a Boolean in request params.
- `status`: `"In Stock"` / `"Out Of Stock"`; `stock_qty`: int; `disabled`: `"No"`; `freeshipping`: string `"0"`/`"1"`.
- `special_price` (nullable) with `special_price_from_date` / `special_price_end_date`; `rebate_percentage` + `rebate_start_date` / `rebate_end_date`.
- Category comes as `Category` ("A > B > C" path) plus `l1/l2/l3_category_id` and `_name` fields.
- `gallery`: array of CDN image URLs; `desc`: description; `eancode`; `length`/`width`/`height`/`weight`/`cbm`; `updated_at`: unix timestamp; `is_direct_import`; `vendor_id`; `website_url`.
- The response schema changed on 30 Sep 2025 — docs keep "Before" and "After" examples. Current schema is the "After" one (adds `RRP` object, `rebate_*`, `is_new_arrival`, `is_direct_import`).

## `/placingOrder` semantics

- Success and failure both return **HTTP 200**; check each array item for `status: 1` (ok) vs `status: -1` + `errmsg`.
- Known `errmsg` values: duplicate `your_order_no`, postcode not found, postcode/suburb mismatch, email not found, insufficient stock for SKU, SKU does not exist, missing `sku`/`qty` in order_items.
- Official example sends `state` as the **full state name** ("Australian Capital Territory"), not the abbreviation — matches the plugin's state-code→name mapping in `class-order-handler.php`.

## Gotchas for this plugin

1. **Boolean GET params:** FIXED — `make_request()` now converts PHP bools to literal `true`/`false` strings before `add_query_arg()` (which would serialize them as 1/0). This may have been the real cause of the `in_stock=true` filter "returning zero results" (commit 2c8aa9e, filter since disabled by default) — worth re-enabling `filter_in_stock`/`filter_new_arrival` in Auto Import settings and retesting.
2. **`limit` minimum is 40** on every list endpoint (`/v2/products` max 200, all others max 160). FIXED — all API client methods now clamp `limit` into the documented range, and sub-40 caller values (Auto Import, admin SKU searches) were raised.
3. **`skus` cap is 100** per `/v2/products` call — the sync's 100-SKU chunking is exactly at the limit; don't raise it.
4. **Time-range caps:** `/orders` ≤ 14 days, `/stock` < 10 days. FIXED — `get_orders()` and `get_stock()` validate the range and return a `WP_Error` before burning an API call. Any history/backfill feature must window its queries.
5. Placed orders are **not paid** — they sit as "Not Submitted" until the merchant pays on the DSZ website. Don't report them to users as fulfilled.
6. Zone rate value `"9999"` appears as a sentinel (effectively "no shipping to this zone", e.g. `nz: "9999"`); `"0"` is common and appears to mean no charge/not applicable. Treat `9999` as unavailable rather than a real price. FIXED — `Shipping_Method::get_rate_for_zone()` was rewritten for the V2 schema: it previously looked for `zones[]`/`default_rate`/`shipping_cost` keys that don't exist in V2 responses (so every rate resolved to 0 = free shipping); it now resolves the postcode's zone slug per scheme (advanced → defined → standard, honoring each scheme's `active` flag), and the 9999 check uses `intval()` so the string sentinel is caught.
7. Stock disclaimer in the docs footer: stock figures are indicative and can change between fetch and order — keep the plugin's stock-buffer feature.
8. **Zone rate `"0"` is ambiguous**: official samples show most zones as `"0"` with isolated real prices (e.g. `act: "3"`, `nsw_m: "20"`, everything else 0) — docs never state whether 0 means free shipping or "supplier didn't price this zone". The shipping method has an opt-in instance setting ("Treat $0 rates as unavailable") for merchants hit by unwanted free shipping. Docs also imply multiple schemes can be `active: true` simultaneously with conflicting values (`defined.sydney = 9999` vs `advanced.sydney = 5.12`) — the plugin resolves most-specific-first: advanced → defined → standard.
9. Unknown postcode on `/v2/get_zone_mapping` returns **HTTP 500** with `{"code":0,"data":[],"message":"no data"}` (per the docs' own error example) — treat as "no zone", not an outage.
