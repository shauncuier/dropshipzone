# 🇦🇺 3S Soft Price & Stock Sync for Dropshipzone

<div align="center">

[![WordPress Plugin Directory](https://img.shields.io/badge/WordPress.org-Plugin%20Directory-21759B.svg?logo=wordpress&logoColor=white)](https://wordpress.org/plugins/3s-soft-price-stock-sync-for-dropshipzone/)
[![GitHub Release](https://img.shields.io/github/v/release/shauncuier/dropshipzone?label=version&color=blue)](https://github.com/shauncuier/dropshipzone/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-21759B.svg?logo=wordpress)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A.svg?logo=woocommerce)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg?logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

**A WooCommerce integration for Australian dropshippers using [Dropshipzone](https://www.dropshipzone.com.au).**

*This plugin is an independent integration. It is not affiliated with, endorsed by, or sponsored by Dropshipzone Pty Ltd. "Dropshipzone" is a trademark of its respective owner and is used here only to describe the service the plugin connects to.*

Sync prices, stock and shipping rates from the Dropshipzone supplier API, import catalogue products, and submit orders for fulfilment.

[🌐 Official WordPress Plugin](https://wordpress.org/plugins/3s-soft-price-stock-sync-for-dropshipzone/) · [📦 Download Latest Release](https://github.com/shauncuier/dropshipzone/releases/latest) · [🐛 Report Bug](https://github.com/shauncuier/dropshipzone/issues) · [✨ Request Feature](https://github.com/shauncuier/dropshipzone/discussions)

</div>

---

## 🚀 What's New

See [CHANGELOG.md](CHANGELOG.md) for full release notes.

**Version 3.3.x — WordPress.org compliance**
- **🏷️ Renamed** to `3S Soft Price & Stock Sync for Dropshipzone` (slug `3s-soft-price-stock-sync-for-dropshipzone`)
- **🔤 Code prefix widened** from `dsz_` to `dszsync_`, with a one-time migration — **see [Upgrading from 3.0.x or earlier](#-upgrading-from-30x-or-earlier)**
- **🔒 Security** — client-supplied product data is now fully sanitized before import; output escaping corrected throughout
- **🗄️ SQL** — table identifiers use the `%i` placeholder, so every query is genuinely prepared
- **📄 External Services disclosure** documenting every API call and the data it carries

**Version 3.0.0 — Orders**
- **📮 Tracking Number Sync** — pulls tracking and status from Dropshipzone into WooCommerce orders
- **📦 Bulk & Auto Order Submission** — orders-list bulk action, chunked bulk tool, optional submit-on-payment
- **💵 Profit Calculator** — per-order profit and a margin column on the products list

**Version 2.9.0 — Pricing**
- **🎯 Advanced Price Rules** — per-category, per-supplier or per-SKU-prefix markup overrides

---

## ✨ Features

### Core Synchronization
| Feature | Description |
|---------|-------------|
| 🔄 **Price Sync** | Update regular and sale prices from supplier cost |
| 📦 **Stock Sync** | Keep stock quantities accurate, with buffer and out-of-stock rules |
| ⏰ **Scheduled Sync** | Hourly, twice daily, or daily |
| ▶️ **Manual Sync** | Run any time from the Sync Center |
| 📊 **Batch Processing** | Chunked with continuation, handles large catalogues |

### Product Management
| Feature | Description |
|---------|-------------|
| 🛍️ **Product Import** | Search and import from the Dropshipzone catalogue |
| 🤖 **Auto Import** | Scheduled imports with filters and a supplier blacklist |
| 💾 **Import Templates** | Save and reuse filter presets |
| 📈 **Import Metrics** | 7-day and 30-day stats plus run history |
| 🗺️ **Product Mapping** | Link WooCommerce products to Dropshipzone SKUs |
| 🔍 **Scan Unmapped** | Detect and link existing products automatically |
| 🔃 **Granular Resync** | Refresh images, categories, or everything |
| 📤 **CSV Export** | Export all product mappings |

### Order & Shipping
| Feature | Description |
|---------|-------------|
| 📮 **Order Submission** | Manual, bulk, or automatic on payment |
| 🚚 **Shipping Rates** | Live rates from Dropshipzone zone mapping and per-SKU rates |
| 🇳🇿 **New Zealand** | Flat-rate NZ shipping via the standard scheme |
| 📍 **Tracking Sync** | Tracking numbers and status pulled back into orders |
| 💵 **Profit Reporting** | Order profit and products-list margin column |

### Pricing & Rules
| Feature | Description |
|---------|-------------|
| 💰 **Flexible Markup** | Percentage or fixed amount |
| 🎯 **Advanced Rules** | Override markup by category, supplier or SKU prefix |
| 🧮 **GST Support** | Include or add 10% Australian GST |
| 🔢 **Price Rounding** | .99, .95, or nearest dollar |
| 🛡️ **Stock Buffer** | Subtract units to prevent overselling |

### Technical
| Feature | Description |
|---------|-------------|
| 🏷️ **SKU Matching** | Products matched by SKU, variations included |
| ⚡ **Rate Limiter** | Adaptive throttling that never blocks a request thread for long |
| 🧹 **Daily Maintenance** | Log retention, orphaned mapping cleanup, cache purging |
| 📝 **Detailed Logging** | Filterable log with CSV export |
| 🎨 **Modern Admin UI** | Token-based design system with an optional dark theme |

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 6.2 or higher |
| WooCommerce | 8.0 or higher |
| PHP | 7.4 or higher |
| Dropshipzone | API account required |

> WordPress 6.2 is the minimum because the plugin uses the `%i` identifier
> placeholder in prepared statements, which that release introduced.

---

## 🚀 Installation

### From WordPress Admin (recommended)

1. Download the latest `3s-soft-price-stock-sync-for-dropshipzone-*.zip` from [Releases](https://github.com/shauncuier/dropshipzone/releases/latest)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and click **Install Now**
4. Activate

### Manual

Extract the zip into `/wp-content/plugins/` so the folder is named
`3s-soft-price-stock-sync-for-dropshipzone`, then activate through the
WordPress admin.

---

## ⬆️ Upgrading from 3.0.x or earlier

Version 3.3.0 widened the internal prefix from `dsz_` to `dszsync_` to meet
the WordPress.org four-character minimum. **A one-time migration runs
automatically on the first load after upgrading** and moves:

- All plugin options (API credentials, price and stock rules, auto-import settings)
- Post meta (`_dsz_cost` → `_dszsync_cost`, tracking numbers, and the rest)
- Order meta, including HPOS storage
- Scheduled cron events
- The shipping method id, re-pointing any configured WooCommerce shipping zones

Custom database table and column names deliberately keep the `dsz_` form.
They are scoped inside plugin-owned tables, cannot collide with anything,
and renaming them would mean an `ALTER TABLE` on live data for no benefit.

**If you use the developer hooks, note that they were renamed too** — see
below.

---

## ⚙️ Quick Start

### 1️⃣ Configure API Settings
**DSZ Sync → API Settings** — enter your Dropshipzone email and password,
then click **Test Connection**.

### 2️⃣ Set Price Rules
Markup type and value, GST handling, and rounding. Optionally add advanced
rules that override the global markup for specific categories, suppliers or
SKU prefixes.

### 3️⃣ Configure Stock Rules
Stock buffer, out-of-stock handling, and whether to draft products that
disappear from the supplier catalogue.

### 4️⃣ Import or Map Products
- **Import** — search the catalogue and import
- **Auto-Map** — match existing products by SKU
- **Scan Unmapped** — check existing products against the API in bulk

### 5️⃣ Run Sync
**DSZ Sync → Sync Center** — run once, or set a schedule.

### 6️⃣ Shipping (optional)
The plugin registers a **Dropshipzone Shipping** method and attaches it to
Australia and New Zealand zones on activation. Add a fallback cost so
checkout still quotes when the API is unreachable.

---

## 📊 Rate Limiting

The plugin respects Dropshipzone's documented throttle limits:

| Limit | Value |
|-------|-------|
| Requests per minute | 60 |
| Requests per hour | 600 |

The rate limiter tracks usage, applies adaptive delays as limits approach,
and **never blocks a request thread for more than 15 seconds**. Longer waits
return a retryable error so admin screens and checkout stay responsive.

---

## 🔧 Developer Hooks

> **Renamed in 3.3.0.** These were previously prefixed `dsz_`. If you
> registered callbacks against the old names, update them — the old hooks
> no longer fire.

### Filters

```php
// Modify the calculated price before it is saved
add_filter( 'dszsync_calculated_price', function ( $price, $product_id, $supplier_cost ) {
    return $price;
}, 10, 3 );

// Modify the calculated stock quantity before it is saved
add_filter( 'dszsync_calculated_stock', function ( $stock, $product_id, $supplier_stock ) {
    return $stock;
}, 10, 3 );

// Change how long logs are retained (days)
add_filter( 'dszsync_log_retention_days', function ( $days ) {
    return 90;
} );

// Cap how many /stock pages one fast-stock-update run reads.
// 160 change rows per page; the full sync picks up anything left over.
add_filter( 'dszsync_incremental_max_pages', function ( $pages ) {
    return 20;
} );

// How far back tracking sync looks for orders still awaiting tracking (days)
add_filter( 'dszsync_tracking_lookback_days', function ( $days ) {
    return 90;
} );
```

### Actions

```php
// After a full sync run completes
add_action( 'dszsync_sync_completed', function ( $stats ) {
    // $stats: [ 'updated' => int, 'skipped' => int, 'errors' => int ]
} );

// After a product price is updated
add_action( 'dszsync_price_updated', function ( $product_id, $old_price, $new_price ) {
    // ...
}, 10, 3 );
```

---

## 📖 Documentation

- [🔌 Dropshipzone API notes](API-NOTES.md) — endpoint reference, field semantics, and integration gotchas
- [📝 Changelog](CHANGELOG.md)
- [🤝 Contributing Guidelines](CONTRIBUTING.md)
- [📁 Design and planning docs](doc/) — repo-only, excluded from release builds

---

## 🤝 Contributing

Contributions are welcome. Please read the [Contributing Guidelines](CONTRIBUTING.md) first.

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Commit your changes
4. Push and open a Pull Request

Before submitting, run [Plugin Check](https://wordpress.org/plugins/plugin-check/)
against your build — the plugin currently passes with no errors or warnings,
and it is worth keeping it that way.

---

## 📄 License

GPL v2 or later — see [LICENSE](LICENSE).

---

## 🆘 Support

| Channel | Link |
|---------|------|
| 💬 Discussions | [GitHub Discussions](https://github.com/shauncuier/dropshipzone/discussions) |
| 🐛 Issues | [GitHub Issues](https://github.com/shauncuier/dropshipzone/issues) |
| 🏢 Developer | [3s-soft.com](https://3s-soft.com) |

For questions about the Dropshipzone **service** — accounts, pricing,
stock accuracy, or fulfilment — contact Dropshipzone directly. This plugin
is an independent integration and its maintainers cannot help with supplier
account matters.

---

## 💖 Support the Project

- ⭐ **Star this repository**
- 🐛 **Report bugs** so they can be fixed
- 💡 **Suggest features** in discussions
- ☕ **Buy us a coffee**: [buymeacoffee.com/shauncuier](https://buymeacoffee.com/shauncuier)

---

## 🙏 Credits

Developed by [3s-Soft](https://3s-soft.com) for WooCommerce stores that
source from [Dropshipzone](https://www.dropshipzone.com.au).

Built on [WooCommerce](https://woocommerce.com).

---

<div align="center">

**Made with ❤️ for Australian Dropshippers**

[![GitHub stars](https://img.shields.io/github/stars/shauncuier/dropshipzone?style=social)](https://github.com/shauncuier/dropshipzone)
[![GitHub forks](https://img.shields.io/github/forks/shauncuier/dropshipzone?style=social)](https://github.com/shauncuier/dropshipzone/fork)

</div>
