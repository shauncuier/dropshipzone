# Changelog

All notable changes to the DropshipZone Sync plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.3.4] - 2026-07-27

### Fixed
- **`dszsync_sync_completed` action name.** The prefix rename in 3.3.0 rewrote `dsz_sync_` → `dszsync_`, which turned `dsz_sync_completed` into `dszsync_completed` — ambiguous, and not the name the documentation used. Renamed to `dszsync_sync_completed`. Anything hooked to the interim name must be updated; the plugin has not been publicly released with it.

### Changed
- **README rewritten.** It still documented the hooks under their pre-3.3.0 `dsz_` names, which no longer fire, and linked to `API-DOCUMENTATION.md`, deleted in 3.1.0. Requirements corrected to WordPress 6.2, the upgrade migration is now documented, and the support section no longer points at a Dropshipzone address the maintainers do not control.

---

## [3.3.3] - 2026-07-27

### Changed
- Converted the two remaining product-search queries (`search_wc_products()` and `get_unmapped_count()`) to the `%i` identifier placeholder. Both concatenated `$wpdb->posts`, `$wpdb->postmeta` and the mapping table into multi-line SQL, which the 3.3.2 conversion pass did not reach. Plugin Check now reports nothing.

---

## [3.3.2] - 2026-07-27

### Changed
- **Table names now use the `%i` identifier placeholder** rather than being concatenated into the SQL string. Plugin Check reported these as `PreparedSQL.NotPrepared` errors, and the annotations added in 3.3.1 did not silence them because `phpcs:ignore` only applies to the following line — the error was raised on the SQL line inside a multi-line `prepare()` call. Using `%i` removes the finding properly instead of suppressing it. **Minimum WordPress raised to 6.2**, the release that introduced `%i`.

### Fixed
- Several `phpcs:ignore` annotations named `WordPress.Security.ValidationSanitization.InputNotSanitized`, which does not exist. The real sniff is `WordPress.Security.ValidatedSanitizedInput.InputNotSanitized`, so those suppressions had no effect. Corrected, and the API password read now carries its own justification.

---

## [3.3.1] - 2026-07-27

Resolves everything reported by Plugin Check.

### Fixed
- **Unescaped output** (3 errors): the two log-summary strings passed `$total` and `ucfirst($level)` through `printf()` with only the format string escaped, and the Product Import permission notice used a bare `__()` in `wp_die()`. All now escape the composed string.
- **Direct filesystem calls** (2 errors): the mappings CSV export opened a `php://temp` stream via `fopen()`/`fclose()`. It now builds the CSV as a string, so no filesystem API is involved and `WP_Filesystem` does not apply.
- **Missing `wp_unslash()`** on seven inputs before sanitization.
- **Translators comment** placement: moved directly above the `__()` call rather than above the enclosing `sprintf()`.
- **Direct database calls**: every `$wpdb` call now carries a specific justification noting that table names come from `$wpdb->prefix` rather than user input, that values are prepared, and that plugin-owned tables have no core caching API.
- **Nonce warnings**: read-only admin screen state (current page, filter, pagination) annotated as such — these read `$_GET` for display only and change nothing.
- **Hook prefix**: `active_plugins` is a core filter being read, not a hook this plugin defines; annotated accordingly and the comparison made strict.

---

## [3.3.0] - 2026-07-27

Addresses the plugin review team's January feedback.

### Changed
- **Renamed to `3S Soft Price & Stock Sync for Dropshipzone`** (slug `3s-soft-price-stock-sync-for-dropshipzone`), matching the name the review team's tooling suggested. Their guidance rejects short distinguishing terms — the previous `3S` prefix was the same two-letter pattern their examples call out as insufficient.
- **Widened the code prefix from `dsz_` to `dszsync_`** to meet the four-character minimum: functions, constants, options, post meta, cron hooks, filters/actions, AJAX actions, nonces, transients and the shipping method id. Database table and column names deliberately keep the `dsz_` form — they are scoped inside plugin-owned tables, cannot collide with anything, and renaming them would mean an `ALTER TABLE` on live data for no benefit.
- **`Contributors`** now lists the WordPress.org account that owns the submission (`shauncuier`) rather than a display name that matched no account.

### Added
- **One-time prefix migration** on load: copies every renamed option and post meta key (including HPOS order meta), re-points configured WooCommerce shipping zone instances and their per-instance settings at the new method id, and clears the old cron hooks. Existing installs keep their credentials, rules, mappings and schedules.

