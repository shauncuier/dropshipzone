# 🇦🇺 DropshipZone Sync for WooCommerce

<div align="center">

[![GitHub Release](https://img.shields.io/github/v/release/shauncuier/dropshipzone?label=version&color=blue)](https://github.com/shauncuier/dropshipzone/releases/latest)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B.svg?logo=wordpress)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A.svg?logo=woocommerce)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg?logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

**The official integration plugin for Australian dropshippers using [Dropshipzone](https://dropshipzone.com.au).**

Automatically sync 10,000+ products with real-time pricing, stock levels, and seamless product imports.

[📦 Download Latest Release](https://github.com/shauncuier/dropshipzone/releases/latest) · [📖 Documentation](https://github.com/shauncuier/dropshipzone/wiki) · [🐛 Report Bug](https://github.com/shauncuier/dropshipzone/issues) · [✨ Request Feature](https://github.com/shauncuier/dropshipzone/discussions)

</div>

---

## 🚀 What's New

See [CHANGELOG.md](CHANGELOG.md) for full release notes, or check the [latest release](https://github.com/shauncuier/dropshipzone/releases/latest).

**Version 2.8.0 Highlights:**
- **🔌 Developer Hooks** - `dsz_calculated_price`/`dsz_calculated_stock` filters and `dsz_sync_completed`/`dsz_price_updated` actions are live
- **🧹 Daily Maintenance** - Automatic log retention, orphaned mapping cleanup, transient purging
- **🚫 Supplier Blacklist** - Exclude suppliers from Auto Import
- **💾 Import Templates & CSV Export** - Save filter presets; export mappings

**Version 2.7.0 Highlights:**
- **🇳🇿 New Zealand Shipping** - Flat-rate shipping support for NZ destinations (standard scheme `nz` key)
- **🛡️ Treat $0 Rates as Unavailable** - Prevents unintended free shipping when a supplier has not priced a zone
- **⚡ Performance & Resilience** - Batched mapping queries for cart variations and negative caching to protect checkouts from API downtime

**Previous releases:**
- **🤖 Auto Product Import** - Schedule automatic imports with customizable filters (v2.5.0)
- **📊 Import Metrics** - Track import runs with 7-day, 30-day stats and history (v2.5.0)
- **🚚 Shipping Zones** - WooCommerce shipping method using DSZ zone mapping (v2.4.0)
- **🔍 Scan Unmapped Products** - Auto-detect and link existing WC products to DSZ (v2.3.0)

---

## 🔮 Future Plans (Roadmap)

We're constantly working to improve DropshipZone Sync! Here's our comprehensive development roadmap:

### ✅ Completed Features
| Feature | Description | Version |
|---------|-------------|:-------:|
| **Developer Hooks** | Price/stock filters + sync lifecycle actions | v2.8.0 |
| **Scheduled Maintenance** | Auto-cleanup of old logs, orphaned mappings, stale caches | v2.8.0 |
| **Supplier Blacklist** | Exclude specific suppliers from import | v2.8.0 |
| **Import Templates** | Save and reuse import filter configurations | v2.8.0 |
| **Export Tools** | Export product mappings as CSV | v2.8.0 |
| **Database Optimization** | Composite indexes for large-catalog sync queries | v2.8.0 |
| **New Zealand Shipping** | Flat-rate shipping support for NZ destinations | v2.7.0 |
| **Negative Caching** | 5-minute transient back-off cache on API failures | v2.7.0 |
| **Batch Auto-Mapping** | Process SKU auto-mapping in safe batches of 500 | v2.6.0 |
| **Admin UI Theme Toggle** | Opt-in dark theme toggle in the header | v2.6.3 |
| **Auto Product Import** | Scheduled imports with filters and metrics | v2.5.0 |
| **Shipping Zones** | WooCommerce shipping using DSZ zone rates | v2.4.0 |
| **Scan Unmapped Products** | Auto-link existing products to DSZ | v2.3.0 |
| **Granular Resync** | Refresh images, categories, or all separately | v2.3.1 |
| **Order Submission** | Submit orders to DSZ for fulfillment | v2.2.6 |
| **API Load Balancer** | Smart throttling with adaptive delays | v2.2.4 |

### 🔴 High Priority (Coming Soon)
| Feature | Description | Status |
|---------|-------------|:------:|
| **Advanced Price Rules** | Category-based and supplier-based pricing | 🚧 In Progress |
| **Tracking Number Sync** | Auto-import tracking numbers from DSZ orders and update WC orders | 📋 Planned |
| **Bulk Order Submission** | Submit multiple orders to DSZ at once | 📋 Planned |
| **Webhook Support** | Real-time updates via DSZ webhooks | ⏸️ Waiting on DSZ (no webhook API exists yet) |

### 🟡 Medium Priority
| Feature | Description | Status |
|---------|-------------|:------:|
| **Product Variations** | Full support for variable products from DSZ | 📋 Planned |
| **Email Notifications** | Get notified on sync errors, low stock, price changes | 📋 Planned |
| **Auto-Repricing** | Adjust prices based on competitor analysis | 📋 Planned |
| **Inventory Alerts** | Low stock warnings with configurable thresholds | 📋 Planned |
| **Import Scheduling** | Schedule specific import times (not just intervals) | 📋 Planned |
| **Category Mapping** | Map DSZ categories to custom WC categories | 📋 Planned |

### 🟢 Low Priority / Under Consideration
| Feature | Description | Status |
|---------|-------------|:------:|
| **Profit Calculator** | View margins on product and order level | 📋 Planned |
| **REST API Endpoints** | Expose sync functionality via REST API | 📋 Planned |
| **Sync Analytics Dashboard** | Charts showing sync history, errors, trends | 📋 Planned |
| **Product Compare** | Compare local product data with DSZ data | 📋 Planned |
| **Auto-Discontinue** | Automatically handle discontinued products | 📋 Planned (surfacing of existing deactivate-if-not-found) |
| **Markup by Category** | Different markup rules per product category | 🚧 Part of Advanced Price Rules |

**Not pursuing** (with reasons): Auto-Repricing (no competitor data source in the API), Multi-supplier (out of scope for a DSZ integration), Multi-currency (API is AUD-only; currency plugins handle display), Redis/Memcached layer (transient caching is already object-cache backed), WooCommerce Blocks (plugin has no frontend surface).

### 🔧 Technical Improvements
| Feature | Description | Status |
|---------|-------------|:------:|
| **Background Processing** | Move heavy tasks to Action Scheduler | 📋 Planned |
| **Unit Tests** | Comprehensive PHPUnit test suite | 📋 Planned |
| **CLI Commands** | WP-CLI commands for sync operations | 📋 Planned |

### Legend
| Icon | Status |
|:----:|--------|
| ✅ | **Complete** - Feature is available now |
| 🚧 | **In Progress** - Currently being developed |
| 📋 | **Planned** - Feature is in our development roadmap |
| 💭 | **Considering** - Under evaluation based on user feedback |

> 💡 **Have a feature request?** [Submit it here](https://github.com/shauncuier/dropshipzone/discussions) and help shape the future of the plugin!

---

## ✨ Features

### Core Synchronization
| Feature | Description |
|---------|-------------|
| 🔄 **Price Sync** | Automatically update regular and sale prices from supplier |
| 📦 **Stock Sync** | Keep stock quantities accurate in real-time |
| ⏰ **Scheduled Sync** | Hourly, twice daily, or daily options |
| ▶️ **Manual Sync** | Run sync anytime with one click |
| 📊 **Batch Processing** | Handles 10,000+ products efficiently |

### Product Management
| Feature | Description |
|---------|-------------|
| 🛍️ **Product Import** | Import products directly from Dropshipzone catalog |
| 🤖 **Auto Import** | Schedule automatic imports with customizable filters |
| 📈 **Import Metrics** | Track imports with 7-day, 30-day stats and history |
| 🗺️ **Product Mapping** | Link WooCommerce products to Dropshipzone SKUs |
| 🔍 **Scan Unmapped** | Auto-detect and link existing WC products to DSZ |
| 🔃 **Granular Resync** | Refresh images, categories, or all data separately |

### Order & Shipping
| Feature | Description |
|---------|-------------|
| 📤 **Order Submission** | Submit orders to Dropshipzone for fulfillment |
| 🚚 **Shipping Zones** | WooCommerce shipping using DSZ zone mapping and per-product rates |

### Pricing & Rules
| Feature | Description |
|---------|-------------|
| 💰 **Flexible Pricing** | Percentage or fixed markup options |
| 🧮 **GST Support** | Include or exclude 10% Australian GST |
| 🔢 **Price Rounding** | Round to .99, .95, or nearest dollar |
| 🛡️ **Stock Buffer** | Subtract units to prevent overselling |
| ⚙️ **Min Stock Filter** | Only import products with sufficient stock |

### Technical
| Feature | Description |
|---------|-------------|
| 🏷️ **SKU Matching** | Products matched by SKU for accuracy |
| ⚡ **API Load Balancer** | Smart throttling with adaptive delays |
| 📝 **Detailed Logging** | Track all sync activity and errors |
| 🎨 **Modern UI** | Beautiful, responsive admin interface |

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 6.0 or higher |
| WooCommerce | 8.0 or higher |
| PHP | 7.4 or higher |
| Dropshipzone | API account required |

---

## 🚀 Installation

### Option 1: From WordPress Admin (Recommended)

1. Download the latest release `.zip` file from [Releases](https://github.com/shauncuier/dropshipzone/releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip file and click **Install Now**
4. Activate the plugin

### Option 2: Manual Installation

```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/shauncuier/dropshipzone.git
```

### Option 3: From GitHub Releases

1. Download the latest `dropshipzone-*.zip` from [Releases](https://github.com/shauncuier/dropshipzone/releases)
2. Extract and upload to `/wp-content/plugins/`
3. Activate through WordPress admin

---

## ⚙️ Quick Start

### 1️⃣ Configure API Settings
Navigate to **DSZ Sync → API Settings** and enter your Dropshipzone credentials:
- API Email
- API Password

Click **Test Connection** to verify.

### 2️⃣ Set Price Rules
Configure your pricing strategy:
- **Markup Type**: Percentage or Fixed amount
- **Markup Value**: Your desired markup (e.g., 30%)
- **GST Options**: Include or exclude 10% Australian GST
- **Rounding**: Round to .99, .95, or nearest dollar

### 3️⃣ Configure Stock Rules
- **Stock Buffer**: Units to subtract (prevents overselling)
- **Out of Stock Handling**: How to handle zero stock items

### 4️⃣ Import or Map Products
- **Import**: Search and import products from Dropshipzone catalog
- **Auto-Map**: Automatically matches existing products by SKU
- **Manual Map**: Manually link products to specific SKUs

### 5️⃣ Run Sync
- Navigate to **DSZ Sync → Sync Control**
- Click **Run Sync Now** or configure a schedule

---

## 📊 Rate Limiting

This plugin respects Dropshipzone's API throttle limits:

| Limit | Value |
|-------|-------|
| Requests per minute | 60 |
| Requests per hour | 600 |

The built-in rate limiter automatically:
- ✅ Tracks all API requests
- ✅ Waits when approaching limits
- ✅ Prevents rate limit errors
- ✅ Logs throttling events

---

## 🔧 Developer Hooks

### Filters

```php
// Modify price before saving
add_filter('dsz_calculated_price', function($price, $product_id, $supplier_price) {
    return $price;
}, 10, 3);

// Modify stock before saving
add_filter('dsz_calculated_stock', function($stock, $product_id, $supplier_stock) {
    return $stock;
}, 10, 3);
```

### Actions

```php
// After sync completes
add_action('dsz_sync_completed', function($stats) {
    // $stats contains 'updated', 'skipped', 'errors'
});

// After product price updated
add_action('dsz_price_updated', function($product_id, $old_price, $new_price) {
    // Do something after price update
}, 10, 3);
```

---

## 📖 Documentation

- [📚 Full Documentation](https://github.com/shauncuier/dropshipzone/wiki)
- [🔌 API Documentation](API-DOCUMENTATION.md)
- [📝 Changelog](CHANGELOG.md)
- [🤝 Contributing Guidelines](CONTRIBUTING.md)

---

## 🤝 Contributing

Contributions are welcome! Please read our [Contributing Guidelines](CONTRIBUTING.md) first.

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Commit your changes: `git commit -m 'Add amazing feature'`
4. Push to the branch: `git push origin feature/amazing-feature`
5. Open a Pull Request

---

## 📄 License

This project is licensed under the GPL v2 or later - see the [LICENSE](LICENSE) file for details.

---

## 🆘 Support

| Channel | Link |
|---------|------|
| 📖 Documentation | [Wiki](https://github.com/shauncuier/dropshipzone/wiki) |
| 💬 Discussions | [GitHub Discussions](https://github.com/shauncuier/dropshipzone/discussions) |
| 🐛 Issues | [GitHub Issues](https://github.com/shauncuier/dropshipzone/issues) |
| 📧 Email | support@dropshipzone.com.au |

---

## 💖 Support the Project

If you find this plugin useful, please consider:

- ⭐ **Star this repository** to show your support
- 🐛 **Report bugs** to help improve the plugin
- 💡 **Suggest features** in discussions
- ☕ **Buy us a coffee**: [buymeacoffee.com/shauncuier](https://buymeacoffee.com/shauncuier)

Your support helps us maintain the plugin and add new features!

---

## 🙏 Credits

<table>
  <tr>
    <td align="center">
      <strong>Developed by</strong><br>
      <a href="https://3s-soft.com">3s-Soft</a>
    </td>
    <td align="center">
      <strong>Built for</strong><br>
      <a href="https://dropshipzone.com.au">Dropshipzone Australia</a>
    </td>
    <td align="center">
      <strong>Powered by</strong><br>
      <a href="https://woocommerce.com">WooCommerce</a>
    </td>
  </tr>
</table>

---

<div align="center">

**Made with ❤️ for Australian Dropshippers**

[![GitHub stars](https://img.shields.io/github/stars/shauncuier/dropshipzone?style=social)](https://github.com/shauncuier/dropshipzone)
[![GitHub forks](https://img.shields.io/github/forks/shauncuier/dropshipzone?style=social)](https://github.com/shauncuier/dropshipzone/fork)

</div>
