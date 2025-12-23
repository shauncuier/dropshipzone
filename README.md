# 🇦🇺 Dropshipzone Price & Stock Sync for WooCommerce

<div align="center">

![Plugin Banner](assets/banner-1544x500.png)

[![WordPress Plugin Version](https://img.shields.io/badge/version-2.0.3-blue.svg)](https://github.com/shauncuier/dropshipzone/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B.svg?logo=wordpress)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A.svg?logo=woocommerce)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg?logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](LICENSE)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](CONTRIBUTING.md)

**The official integration plugin for Australian dropshippers using [Dropshipzone](https://dropshipzone.com.au).**

Automatically sync 10,000+ products with real-time pricing, stock levels, and seamless product imports.

[📦 Download Latest Release](https://github.com/shauncuier/dropshipzone/releases) · [📖 Documentation](https://github.com/shauncuier/dropshipzone/wiki) · [🐛 Report Bug](https://github.com/shauncuier/dropshipzone/issues) · [✨ Request Feature](https://github.com/shauncuier/dropshipzone/discussions)

</div>

---

## 🚀 What's New in v2.0.3

- **📂 Category Import** - Categories now import correctly with proper hierarchical structure
- **🐛 Description Fix** - Product descriptions import correctly from the API
- **⚡ Rate Limiting** - Smart API throttling (60/min, 600/hour)
- **🗑️ Auto-Deactivate** - Products not found in API are automatically set to Draft

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| 🔄 **Price Sync** | Automatically update regular and sale prices from supplier |
| 📦 **Stock Sync** | Keep stock quantities accurate in real-time |
| 🛍️ **Product Import** | Import products directly from Dropshipzone catalog |
| 🏷️ **SKU Matching** | Products matched by SKU for accuracy |
| 💰 **Flexible Pricing** | Percentage or fixed markup options |
| 🧮 **GST Support** | Include or exclude 10% Australian GST |
| 🔢 **Price Rounding** | Round to .99, .95, or nearest dollar |
| 🛡️ **Stock Buffer** | Subtract units to prevent overselling |
| ⏰ **Scheduled Sync** | Hourly, twice daily, or daily options |
| ▶️ **Manual Sync** | Run sync anytime with one click |
| 📊 **Batch Processing** | Handles 10,000+ products efficiently |
| 📝 **Detailed Logging** | Track all sync activity and errors |
| ⚡ **Rate Limiting** | Smart API throttling to prevent limits |
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

1. Download `dropshipzone-price-stock-sync-v2.0.0.zip` from [Releases](https://github.com/shauncuier/dropshipzone/releases)
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
