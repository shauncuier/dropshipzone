# WordPress.org review — status

## APPROVED — review complete (30 July 2026) 🎉

The plugin hosting request has been **approved** by the WordPress Plugins Team!

| Property | Value |
|---|---|
| SVN URL | `https://plugins.svn.wordpress.org/3s-soft-price-stock-sync-for-dropshipzone` |
| Public URL | `https://wordpress.org/plugins/3s-soft-price-stock-sync-for-dropshipzone` |
| Slug | `3s-soft-price-stock-sync-for-dropshipzone` |
| Review ID | `APPROVED 3s-soft-price-stock-sync-for-dropshipzone/shauncuier/28Jul26/T2 30Jul26/4.1 (P0TDX346807HGN)` |
| SVN Username | `shauncuier` |

---

## SUBMITTED — awaiting review (26 July 2026)

Nothing further to send. The submission went through the upload form and
was accepted.

| | |
|---|---|
| Permalink | https://wordpress.org/plugins/3s-soft-price-stock-sync-for-dropshipzone/ |
| Slug | `3s-soft-price-stock-sync-for-dropshipzone` — confirmed correct |
| Version | 3.3.3 |
| Automated scan | Pass |
| Review email goes to | `plugins@3s-soft.com` |

**Do not reply to the confirmation email** — it says so explicitly, and the
slug is correct so there is nothing to raise. **Do not submit another
plugin** while this one is queued. Each extra message can re-queue the
submission.

The one remaining window: new versions can be uploaded on the submission
page **until a reviewer starts**. After that it closes. So if the
`dszsync_` prefix migration fails on a real install, fix and re-upload
now rather than later.

Everything below is historical — kept for the reasoning, not as
instructions.

## (historical) Do not use the upload form

The January pre-review email said to upload via "Add your plugin". The
**2 May rejection supersedes that**:

> All we ask is you do not resubmit your plugin until asked to do so.
> ...
> You can reply to the original review (or even this email) with your
> updated code for as long as needed. Even years.
> ...
> please email us with your plugin attached as a zip

So the plugin goes back **as an email attachment**, not through the form.
Uploading now would be resubmitting against an explicit instruction.

| # | Step | Status |
|---|---|---|
| 1 | Update display name in readme and plugin headers | Done — `3S Soft Price & Stock Sync for Dropshipzone` |
| 2 | Update the slug in plugin files (i18n functions) | Done — text domain `3s-soft-price-stock-sync-for-dropshipzone` |
| 3 | Reply requesting a new slug reservation | **Send the message below, zip attached** |
| 4 | Upload via "Add your plugin" | **Only when they ask** — do not do this yet |

Send from `3ssoft.bd@gmail.com` (where the thread lives), subject line
unchanged so it threads to the same ticket, with
`build/3s-soft-price-stock-sync-for-dropshipzone-v3.3.3.zip` attached.

They asked for brevity — "be brief and direct in your reply, please avoid copy-pasting bloated AI responses" and "do not list the changes done, we will review the entire plugin again". The message below is deliberately short.

---

## Reply (zip attached, subject line unchanged)

Hello,

Still working on this — attaching the updated plugin.

The core problem was the name: "DropshipZone Sync" led with a trademark I do not own. I am not affiliated with Dropshipzone Pty Ltd; they are a supplier this plugin integrates with.

Requesting a new slug reservation:

**Slug:** `3s-soft-price-stock-sync-for-dropshipzone`
**Display name:** 3S Soft Price & Stock Sync for Dropshipzone

The trademark now appears only in the trailing "for Dropshipzone" position, with my own vendor prefix in front. Display name and text domain both match the requested slug, and the readme states the lack of affiliation explicitly.

I have also updated my WordPress.org account email to plugins@3s-soft.com, on the domain of the entity behind the plugin.

The naming, ownership, sanitization, escaping, prefixing, external services and directory-assets points from the review have been addressed. Plugin Check runs clean.

One note on the prefix: I widened `dsz_` to `dszsync_` across functions, options, post meta, hooks, AJAX actions and the shipping method id, with a migration for existing installs. I left the custom database table and column names on the old form, as they are scoped inside tables the plugin owns and renaming them would mean an ALTER TABLE on live data for no collision benefit. Happy to change that if you would prefer consistency.

Please let me know if you would like me to upload via the Add your plugin page instead — I have not done so, per the instruction not to resubmit until asked.

Thanks,
Shaun (shauncuier)

---

## Only if they ask you to upload

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