### Compatibility
- Declared support for WordPress 7.0 and WooCommerce 10.9 (was 6.7.1 / 10.4).
- Made the optional `Admin_UI` constructor parameters explicitly nullable. PHP 8.4+ deprecates the implicit form, which was emitting four deprecation notices per admin request.

### Security
- The resync handler decoded `product_data` from `$_POST` and passed it into product updates unsanitized — the same flaw already fixed on the import path. It now goes through `dszsync_sanitize_api_product()`.
- API password input is explicitly constrained to a scalar string. It is deliberately not run through `sanitize_text_field()`, which would corrupt valid passwords; it is never output and is encrypted at rest.

---

## [3.2.0] - 2026-07-27

### Changed
- **Renamed to `3S Product Sync for Dropshipzone`** (slug and text domain `3s-product-sync-for-dropshipzone`). The WordPress.org submission form warns that generic names are unlikely to be accepted and gives `WriteralAI – AI Writer for Acme` as the model for non-owners: a vendor prefix plus the trademark trailing. `Product Sync` alone was generic enough to risk rejection under that rule, and an approved plugin can never be renamed.

### Fixed
- Escaped two output paths that Plugin Check flags: the class attribute in the bulk-action admin notice, and a translated string with an embedded link (now `wp_kses_post( sprintf( ... ) )` rather than an `esc_html__()` format string with raw HTML injected as an argument).

---

## [3.1.0] - 2026-07-27

WordPress.org plugin directory compliance release.

### Changed
- **Plugin renamed** to `Product Sync for Dropshipzone` (slug `product-sync-for-dropshipzone`, text domain `product-sync-for-dropshipzone`). WordPress.org does not permit a plugin slug to *begin* with a trademark the author does not own; the trailing "for Dropshipzone" construction is explicitly allowed. Main plugin file, `.pot`, build script and release workflow renamed to match.
- **Plugin URI** now points to a page the author controls instead of the supplier's website.
- Removed the "official integration plugin" claim and added a clear non-affiliation notice.

### Added
- **External Services disclosure** in `readme.txt`, documenting every request made to the Dropshipzone API, the data each carries, and links to the provider's terms and privacy policy (required for directory listing).

### Security
- Product data posted back from browser-cached catalogue search results is now recursively sanitized via `dsz_sanitize_api_product()` before import. Previously it was `json_decode`d from `$_POST` and used directly.

### Fixed
- Removed `error_log()` debug calls from the mapping table migration.
- Corrected stale readme content (install path, inaccurate "never creates products" FAQ, roadmap listing already-shipped features).

---

## [3.0.0] - 2026-07-19

### Added
- **Tracking Number Sync**: Twice-daily cron polls Dropshipzone for tracking/status on submitted orders (14-day window), saves tracking to order meta, adds order notes, and optionally auto-completes WC orders when DSZ marks them complete. Manual "Check Tracking Now" button in Sync Center.
- **Bulk Order Submission**: "Submit to Dropshipzone" bulk action on the orders list (HPOS + legacy) and a chunked "Submit Pending Orders" tool in Sync Center with rate-limit retry.
- **Auto-Submit Orders**: Optional (off by default) automatic submission to DSZ when an order reaches processing.
- **Profit Calculator**: Estimated item profit (revenue − supplier cost) in the order meta box and a "DSZ Margin" column on the products list.

### Notes
- The `/orders` API response schema is undocumented; tracking field extraction is defensive and the first raw entry is logged at debug level for verification.

---

## [2.9.0] - 2026-07-19

### Added
- **Advanced Price Rules**: Ordered rule list on the Price Rules page — match by Dropshipzone category (numeric ID or path prefix), supplier ID, or SKU prefix; first match overrides the global markup. GST/rounding inherit from global rules.
- **Supplier Cost Tracking**: `_dsz_cost` meta saved on every price write (sync, import, resync) — foundation for profit reporting.

### Changed
- **Unified Pricing Engine**: The scheduled sync now uses the same `Price_Sync::calculate_price()` engine as import/resync (removes duplicated markup math); sale prices go through the full rule set and are only applied when below the regular price.

---

## [2.8.0] - 2026-07-19

