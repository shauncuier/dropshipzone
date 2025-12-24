# Changelog

All notable changes to the DropshipZone Sync plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

## [Unreleased]

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

[2.0.1]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.1
[2.0.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v2.0.0
[1.0.0]: https://github.com/shauncuier/dropshipzone/releases/tag/v1.0.0
