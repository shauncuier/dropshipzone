# Contributing

Thank you for considering a contribution to **3S Soft Price & Stock Sync
for Dropshipzone**.

## Code of Conduct

By participating you are expected to uphold our
[Code of Conduct](CODE_OF_CONDUCT.md).

## Security Issues

**Do not open a public issue for a security problem.** Follow the
[Security Policy](SECURITY.md) instead.

---

## Getting Set Up

You need a WordPress site with WooCommerce active and a Dropshipzone API
account. A local environment (Local, Herd, wp-env, Docker) is fine.

```bash
git clone https://github.com/shauncuier/dropshipzone.git
```

Symlink or copy the repository into `wp-content/plugins/` as
`3s-soft-price-stock-sync-for-dropshipzone`. **The folder name matters** —
it must match the plugin slug or WordPress will treat updates as a
different plugin.

Build a distributable zip with:

```powershell
.\build.ps1
```

The zip lands in `build/` with the correct root folder and excludes
development files. `doc/`, `tests/`, `*.md`, and the build scripts never
ship.

**`build/` is disposable.** `build.ps1` deletes the whole directory on every
run. Never keep anything there you cannot regenerate — in particular the
WordPress.org SVN working copy, which lives *outside* the repository at
`../dropshipzone-svn`.

---

## Before You Open a Pull Request

Run these. A PR that fails any of them will be sent back.

**1. Lint everything**

```bash
php -l <each changed .php file>
node --check assets/admin.js
```

**2. Run Plugin Check**

Install [Plugin Check](https://wordpress.org/plugins/plugin-check/) and run
it against your build. The plugin currently passes with **no errors and no
warnings** — keep it that way. If you must suppress a sniff, use a
`phpcs:ignore` with a specific justification, and name the sniff exactly.
A misspelled sniff name silently does nothing.

**3. Run the regression suites**

```
php tests/run.php
```

85 assertions across three suites, no WordPress install and no network
required — see [tests/README.md](tests/README.md). They load the real
classes from `includes/` against stubs, so they fail when the shipped code
regresses, not when a copy drifts. Run them before every release.

**4. Exercise what you changed**

The suites cover pricing, the incremental window and the memory guard —
nothing else. For everything else, drive the actual flow in the admin: run
a sync, import a product, place a test order, load the checkout with a
mapped product in the cart. Static checks do not catch a broken query.

---

## Conventions

### Prefixes

Everything global uses the `dszsync_` prefix — functions, options, post
meta, cron hooks, filters, actions, AJAX actions, nonces, transients.
Constants use `DSZSYNC_`. Classes live under the `Dropshipzone` namespace.

Database table and column names keep the older `dsz_` form deliberately.
They are scoped inside plugin-owned tables and renaming them would mean an
`ALTER TABLE` on live data for no benefit.

**If you rename anything stored in the database, add a migration.** See
`maybe_migrate_prefix()` in the main plugin file for the pattern.

### SQL

Table identifiers use the `%i` placeholder, not string concatenation:

```php
$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE level = %s', $table, $level ) );
```

This is why the plugin requires WordPress 6.2 or newer.

### Escaping and Sanitization

Escape at the point of output, using the function that fits the context —
`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`. Escape the
*composed* string, not the format string:

```php
// wrong — escapes the format, not the values
printf( esc_html__( 'Showing %d logs', 'domain' ), $total );

// right
echo esc_html( sprintf( __( 'Showing %d logs', 'domain' ), $total ) );
```

Sanitize input as early as possible, and `wp_unslash()` before sanitizing.

### The Dropshipzone API

Read [API-NOTES.md](API-NOTES.md) before touching anything that talks to
the API. It documents rate limits, pagination bounds, date-range caps, and
several fields whose behaviour is not what the official docs imply.

All requests go through `API_Client`. Do not add raw HTTP calls — the rate
limiter and retry handling live there.

### Commits

- Present tense, imperative mood: "Add feature", not "Added feature"
- Explain **why** in the body, not just what — the diff already shows what
- Reference issues after the first line

---

## Reporting Bugs

Search the [issue tracker](https://github.com/shauncuier/dropshipzone/issues)
first, then open a new issue with:

- Steps to reproduce, as specifically as you can manage
- What you expected versus what happened
- Plugin, WordPress, WooCommerce and PHP versions
- Relevant entries from **DSZ Sync → Logs**

## Suggesting Features

Open a [discussion](https://github.com/shauncuier/dropshipzone/discussions)
describing the problem you are trying to solve, not just the solution you
have in mind. The [README roadmap](README.md#-features) shows what is
already planned.

## Questions

Open a [discussion](https://github.com/shauncuier/dropshipzone/discussions).
For questions about the Dropshipzone service itself — accounts, pricing,
stock accuracy, fulfilment — contact Dropshipzone Pty Ltd directly. This
plugin is an independent integration.
