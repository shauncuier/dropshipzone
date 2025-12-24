=== DropshipZone Sync ===
Contributors: 3s-Soft, dropshipzone
Tags: woocommerce, dropshipzone, price sync, stock sync, dropshipping
Requires at least: 6.0
Tested up to: 6.7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.4
Stable tag: 2.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically sync product prices and stock levels from Dropshipzone API to your WooCommerce store.

== Description ==

**DropshipZone Sync** is a lightweight, enterprise-grade WooCommerce plugin that automatically synchronizes product prices and stock levels from the Dropshipzone API.

= Key Features =

* **Price Sync** - Automatically update regular and sale prices
* **Stock Sync** - Keep stock quantities accurate in real-time
* **SKU Matching** - Products matched by SKU for accuracy
* **Flexible Pricing** - Percentage or fixed markup options
* **GST Support** - Include or exclude 10% Australian GST
* **Price Rounding** - Round to .99, .95, or nearest dollar
* **Stock Buffer** - Subtract units to prevent overselling
* **Scheduled Sync** - Hourly, twice daily, or daily options
* **Manual Sync** - Run sync anytime with one click
* **Batch Processing** - Handles 10,000+ products efficiently
* **Detailed Logging** - Track all sync activity and errors

= Important Notes =

* This plugin ONLY syncs price and stock
* Does NOT create or import new products
* Does NOT modify titles, descriptions, images, or categories
* Requires WooCommerce to be installed and active
* Requires Dropshipzone API credentials

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
6. Logs - View detailed sync activity and errors

== Changelog ==

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