### Added
- **Developer Hooks**: The documented `dsz_calculated_price` and `dsz_calculated_stock` filters and `dsz_sync_completed` / `dsz_price_updated` actions now exist across all sync/import paths.
- **Daily Maintenance Cron**: Log retention (default 30 days, `dsz_log_retention_days` option/filter), orphaned mapping cleanup, and expired DSZ transient purging.
- **Supplier Blacklist**: Exclude up to 50 supplier IDs from Auto Import (API-native `exclude_supplier_ids`).
- **Import Filter Templates**: Save, apply, and delete named filter presets on the Product Import page (up to 20).
- **Mappings CSV Export**: Export all product mappings from the Product Mapping page.

### Changed
- **Database Indexes**: Added composite `(sync_enabled, last_synced)` index on the mapping table (schema v3) for faster sync batch queries on large catalogs.
- **README**: Removed stale banner reference and outdated install instructions; roadmap updated.

---

## [2.7.0] - 2026-07-19

### Added
- **New Zealand Shipping Support**: Added flat-rate shipping support for NZ destinations (standard scheme `nz` key), bypassing AU postcode mapping.
- **Treat $0 Rates as Unavailable Setting**: Added an opt-in shipping method setting to use the fallback rate if the API returns a $0 rate for a zone, preventing unintended free shipping.
- **Negative Caching**: Added a 5-minute transient back-off cache on API failures (postcode mapping and zone rate requests) to prevent hammering the API during checkouts.

### Changed
- **Batched Mapping Queries**: Refactored cart checking to use a single batched query mapping product/variation IDs to SKUs, improving checkout load times.

### Fixed
- **Postcode Validation**: Validate and trim AU postcodes (4 digits) before calling the API.
- **Variation Mapping**: Correctly resolve variation-level mappings using variation IDs before falling back to parent product IDs.

---

## [2.6.6] - 2026-07-18

### Fixed
- **Plugin Folder Name Alignment**: Unified the root folder name inside all ZIP packages (local build and GitHub release) to always be `dropshipzone`. This ensures full compatibility with standard developer repository checkouts and prevents WordPress from deactivating the active plugin with a "Plugin file does not exist" error upon updating.

---

## [2.6.5] - 2026-07-18

### Fixed
- **Build Script slug mismatch**: Fixed a bug in `build.ps1` where the generated ZIP package contained the wrong root plugin folder name (`dropshipzone` instead of `dropshipzone-price-stock-sync`), which caused WordPress to fail updates with a "Plugin file does not exist" error.

---

## [2.6.4] - 2026-07-18

### Fixed
- **Credentials Persistence**: Fixed a bug where a successful "Test Connection" would obtain a token but fail to persist the API email and password, causing background sync cron jobs to fail with "missing credentials" once the initial token expired.

---

## [2.6.3] - 2026-07-18

### Added
- **Admin UI Theme Toggle**: Added an opt-in dark theme toggle in the header, persisted via `localStorage` (independent of OS preferences to avoid clashing with the standard light WordPress admin dashboard).

---

## [2.6.2] - 2026-07-18

### Changed
- **Admin UI Styling**: Cleaned up inline styling and refactored components to use class-based CSS utilities.

### Fixed
- **Build Script**: Excluded the `doc` directory from the distribution ZIP package.

---

## [2.6.1] - 2026-07-18

### Fixed
- **Build Script**: Fixed `.agents` directory being included in distribution zip (exclusion pattern was `.agent` instead of `.agent*`).

---

## [2.6.0] - 2026-07-18

### Added
- **Batch Auto-Mapping**: Auto-map by SKU now processes in batches of 500 with a 20-second time guard, preventing timeouts on large catalogs. Reports remaining unmapped count.
- **Sync Batch Locking**: Transient-based lock prevents concurrent sync batches from overlapping (admin AJAX vs scheduled cron).
- **Schema Version Gate**: Mapping table migration now checks a stored schema version, avoiding an `information_schema` query on every page load.

### Changed
- **Encryption Upgrade**: Credentials now use per-encryption random IVs (`v2:` prefixed format) instead of a static IV derived from salts. Legacy encrypted values are transparently decrypted on read.
- **Admin UI Overhaul**: Refactored admin interface with cleaner layouts and improved JavaScript interactions (~700 lines changed).

