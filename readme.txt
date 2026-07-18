=== DropshipZone Sync ===
Contributors: 3s-Soft, dropshipzone
Tags: woocommerce, dropshipzone, price sync, stock sync, dropshipping
Requires at least: 6.0
Tested up to: 6.7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.4
Stable tag: 2.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically sync product prices and stock levels from Dropshipzone API to your WooCommerce store.

== Description ==

**DropshipZone Sync** is a lightweight, enterprise-grade WooCommerce plugin that automatically synchronizes product prices and stock levels from the Dropshipzone API.

= Key Features =

* **Price Sync** - Automatically update regular and sale prices
* **Stock Sync** - Keep stock quantities accurate in real-time
* **Auto Product Import** - Schedule automatic imports with customizable filters (NEW)
* **Import Metrics** - Track import runs with 7-day, 30-day stats and history (NEW)
* **Minimum Stock Filter** - Only import products with 100+ units in stock (NEW)
* **Shipping Zones** - Calculate shipping using DSZ zone mapping and per-product rates
* **Product Import** - Import products directly from Dropshipzone catalog
* **Order Submission** - Submit orders to Dropshipzone for fulfillment
* **Product Mapping** - Link WooCommerce products to Dropshipzone SKUs
* **Scan Unmapped Products** - Auto-detect and link existing WC products to DSZ
* **Granular Resync** - Refresh images, categories, or all data separately
* **SKU Matching** - Products matched by SKU for accuracy
* **Flexible Pricing** - Percentage or fixed markup options
* **GST Support** - Include or exclude 10% Australian GST
* **Price Rounding** - Round to .99, .95, or nearest dollar
* **Stock Buffer** - Subtract units to prevent overselling
* **Scheduled Sync** - Hourly, twice daily, or daily options
* **Manual Sync** - Run sync anytime with one click
* **Batch Processing** - Handles 10,000+ products efficiently
* **API Load Balancer** - Smart rate limiting prevents API errors
* **Detailed Logging** - Track all sync activity and errors

= Important Notes =

* Requires WooCommerce to be installed and active
* Requires Dropshipzone API credentials
* Orders created as "Not Submitted" - login to Dropshipzone to pay

= Requirements =

* WordPress 6.0 or higher
* WooCommerce 8.0 or higher
* PHP 7.4 or higher
* Dropshipzone API account

== Installation ==

1. Upload the `dropshipzone-price-stock-sync` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **DSZ Sync > API Settings** and enter your Dropshipzone credentials
4. Configure your price and stock rules
5. Run a manual sync or wait for the scheduled sync

== Frequently Asked Questions ==

= How often does the sync run? =

By default, the sync runs every hour. You can change this to twice daily or once daily in Sync Control settings.

= Will this create new products? =

No. This plugin ONLY updates prices and stock for existing products. Products must already exist in your WooCommerce store with matching SKUs.

= What happens if a SKU doesn't match? =

Products without matching SKUs are skipped. You can view these in the Logs page.

= How does the pricing markup work? =

You can choose between percentage markup (e.g., 30% on top of supplier price) or fixed markup (e.g., $15 added to supplier price).

= Is GST automatically calculated? =

Yes, you can enable GST calculation in Price Rules. Choose whether supplier prices already include GST or need GST added.

= Can I prevent overselling? =

Yes! Use the Stock Buffer feature to subtract a safety amount from supplier stock levels.

= Is the API password stored securely? =

Yes, API passwords are encrypted before storage using WordPress security salts.

== Screenshots ==

1. Dashboard - Overview of sync status and quick actions
2. API Settings - Configure Dropshipzone credentials
3. Price Rules - Set markup, GST, and rounding preferences  
4. Stock Rules - Configure buffer and out-of-stock handling
5. Sync Control - Manual sync and scheduling options
6. Product Import - Browse and import products from Dropshipzone
7. Auto Import - Configure scheduled automatic imports (NEW)
8. Product Mapping - Link WC products to Dropshipzone SKUs
9. Order Submission - Submit orders to Dropshipzone for fulfillment
10. Logs - View detailed sync activity and errors

== Future Roadmap ==

We're constantly improving DropshipZone Sync. Here's what's planned:

= High Priority (Coming Soon) =

