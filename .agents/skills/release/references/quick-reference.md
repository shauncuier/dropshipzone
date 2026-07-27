# Release Skill — Quick Reference

## Version Locations (must all match)

| File | Search for |
|------|-----------|
| `3s-soft-price-stock-sync-for-dropshipzone.php` | `Version: X.Y.Z` (plugin header) |
| `3s-soft-price-stock-sync-for-dropshipzone.php` | `define('DSZSYNC_VERSION', 'X.Y.Z')` |
| `readme.txt` | `Stable tag: X.Y.Z` |
| `CHANGELOG.md` | `## [X.Y.Z] - YYYY-MM-DD` + one link line at the bottom |

`README.md` has **no** version to bump — its badge is generated from the
latest GitHub release by shields.io.

Consistency check:

```bash
grep -m1 "Version:" 3s-soft-price-stock-sync-for-dropshipzone.php
grep -m1 "DSZSYNC_VERSION" 3s-soft-price-stock-sync-for-dropshipzone.php
grep -m1 "Stable tag" readme.txt
```

## Commands

```powershell
# Build only
.\build.ps1                              # version from plugin header
.\build.ps1 -Version "3.4.0"

# Release script — prompts via Read-Host, so it FAILS in a
# non-interactive agent shell. Run it yourself, or use the manual
# git flow in SKILL.md.
.\release.ps1 -BumpType patch            # or minor / major
.\release.ps1 -Version "3.4.0"
.\release.ps1 -BumpType patch -DryRun    # safe non-interactively
.\release.ps1 -BumpType patch -NoPush
.\release.ps1 -BumpType patch -NoBuild
```

## Manual Release (reliable for automation)

```bash
# verify
php -l 3s-soft-price-stock-sync-for-dropshipzone.php
node --check assets/admin.js

# bump the three version locations, add the CHANGELOG entry, then
.\build.ps1
git add -A && git commit -m "<subject>"
git tag -a "vX.Y.Z" -m "Version X.Y.Z - <summary>"
git push origin main && git push origin "vX.Y.Z"

# verify what actually published
gh run list --limit 1
gh release view vX.Y.Z --json name,tagName,url,assets
```

No `Co-Authored-By` trailers on commits.

## Build Output

`build/3s-soft-price-stock-sync-for-dropshipzone-vX.Y.Z.zip`

**Excluded** (from `build.ps1`):

```
.git, .github, .gitignore, .gitattributes, .claude, .agent*
build, build.ps1, release.ps1, build.sh, doc
node_modules, .DS_Store, Thumbs.db, *.log, *.md
```

**Included:** `readme.txt`, `LICENSE`,
`3s-soft-price-stock-sync-for-dropshipzone.php`, `includes/`, `assets/`,
`languages/`

Zip root folder **must** be `3s-soft-price-stock-sync-for-dropshipzone/`.
WordPress uses it as the install directory; a mismatch breaks updates.

## GitHub Actions Flow

```
Push v* tag → .github/workflows/release.yml:
  1. Checkout
  2. Validate plugin file exists
  3. Build zip (copies whole dirs — slightly larger than build.ps1 output)
  4. Extract the changelog section for this version
  5. Create GitHub Release + attach zip
  6. Upload build artifact (30 day retention)
```

## Gotchas

- **`-replace` replaces every match.** The old changelog-link logic
  inserted one copy of the new link before *each* existing link line; the
  section reached 768 lines. Check the tail of `CHANGELOG.md` after a
  scripted run.
- **A GitHub release does not publish to WordPress.org.** The plugin is
  queued for first review there; see `doc/wordpress-org-submission-email.md`.
- **Renaming a public hook is a breaking change** — major bump, and update
  README's hook documentation to match the code.