### Fixed
- **Stock Sync Accuracy**: When API omits `in_stock`, availability is now derived from `stock_qty` instead of assuming in-stock. Fixed phantom stock updates caused by type mismatch (`null`/string vs int).
- **Category Parsing**: Hierarchical category paths containing commas (e.g., "Home, Garden > Tools") are no longer incorrectly split into separate categories.
- **Mapping Sort Stability**: Added secondary `id ASC` sort to product mapping queries for deterministic pagination.

---

## [2.5.1] - 2026-07-18

### Fixed
- **Auto Import Defaults**: Changed overly restrictive default settings for auto import.
- **Product Import Search**: Removed API `in_stock` filter; now relies on PHP-side filtering to exclude 0-stock products.
- **Product Import UI**: Improved search interface with default in-stock filter and cleaner layout.
- **Metrics Display**: Added cache-busting for realtime metrics display.

### Added
- **Browse All Products**: Allow empty search to browse the full product catalog.
- **Auto Import Debug Counters**: Added detailed skip counters for auto import troubleshooting.

---

## [2.5.0] - 2025-12-30

### Added
- **Auto Product Import**: Scheduled automatic import of new products from Dropshipzone API.
  - Enable/disable auto import on schedule (hourly, twice daily, daily)
  - Configurable filters: New Arrivals, In Stock, Free Shipping
  - Maximum products per run limit (default: 50)
  - Minimum stock quantity filter (default: 100) - only import products with sufficient stock
  - Default product status for imports (publish/draft/pending)
- **Auto Import Metrics**: Separate tracking for auto import runs.
  - Total imports count with number of runs
  - Last 7 days and 30 days statistics
  - Import history table showing date, imported/skipped/errors, and status
  - Stores up to 30 historical import runs

### Changed
- **Import Products UI**: Compact search bar with inline layout (title left, search right)
- **Navigation**: Added "Auto Import" tab to plugin navigation

---

## [2.4.0] - 2025-12-27

### Added
- **Shipping Zones**: New WooCommerce shipping method that calculates rates using Dropshipzone zone mapping.
  - Maps customer postcode to DSZ shipping zones (Standard, Defined, Advanced)
  - Fetches per-product zone rates from DSZ API
  - Supports free shipping threshold and handling fees
  - Handles undeliverable zones (9999)
  - Caches zone data for performance

### Changed
- Updated `readme.txt` and `README.md` with new features and roadmap.

---

## [2.3.2] - 2025-12-27

### Fixed
- **WordPress.org Compatibility**: Fixed 15 unescaped output issues by adding `esc_attr()` to dynamic HTML attributes.

---

## [2.3.1] - 2025-12-26

### Added
- **Granular Resync Buttons**: Sync Center now has separate buttons for targeted updates:
  - **Refresh Images** - Re-download product images only
  - **Refresh Categories** - Update product categories only
  - **Refresh All Data** - Update everything (existing behavior)

---

## [2.3.0] - 2025-12-26

### Added
- **Scan Unmapped Products**: New button to scan all unmapped WooCommerce products against Dropshipzone API.
  - Products found in DSZ are automatically linked and price/stock synced
  - Products not found are marked with `_dsz_not_available` meta (Non-DSZ products)

### Fixed
- **Resync Never Synced Button**: Fixed JavaScript variable name (`dszAdmin` → `dsz_admin`) that was preventing the button from working.

---

## [2.2.9] - 2025-12-26

### Fixed
- **Missing Return Statement**: Fixed `ajax_resync_all()` to properly exit after early return when no products need resyncing.
- **Batch API Fetching**: Refactored `ajax_resync_never_synced()` to batch fetch SKUs (100 at a time) instead of making individual API calls per product.
- **Frontend Debouncing**: Added `resyncInProgress` flag to prevent overlapping resync operations in admin.js.

### Improved
- Added memory limit protection to never-synced resync.
- Enhanced logging for batch operations in never-synced resync.

---

## [2.2.8] - 2025-12-26

### Added
- **Resync Never Synced Button**: Bulk resync all products that have never been resynced.
- **Never Resynced stat box**: Shows count of products with NULL last_resynced.
- Debug logging for `update_last_resynced()` troubleshooting.

### Fixed
- Improved `last_resynced` column migration reliability using information_schema.
- Migration now runs on plugin load, not just activation.

---

## [2.2.7] - 2025-12-26

### Added
- **Complete API Integration**: All Dropshipzone API endpoints now implemented.
- `get_categories()` - Fetch all DSZ product categories
- `get_zone_mapping()` - Map postcodes to shipping zones (standard, defined, advanced)
- `get_zone_rates()` - Get shipping rates per SKU per zone

