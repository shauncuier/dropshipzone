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

`build/3s-soft-price-stock-sync-for-dropshipzone-v3.3.3.zip` — 128 KB, 19 entries, root folder matches the slug, no dev files.

Form answers:

| Item | Answer |
|---|---|
| Read FAQ / guidelines | Yes |
| Plugin Check run, issues resolved | **Yes — clean run, 2026-07-26, no errors or warnings** |
| Distinctive name, searched for similar | Yes — vendor prefix, trademark trailing |
| Permission to upload; account represents owner | Yes — account `shauncuier` |
| No artificial feature limitations | Yes — no paywall, licence gate, trial or usage cap |

## Plugin Check status: clean

Run against v3.3.3 on 2026-07-26 — **no errors, no warnings**. Nothing needs declaring as a false positive.

Three passes got there:

| Pass | Findings | What changed |
|---|---|---|
| First | ~150 | Unescaped output, `fopen()` in the CSV export, missing `wp_unslash()`, translators comment placement |
| Second | ~30 | Table names moved to the `%i` identifier placeholder; corrected `phpcs:ignore` sniff names that were misspelled and therefore inert |
| Third | 4 | Final two multi-table search queries converted to `%i` |
| Now | **0** | — |

The plugin's SQL is now genuinely prepared throughout rather than relying on suppression comments. Minimum WordPress is 6.2, the release that introduced `%i`.

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