* **Tracking Number Sync** - Auto-import tracking numbers from DSZ orders
* **Webhook Support** - Real-time updates via DSZ webhooks
* **Advanced Price Rules** - Category-based and supplier-based pricing
* **Bulk Order Submission** - Submit multiple orders at once

= Medium Priority =

* **Product Variations** - Full support for variable products from DSZ
* **Email Notifications** - Get notified on sync errors, low stock, price changes
* **Auto-Repricing** - Adjust prices based on competitor analysis
* **Inventory Alerts** - Low stock warnings with configurable thresholds
* **Import Scheduling** - Schedule specific import times
* **Category Mapping** - Map DSZ categories to custom WC categories

= Under Consideration =

* **Multi-currency Support** - Support for AUD, NZD, USD
* **Profit Calculator** - View margins on product and order level
* **Multi-supplier Support** - Integrate with multiple dropship suppliers
* **REST API Endpoints** - Expose sync functionality via REST API
* **Sync Analytics Dashboard** - Charts showing sync history and trends
* **Supplier Blacklist** - Exclude specific suppliers from import
* **Export Tools** - Export product data, mappings, and reports

== Changelog ==

= 2.8.0 =
* NEW: Developer Hooks. The documented `dsz_calculated_price` and `dsz_calculated_stock` filters and `dsz_sync_completed` / `dsz_price_updated` actions now exist across all sync/import paths.
* NEW: Daily Maintenance Cron. Log retention (default 30 days), orphaned mapping cleanup, and expired DSZ transient purging.
* NEW: Supplier Blacklist. Exclude up to 50 supplier IDs from Auto Import (using native API exclude_supplier_ids).
* NEW: Import Filter Templates. Save, apply, and delete named filter presets on the Product Import page.
* NEW: Mappings CSV Export. Export all product mappings from the Product Mapping page.
* CHANGED: Database Indexes. Added composite index on the mapping table (schema v3) for faster sync batch queries.

= 2.7.0 =
* NEW: New Zealand shipping support. Added flat-rate shipping for NZ destinations (standard scheme `nz` key), bypassing AU postcode mapping.
* NEW: Treat $0 Rates as Unavailable. Added an opt-in shipping method setting to use the fallback rate if the API returns a $0 rate for a zone, preventing unintended free shipping.
* NEW: Negative Caching. Added a 5-minute transient back-off cache on API failures (postcode mapping and zone rate requests) to prevent hammering the API during checkouts.
* CHANGED: Batched mapping queries. Refactored cart checking to use a single batched query mapping product/variation IDs to SKUs, improving checkout load times.
* FIXED: Validate and trim AU postcodes (4 digits) before calling the API.
* FIXED: Correctly resolve variation-level mappings using variation IDs before falling back to parent product IDs.

= 2.6.6 =
* FIXED: Unified the root folder name inside all ZIP packages to always be `dropshipzone` to prevent WordPress from deactivating the active plugin with a "Plugin file does not exist" error upon updating.

= 2.6.5 =
* FIXED: Fixed a bug in build.ps1 where the generated ZIP package contained the wrong root plugin folder name (dropshipzone instead of dropshipzone-price-stock-sync).

= 2.6.4 =
* FIXED: Fixed a bug where a successful "Test Connection" would obtain a token but fail to persist the API email and password.

= 2.6.3 =
* NEW: Added an opt-in dark theme toggle in the admin header, persisted via localStorage.

= 2.6.2 =
* IMPROVED: Cleaned up admin UI inline styling and refactored components to use class-based CSS utilities.
* FIXED: Excluded the doc directory from the distribution ZIP package.

= 2.6.1 =
* FIXED: Fixed .agents directory being included in distribution zip.

= 2.6.0 =
* NEW: Batch Auto-Mapping. Auto-map by SKU now processes in batches of 500 with a 20-second time guard, preventing timeouts on large catalogs.
* NEW: Sync Batch Locking. Transient-based lock prevents concurrent sync batches from overlapping.
* NEW: Schema Version Gate. Mapping table migration now checks a stored schema version, avoiding an information_schema query on every page load.
* CHANGED: Credentials encryption now uses per-encryption random IVs instead of a static IV.
* IMPROVED: Refactored admin interface with cleaner layouts and improved JavaScript interactions.
* FIXED: Derived stock availability from stock_qty when API omits in_stock.
* FIXED: Category parsing for hierarchical paths containing commas.
* FIXED: Added secondary ID sort to product mapping queries for deterministic pagination.