---

## [2.2.6] - 2025-12-25

### Added
- **Create Order**: Submit WooCommerce orders to Dropshipzone API for fulfillment.
- **Order Meta Box**: "Dropshipzone Order" panel on WC order edit page with Submit button.
- **Order Tracking**: Database table (`wp_dsz_orders`) to track DSZ serial numbers and status.
- **Australian State Mapping**: Automatic conversion of state codes (NSW→New South Wales).
- API methods: `place_order()` and `get_orders()`.

### Notes
- Orders are created as "Not Submitted" in Dropshipzone - user must login to DSZ to pay.

---

## [2.2.5] - 2025-12-25

### Added
- **Last Resynced Column**: Product Mapping page now shows when each product was last fully resynced (separate from price/stock sync).
- **Resync Filter**: Filter mappings by resync status (Never, Today, Last 7 Days, Last 30 Days, Older).
- **Database Upgrade**: Added `last_resynced` column to track full data resyncs separately.

### Changed
- Column renamed from "Last Synced" to "Last Resynced" to clarify it tracks full data resync (images, description, title, etc).

---

## [2.2.4] - 2025-12-25

### Added
- **API Load Balancer**: Enhanced rate limiting with smart adaptive delays that proactively slow requests before hitting API limits.
- **Request Statistics**: Track total API requests, waits, and average wait times for monitoring.
- **Usage Percentage Display**: Rate limit status now shows usage percentages for minute and hour quotas.
- **Skipped Products Reporting**: Resync completion message now shows count of skipped inactive products.

### Improved
- **Resync Optimization**: "Refresh All Data" now skips products that are already inactive (draft + out of stock) since they don't need updating.
- **Sequential API Processing**: API requests are explicitly processed one at a time (sequentially) to respect rate limits.
- **Proactive Throttling**: Requests are now automatically delayed based on current API usage (40%→1.5s, 60%→2s, 80%→3s, 90%→5s).
- **Burst Prevention**: Minimum 0.5 second delay between requests prevents API bursting.
- **Enhanced Logging**: Added detailed debug logs for resync filtering and load balancer delays.

---

## [2.2.3] - 2025-12-25

### Fixed
- **In Stock Filter**: Fixed API parameter format (use `1` instead of `true`).

### Improved  
- **Import Page UI**: Cleaner hero section and compact quick filter cards.

---

## [2.2.2] - 2025-12-25

### Fixed
- **Category Loading**: Fixed API response handling for categories, now properly extracts data from different response formats.

---

## [2.2.1] - 2025-12-25

### Added
- **Product Badges**: Import cards now show Sale, Free Shipping, and New Arrival badges.
- **Dual Price Display**: Shows both Cost (supplier) and RRP when available.
- **Product Specs**: Displays weight and brand information on product cards.
- **Lazy Loading**: Product images now lazy load for better performance.

### Improved
- **Import Card Styling**: Better visual distinction for already-imported products.

---

## [2.2.0] - 2025-12-25

### Added
- **Auto-Republish on Restock**: Products that were set to Draft (because they went out of stock or were discontinued) are now automatically republished when stock is restored.
- **Stock Rules Setting**: New "Auto-Republish on Restock" toggle in Stock Rules page to enable/disable this behavior.

---

## [2.1.2] - 2025-12-25

### Improved
- **Redesigned Logs Page**: Modern card-based layout with stats cards and improved UX.
- **Clickable Filter Cards**: Stats cards show counts per level (Total, Info, Warning, Error) and act as filters.
- **Color-Coded Log Items**: Visual indicators for each log type with hover effects.
- **Collapsible Context**: Click "View details" to expand JSON context instead of showing everything.
- **Relative Timestamps**: Show "5 minutes ago" instead of full datetime.

---

## [2.1.1] - 2025-12-25

### Fixed
- **Batch API Fetching**: "Refresh All Product Data" now fetches product data in batches (100 SKUs per request) instead of one-by-one, significantly improving performance.
- **Complete Product Updates**: Now properly updates images, descriptions, prices, stock, and categories from API data.

### Added
- **Memory Protection**: Automatic memory limit check prevents server timeouts during large resyncs.
- **Enhanced Logging**: Detailed logging for batch fetch and resync operations.

---

## [2.1.0] - 2025-12-25

