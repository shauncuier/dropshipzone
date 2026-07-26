# WordPress.org review — reply

Their four steps, and where each stands:

| # | Step | Status |
|---|---|---|
| 1 | Update display name in readme and plugin headers | Done — `3S Soft Price & Stock Sync for Dropshipzone` |
| 2 | Update the slug in plugin files (i18n functions) | Done — text domain `3s-soft-price-stock-sync-for-dropshipzone` |
| 3 | Reply requesting a new slug reservation | **Send the message below** |
| 4 | Upload a new version via "Add your plugin" | After sending; they said not to wait for confirmation |

They asked for brevity — "be brief and direct in your reply, please avoid copy-pasting bloated AI responses" and "do not list the changes done, we will review the entire plugin again". The message below is deliberately short.

---

## Reply (send on the existing review thread, keep the subject line)

Hello,

Requesting a new slug reservation:

**Slug:** `3s-soft-price-stock-sync-for-dropshipzone`
**Display name:** 3S Soft Price & Stock Sync for Dropshipzone

I am not affiliated with Dropshipzone Pty Ltd — they are a supplier my plugin integrates with. The new name uses my own vendor prefix with the trademark only in the trailing "for Dropshipzone" position, and the readme states the lack of affiliation explicitly. Display name and text domain both match the requested slug.

I have worked through the naming, ownership, sanitization, escaping, prefixing, external services and directory-assets points from your email. A new version is uploaded.

One note on the prefix: I widened `dsz_` to `dszsync_` across functions, options, post meta, hooks, AJAX actions and the shipping method id, with a migration for existing installs. I left the custom database table and column names on the old form, as they are scoped inside tables the plugin owns and renaming them would mean an ALTER TABLE on live data for no collision benefit. Happy to change that if you would prefer consistency.

Thanks,
Jashe (shauncuier)

---

## Then upload

`build/3s-soft-price-stock-sync-for-dropshipzone-v3.3.0.zip` — 125 KB, 19 entries, root folder matches the slug, no dev files.

Form answers:

| Item | Answer |
|---|---|
| Read FAQ / guidelines | Yes |
| Plugin Check run, issues resolved | **Run it first — see below** |
| Distinctive name, searched for similar | Yes — vendor prefix, trademark trailing |
| Permission to upload; account represents owner | Yes — account `shauncuier` |
| No artificial feature limitations | Yes — no paywall, licence gate, trial or usage cap |

## Run Plugin Check before ticking that box

The form makes you confirm the plugin was tested with **Plugin Check**. It has not been — a static audit is not the same thing, and ticking it would be a false declaration.

1. Install **Plugin Check** from the directory on dropshipzone.local.
2. Install this plugin from the 3.3.0 zip.
3. Tools → Plugin Check → select the plugin → run all checks.
4. Fix what it reports, or note anything you judge a false positive.

Expect three findings, all understood:

- **Text Domain mismatch** — pre-excused in their email: *"You may see a warning regarding the 'Text Domain', as we haven't changed the slug on our side yet. That's fine."*
- **`Tested up to: 6.7.1`** — genuinely stale. Set it to the current WordPress version before uploading. Worth fixing.
- **Direct database queries** — legitimate; the plugin owns three custom tables, every query uses `$wpdb->prepare()` and carries a `phpcs:ignore`. Defensible as a false positive.

## Already handled from their email

- Sanitization: both `product_data` paths, plus the settings/password path.
- Escaping: no bare `_e()` / unescaped `__()` left; the two `printf` issues fixed.
- `load_plugin_textdomain()`: already removed (commented out).
- External services: full disclosure section in readme.txt.
- `register_setting()`: all six calls have a `sanitize_callback`.
- Directory assets: `icon-256x256.png` and `banner-1544x500.png` are out of the zip. They belong in SVN `/assets` after approval.

## Still open

- **Screenshots**: readme.txt lists 10 that do not exist. They go in SVN `/assets` as `screenshot-1.png` etc. after approval — either produce them or trim the list.
- **`.pot` regeneration**: stale (dated 2024, string references predate three renames). Regenerate with
  `wp i18n make-pot . languages/3s-soft-price-stock-sync-for-dropshipzone.pot`