= 2.5.1 =
* NEW: Allow empty search to browse the full product catalog.
* NEW: Added detailed skip counters for auto import troubleshooting.
* FIXED: Auto import defaults changed to be less restrictive.
* FIXED: Removed API in_stock filter to rely on PHP-side filtering for 0-stock products.
* IMPROVED: Improved product search UI with default in-stock filter.
* IMPROVED: Added cache-busting for realtime metrics display.

= 2.5.0 =
* NEW: Auto Product Import - Scheduled automatic import of new products from Dropshipzone API.
* NEW: Configurable filters: New Arrivals, In Stock, Free Shipping, Min Stock Qty (default 100).
* NEW: Auto Import Metrics - Track import runs with 7-day and 30-day statistics.
* NEW: Import history table showing date, imported/skipped/errors, and status.
* IMPROVED: Compact Import Products search bar with inline layout.
* IMPROVED: Added "Auto Import" tab to plugin navigation.

= 2.4.0 =
* NEW: Shipping Zones - WooCommerce shipping method using DSZ zone mapping and per-product rates.
* NEW: Supports free shipping threshold, handling fees, and undeliverable zone detection.
* UPDATED: readme.txt and README.md with new features and roadmap.

= 2.3.2 =
* FIXED: WordPress.org compatibility - 15 unescaped output issues fixed with esc_attr().

= 2.3.1 =
* NEW: Granular resync buttons - Refresh Images, Refresh Categories, or Refresh All Data separately.

= 2.3.0 =
* NEW: Scan Unmapped Products - Check WC products against DSZ API, link found products, mark unfound as non-DSZ.
* FIXED: Resync Never Synced button was not working (wrong JavaScript variable name).

= 2.2.9 =
* FIXED: Missing return statement in ajax_resync_all() now properly exits after early return.
* FIXED: ajax_resync_never_synced() now batch fetches SKUs (100 at a time) instead of individual API calls.
* FIXED: Added frontend debouncing to prevent overlapping resync operations.
* IMPROVED: Memory limit protection for never-synced resync.
* IMPROVED: Enhanced logging for batch operations.

= 2.2.8 =
* ADDED: Resync Never Synced button - bulk resync all never-synced products.
* ADDED: Never Resynced stat box on Product Mapping page.
* FIXED: Improved last_resynced column migration reliability.
* FIXED: Migration now runs on plugin load, not just activation.

= 2.2.7 =
* ADDED: Complete API integration - all Dropshipzone endpoints now implemented.
* ADDED: get_categories() - Fetch all DSZ product categories.
* ADDED: get_zone_mapping() - Map postcodes to shipping zones.
* ADDED: get_zone_rates() - Get shipping rates per SKU per zone.

= 2.2.6 =
* ADDED: Submit orders to Dropshipzone API for fulfillment.
* ADDED: "Dropshipzone Order" meta box on WC order edit page.
* ADDED: Order tracking database table for DSZ serial numbers.
* ADDED: Australian state code mapping (NSW→New South Wales).
* NOTE: Orders created as "Not Submitted" - login to DSZ to pay.

= 2.2.5 =
* ADDED: Last Resynced column on Product Mapping page (tracks full data resync separately from price/stock sync).
* ADDED: Resync filter dropdown (Never, Today, Last 7 Days, Last 30 Days, Older).
* ADDED: Database upgrade to add `last_resynced` column.

= 2.2.4 =
* ADDED: API Load Balancer with smart adaptive delays.
* ADDED: Request statistics tracking (total requests, waits, avg wait time).
* ADDED: Skipped products count in resync completion message.
* IMPROVED: Resync now skips products already inactive (draft + out of stock).
* IMPROVED: API requests processed sequentially for rate limiting.
* IMPROVED: Proactive throttling based on API usage percentage.
* IMPROVED: Minimum 0.5s delay between requests prevents bursting.

= 2.2.3 =
* FIXED: In Stock filter now works correctly.
* IMPROVED: Cleaner Product Import page UI.
* IMPROVED: Better quick filter card styling.

= 2.2.2 =
* FIXED: Category loading from API now handles different response formats.
* ADDED: Better logging for category API calls.