### Added
- **Unified Sync Center**: Consolidated all sync actions into one page with three action cards.
- **Action Cards UI**: New card-based layout for Link Products, Update Prices & Stock, and Refresh Product Data.
- **Inline Schedule Settings**: Configure auto-sync interval and batch size directly on Sync Center page.

### Changed
- **Simplified Product Mapping**: Removed duplicate sync buttons, focused on manual mapping table.
- **Renamed Menu**: "Sync Control" is now "Sync Center".

---

## [2.0.9] - 2025-12-25

### Improved
- **Clearer Sync Labels**: Renamed confusing buttons for better user understanding:
  - "Auto-Map by SKU" → "Link Products by SKU"
  - "Resync All Products" → "Refresh All Product Data"
  - "Run Manual Sync" → "Update Prices & Stock"
- **Better Descriptions**: Added clearer helper text explaining what each action does.
- **Reorganized Mapping Page**: Separated "Link Products" and "Maintenance" sections for clearer workflow.

---

## [2.0.8] - 2025-12-24

### Improved
- **WordPress.org Compatibility**: Full compliance with WordPress.org plugin repository guidelines.
- **Plugin Renamed**: Rebranded to "DropshipZone Sync" for better clarity.
- **Future Roadmap**: Added roadmap section to README with planned features.

### Added
- **Languages Folder**: Added `/languages/` directory with POT template for translations.

---

## [2.0.7] - 2025-12-24

### Improved
- **Modern Sync Control**: Overhauled the Sync Control UI with a card-based dashboard, glassmorphism effects, and an animated, high-fidelity progress bar.
- **Enhanced UX**: Real-time sync status updates and clearer catalog overview cards.

---

## [2.0.6] - 2025-12-24

### Added
- **Auto-Deactivation**: Mapped products are now automatically set to "Draft" status and "0" stock if their SKU is no longer found in the Dropshipzone API. This can be toggled in Stock Rules.

---

## [2.0.5] - 2025-12-24

### Added
- **Advanced Search Filters**: Users can now filter API products by Category, Stock Status (In Stock Only), Free Shipping, Promotions, and New Arrivals.
- **Sorting Options**: Sort search results by Price (Low to High, High to Low).
- **Category Loader**: Dynamically load the latest categories from Dropshipzone API for filtering.
- **Search Metadata**: Displayed result counts and active filters for better context.

### Improved
- **Search Logic**: Replaced local filtering with API-native keyword and filter parameters for significantly faster and more accurate results.

---

## [2.0.4] - 2025-12-24

### Improved
- **Navigation Reordered**: Better workflow sequence: Dashboard → API → Import → Mapping → Price Rules → Stock Rules → Sync → Logs
- **Import Product Cards**: Now display category path, color-coded stock status, and description preview
- Changed import icon from plus to download for clearer meaning
- Renamed "Product Import" to "Import Products" for clarity

---

## [2.0.3] - 2025-12-24

### Fixed
- **Category Import**: Fixed issue where product categories were not being imported. The API uses `Category` field (capital C) with hierarchical data like `l1_category_name`, `l2_category_name`, `l3_category_name`.
- Categories now properly create hierarchical structure in WooCommerce (e.g., "Appliances > Air Conditioners > Evaporative Coolers")
- Added `update_categories` option to resync functionality

---

## [2.0.2] - 2025-12-24

### Fixed
- **Product Description Import**: Fixed issue where product descriptions were not being imported. The Dropshipzone API returns descriptions in the `desc` field, not `description`.
- Added debug logging for description import tracking

---

## [2.0.1] - 2025-12-24

### Added
- **API Rate Limiting**: Smart throttling to comply with Dropshipzone API limits (60/min, 600/hour)
- **Auto-Deactivate Products**: Products not found in Dropshipzone API are automatically set to Draft
- New `Rate_Limiter` class for proactive API throttle management
- New `deactivate_product_by_sku()` method in Stock_Sync class
- "Deactivate Missing Products" option in Stock Rules settings

### Changed
- API client now checks rate limits before each request
- API client records all request timestamps for accurate tracking
- Improved logging for rate limit and deactivation events

---

## [2.0.0] - 2025-12-24

### Added
- Commencing development for Version 2.0
- New Product Import feature: Search and import products directly from Dropshipzone API
- Core `Product_Importer` class for automated product creation and image sideloading
- Dynamic rule application (Price & Stock) during product import
- Automated Product Mapping for all imported items
- Enhanced Admin UI with dedicated Product Import dashboard and grid layout results

