# WordPress.org submission — slug reservation reply

The plugins team's follow-up gave four steps. Status:

| # | Step | Status |
|---|---|---|
| 1 | Update display name in readme and plugin headers | Done — `3S Product Sync for Dropshipzone` |
| 2 | Update the slug in plugin files (i18n functions) | Done — text domain `3s-product-sync-for-dropshipzone`, 508 strings |
| 3 | Reply requesting a new slug reservation | **Send the email below** |
| 4 | Upload a new version via "Add your plugin" | After sending (no need to wait for confirmation) |

---

## Reply to send

**To:** the plugins team, on the existing review thread (reply to their follow-up email — do not start a new thread)
**Subject:** keep their subject line intact so it threads

---

Hello,

Thank you for the guidance. I have made the changes and would like to request a new slug reservation.

**Requested slug:** `3s-product-sync-for-dropshipzone`
**Display name:** 3S Product Sync for Dropshipzone

The original name began with a trademark I do not own. Dropshipzone Pty Ltd is a supplier my plugin integrates with; I am not affiliated with them. The new name puts my own vendor prefix first and keeps the trademark only in the trailing "for Dropshipzone" position, following the example in the submission form. The readme states plainly that the plugin is not affiliated with, endorsed by, or sponsored by Dropshipzone Pty Ltd.

Both the display name and the text domain have been updated to match the requested slug throughout the plugin.

I have also addressed the other guideline issues I found while reviewing:

- Added a full **External Services** disclosure to readme.txt covering every request the plugin makes to the Dropshipzone API, the data each one sends (including customer name, address and phone number when an order is submitted for fulfilment), and links to the provider's terms and privacy policy.
- Fixed a **sanitization** problem: product data cached in the browser during a catalogue search was posted back and decoded straight into product creation. It is now recursively sanitized before use.
- Fixed two **output escaping** issues flagged by Plugin Check.
- Removed debug `error_log()` calls, corrected the Plugin URI to a page I control, and removed an inaccurate claim of official affiliation.

I will upload the new version via the "Add your plugin" page now, as you suggested, without waiting for the slug reservation to be confirmed.

My WordPress.org account is 3ssoft.bd@gmail.com, which is the account that will own the listing.

Thank you,
3s-Soft

---

## Then upload

| Form item | Answer |
|---|---|
| Zip | `build/3s-product-sync-for-dropshipzone-v3.2.0.zip` (123 KB, well under the 10 MB cap) |
| Plugin name in file | `3S Product Sync for Dropshipzone` → slug `3s-product-sync-for-dropshipzone` |
| Read FAQ / guidelines | Yes |
| Plugin Check run, issues resolved | **Run it first — see below** |
| Distinctive name, searched for similar | Yes — vendor prefix, trademark trailing |
| Permission to upload, account represents owner | Yes — 3ssoft.bd@gmail.com is the 3s-Soft account |
| No artificial feature limitations | Yes — no paywall, licence gate, trial or usage cap anywhere in the code |

## Run Plugin Check before ticking that box

The form requires confirming the plugin was tested with **Plugin Check**. That has not been done — a static audit is not the same thing, and ticking the box without running it would be a false declaration.

On dropshipzone.local:

1. Install the **Plugin Check** plugin from the directory.
2. Install this plugin from `build/3s-product-sync-for-dropshipzone-v3.2.0.zip`.
3. Tools → Plugin Check → select the plugin → run all checks.
4. Fix what it reports, or note anything you judge a false positive.

Three findings to expect, all already understood:

- **Text Domain mismatch** — expected and explicitly excused in their email: *"You may see a warning regarding the 'Text Domain', as we haven't changed the slug on our side yet. That's fine."*
- **`Tested up to: 6.7.1`** in readme.txt is stale and will be flagged. Set it to the current WordPress version before submitting. This one is worth fixing.
- **Direct database queries** in the logger, mapper and order handler. Legitimate — the plugin owns three custom tables — each marked with a `phpcs:ignore` and using `$wpdb->prepare()`. Reasonable to declare as a false positive.

## Other pre-submission items

- **Screenshots**: readme.txt lists 10, none exist. They go in the SVN `/assets` directory as `screenshot-1.png` etc. after approval, not in the zip. Either produce them or trim the list.
- **`.pot` regeneration**: the translation template is stale (dated 2024, string references predate two renames). Regenerate with
  `wp i18n make-pot . languages/3s-product-sync-for-dropshipzone.pot`
  Not a blocker for review.