= 2.2.1 =
* NEW: Product badges on import cards (Sale, Free Shipping, New Arrival).
* NEW: Dual price display showing Cost and RRP.
* NEW: Specs display with weight and brand info.
* IMPROVED: Better visual styling for already-imported products.
* IMPROVED: Lazy loading for product images.

= 2.2.0 =
* NEW: Auto-Republish on Restock - Draft products automatically republish when stock is restored.
* NEW: Stock Rules setting to enable/disable auto-republish behavior.
* IMPROVED: Logging for republish events.

= 2.1.2 =
* IMPROVED: Redesigned Logs page with stats cards and modern list view.
* NEW: Clickable filter cards showing counts by log level.
* NEW: Color-coded log items with collapsible context details.
* NEW: Relative timestamps (e.g., "5 minutes ago").

= 2.1.1 =
* FIXED: "Refresh All Product Data" now batch-fetches from API for faster performance.
* FIXED: Properly updates images, descriptions, prices, stock, and categories.
* ADDED: Memory limit check to prevent server timeouts.
* ADDED: Detailed logging for resync operations.

= 2.1.0 =
* NEW: Unified "Sync Center" page - all sync actions in one place.
* NEW: Three action cards: Link Products, Update Prices & Stock, Refresh Product Data.
* IMPROVED: Cleaner Product Mapping page focused on manual mapping.
* IMPROVED: Inline schedule settings on Sync Center page.

= 2.0.9 =
* IMPROVED: Clearer sync button labels ("Update Prices & Stock", "Link Products by SKU", "Refresh All Product Data").
* IMPROVED: Better descriptions explaining what each action does.
* IMPROVED: Reorganized Product Mapping page with separate "Link" and "Maintenance" sections.

= 2.0.8 =
* IMPROVED: Full WordPress.org plugin repository compatibility.
* IMPROVED: Plugin renamed to "DropshipZone Sync" for clarity.
* ADDED: Languages folder with POT template for translations.
* ADDED: Future roadmap section in README.

= 2.0.7 =
* IMPROVED: Modernized Sync Control UI with card-based dashboard.
* IMPROVED: Real-time progress visualization and status indicators.

= 2.0.6 =
* ADDED: Auto-deactivation of products not found in the Dropshipzone API.
* IMPROVED: Catalog synchronization accuracy.

= 2.0.5 =
* ADDED: Advanced search filters (Category, Stock, Promotion, Free Shipping, New Arrivals)
* ADDED: Sorting by price
* IMPROVED: Search logic using API-native keywords for better accuracy

= 2.0.4 =
* IMPROVED: Reordered navigation for better user workflow
* IMPROVED: Import cards now show category, stock status, and description preview
* Changed import icon and label for clarity

= 2.0.3 =
* FIXED: Product categories not importing (API uses 'Category' field with capital C)
* Categories now create proper hierarchical structure (e.g., "Appliances > Air Conditioners")
* Added update_categories option to resync functionality

= 2.0.2 =
* FIXED: Product descriptions not importing (API uses 'desc' field not 'description')
* Added debug logging for description import tracking

= 2.0.1 =
* NEW: API Rate Limiting - Smart throttling to comply with Dropshipzone API limits (60/min, 600/hour)
* NEW: Auto-Deactivate Products - Products not found in Dropshipzone API are automatically set to Draft
* Added Rate_Limiter class for proactive API throttle management
* Added "Deactivate Missing Products" option in Stock Rules settings
* Improved logging for rate limit and deactivation events

= 2.0.0 =
* New Product Import feature: Search and import products directly from Dropshipzone API
* Core Product Importer class for automated product creation and image sideloading
* Dynamic rule application (Price & Stock) during product import
* Automated Product Mapping for all imported items
* Enhanced Admin UI with dedicated Product Import dashboard and grid layout results

= 1.0.0 =
* Initial release
* Price sync with percentage/fixed markup
* Stock sync with buffer support
* GST calculation (include/exclude)
* Price rounding options
* Scheduled sync (hourly/twice daily/daily)
* Manual sync with progress indicator
* Batch processing for large catalogs
* Comprehensive logging system
* Modern admin UI

== Upgrade Notice ==

= 2.0.2 =
Bug fix: Product descriptions now import correctly from the Dropshipzone API.

= 2.0.0 =
Major update with new Product Import feature! You can now search and import products directly from Dropshipzone API.

= 1.0.0 =
Initial release of DropshipZone Sync.