## [1.0.0] - 2025-12-21

### Added
- **Price Synchronization**
  - Automatic price sync from Dropshipzone API to WooCommerce
  - Flexible markup options (percentage or fixed amount)
  - GST support (include/exclude 10% Australian GST)
  - Price rounding options (.99, .95, or nearest dollar)
  - Real-time price updates via API

- **Stock Synchronization**
  - Automatic stock level sync from Dropshipzone API
  - Stock buffer system to prevent overselling
  - Automatic out-of-stock status management
  - Zero stock handling for unavailable products

- **Product Mapping**
  - SKU-based product matching
  - Automatic product mapping by SKU
  - Manual product mapping interface
  - Product import from Dropshipzone catalog
  - Bulk mapping operations

- **API Integration**
  - Secure API authentication with token management
  - Automatic token refresh
  - Connection testing
  - Encrypted credential storage
  - Rate limiting and error handling

- **Sync Scheduling**
  - Multiple schedule options (hourly, twice daily, daily)
  - Manual sync trigger
  - Batch processing for large catalogs (10,000+ products)
  - Sync progress tracking
  - Pause/resume functionality

- **Admin Interface**
  - Modern, responsive dashboard
  - Real-time sync status
  - API settings page
  - Price rules configuration
  - Stock rules configuration
  - Product mapping interface
  - Sync control panel
  - Comprehensive logging system

- **Logging & Monitoring**
  - Detailed activity logs
  - Error tracking and reporting
  - Log level filtering (info, warning, error)
  - CSV export of logs
  - Automatic log cleanup

- **Developer Features**
  - WordPress coding standards compliance
  - WooCommerce HPOS compatibility
  - Action hooks for extensibility
  - Filter hooks for customization
  - Comprehensive inline documentation

### Security
- Encrypted API credential storage
- Nonce verification for all AJAX requests
- Capability checks for admin access
- SQL injection prevention
- XSS protection
- CSRF protection

### Performance
- Batch processing for efficient sync
- Database query optimization
- Caching for API tokens
- Minimal memory footprint
- Background processing via WP-Cron

## Future Roadmap

### Future Enhancements
- Webhook support for real-time updates
- Multi-currency support
- Advanced product filtering
- Sync history and analytics
- Email notifications
- REST API endpoints

---

## Versioning

We use [Semantic Versioning](https://semver.org/):
- **MAJOR** version for incompatible API changes
- **MINOR** version for backwards-compatible functionality additions
- **PATCH** version for backwards-compatible bug fixes

## Release Process

1. Update version number in `dropshipzone-price-stock-sync.php`
2. Update version in `readme.txt` (Stable tag)
3. Update version badge in `README.md`
4. Document changes in this `CHANGELOG.md`
5. Tag release in Git: `git tag -a v1.0.0 -m "Version 1.0.0"`
6. Push tag: `git push origin v1.0.0`
7. Create GitHub release with changelog
8. Build and deploy to WordPress.org (if applicable)

[3.3.4]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.3.4
[3.3.3]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.3.3
[3.3.2]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.3.2
[3.3.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.3.1
[3.3.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.3.0
[3.2.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.2.0
[3.1.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.1.0
[3.0.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v3.0.0
[2.9.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.9.0
[2.8.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.8.0
[2.7.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.7.0
[2.6.6]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.6
[2.6.5]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.5
[2.6.4]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.4
[2.6.3]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.3
[2.6.2]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.2
[2.6.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.1
[2.6.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.6.0
[2.5.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.5.1
[2.5.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.5.0
[2.4.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.4.0
[2.3.2]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.3.2
[2.3.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.3.1
[2.3.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.3.0
[2.2.9]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.9
[2.2.8]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.8
[2.2.7]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.7
[2.2.6]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.6
[2.2.5]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.5
[2.2.4]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.4
[2.2.3]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.3
[2.2.2]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.2
[2.2.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.1
[2.2.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.2.0
[2.1.2]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.1.2
[2.1.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.1.1
[2.1.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.1.0
[2.0.9]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.9
[2.0.8]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.8
[2.0.7]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.7
[2.0.6]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.6
[2.0.5]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.5
[2.0.4]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.4
[2.0.3]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.3
[2.0.2]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.2
[2.0.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.1
[2.0.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.0
[1.0.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v1.0.0
