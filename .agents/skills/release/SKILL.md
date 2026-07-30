---
name: release
description: Handles the full release lifecycle for the 3S Soft Price & Stock Sync for Dropshipzone WordPress plugin — version bumping, changelog management, file updates, build creation, git tagging, and pushing. Use when the user asks to release, publish, version bump, tag, ship, or cut a new version.
---

# Release Skill — 3S Soft Price & Stock Sync for Dropshipzone

Covers version bumping, multi-file version updates, changelog formatting,
zip build creation, git commit/tag, and push to remote.

## When to Use

- "release", "cut a release", "ship it", "publish a new version"
- "bump version", "version bump", "patch/minor/major"
- "tag a release", "create a release", "prepare a release"

## Project Context

| Item | Value |
|------|-------|
| Plugin slug | `3s-soft-price-stock-sync-for-dropshipzone` |
| Main file | `3s-soft-price-stock-sync-for-dropshipzone.php` |
| Version constant | `DSZSYNC_VERSION` |
| Code prefix | `dszsync_` / `DSZSYNC_` |
| Namespace | `Dropshipzone` |
| GitHub repo | `shauncuier/dropshipzone` |
| Build script | `build.ps1` |
| Release script | `release.ps1` (interactive — see warning below) |
| GitHub Actions | `.github/workflows/release.yml` (triggers on `v*` tags) |
| Zip output | `build/3s-soft-price-stock-sync-for-dropshipzone-vX.Y.Z.zip` |

> The repository is still named `dropshipzone` on GitHub. That is fine —
> the repo name does not need to match the plugin slug.

## Files That Contain the Version

These **must** move in lockstep:

| File | Pattern |
|------|---------|
| `3s-soft-price-stock-sync-for-dropshipzone.php` | `Version: X.Y.Z` (plugin header) |
| `3s-soft-price-stock-sync-for-dropshipzone.php` | `define('DSZSYNC_VERSION', 'X.Y.Z')` |
| `readme.txt` | `Stable tag: X.Y.Z` |
| `CHANGELOG.md` | `## [X.Y.Z] - YYYY-MM-DD` heading, plus one link at the bottom |

**`README.md` has no version to bump.** Its badge is generated from the
latest GitHub release by shields.io.

Verify consistency before tagging:

```bash
grep -m1 "Version:" 3s-soft-price-stock-sync-for-dropshipzone.php
grep -m1 "DSZSYNC_VERSION" 3s-soft-price-stock-sync-for-dropshipzone.php
grep -m1 "Stable tag" readme.txt
```

All three must show the same number. A mismatch between the plugin header
and `readme.txt` breaks WordPress.org updates.

## Versioning Scheme

[Semantic Versioning](https://semver.org/):

- **MAJOR** — breaking changes. Renaming a public hook counts.
- **MINOR** — new backwards-compatible features.
- **PATCH** — backwards-compatible fixes.

Renaming or removing anything stored in the database needs a migration,
not just a version bump. See `maybe_migrate_prefix()` in the main file.

## Release Workflow

### Step 0 — Preconditions

```bash
git status --short          # must be empty
git fetch origin
git rev-list --left-right --count origin/main...main   # must not be behind
git tag -l "vX.Y.Z"         # must not already exist
```

### Step 1 — Verify Before Bumping

Never tag something unverified.

```powershell
# Lint every PHP file and the admin JS
Get-ChildItem includes\*.php | ForEach-Object { php -l $_.FullName }
php -l 3s-soft-price-stock-sync-for-dropshipzone.php
node --check assets\admin.js
```

Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) against a
build if the release touches plugin code. It currently passes with zero
errors and zero warnings — keep it there.

### Step 2 — Update Version in All Files

Use exact string replacements, never blind regex. Update every file in the
table above.

### Step 3 — Update CHANGELOG.md

1. Add `## [X.Y.Z] - YYYY-MM-DD` at the top of the entries.
2. Describe **why**, not just what — the diff already shows what changed.
3. Add exactly **one** link line at the bottom:
   `[X.Y.Z]: https://github.com/shauncuier/dropshipzone/releases/tag/vX.Y.Z`
4. Preserve existing structure; do not reformat other sections.

> **Watch for duplicate links.** An older `release.ps1` used PowerShell's
> `-replace`, which substitutes *every* match — it inserted one copy of the
> new link before each existing link line, and the section grew to 768
> lines before being rebuilt. Fixed in the script, but check the tail of
> `CHANGELOG.md` after any scripted run.

### Step 4 — Build

```powershell
.\build.ps1
```

Then verify the archive:

- Root folder is `3s-soft-price-stock-sync-for-dropshipzone/` — WordPress
  uses it as the install directory, and a mismatch breaks updates
- No `.md`, `.ps1`, `.yml`, or dotfiles
- Header version matches what you set

### Step 5 — Commit & Tag

```bash
git add -A
git commit -m "<subject>"
git tag -a "vX.Y.Z" -m "Version X.Y.Z - <summary>"
```

Do **not** add `Co-Authored-By` or AI attribution trailers.

### Step 6 — Push

```bash
git push origin main
git push origin "vX.Y.Z"
```

Pushing the tag triggers `.github/workflows/release.yml`, which builds a
zip and creates the GitHub Release automatically.

### Step 7 — Verify the Published Release

Do not assume the workflow succeeded.

```bash
gh run list --limit 1
gh release view vX.Y.Z --json name,tagName,url,assets
```

Download the published asset and confirm the root folder and header
version. The CI zip copies whole directories rather than applying
`build.ps1` exclusions, so it carries a few more files — both are valid.

## Scripts

### `release.ps1`

⚠️ **Calls `Read-Host` for confirmation, which fails under a
non-interactive agent shell.** Either run it yourself in a terminal, or
follow the manual steps above — which is the reliable path for automation.

```powershell
.\release.ps1 -BumpType patch      # or minor / major
.\release.ps1 -Version "3.4.0"
.\release.ps1 -BumpType patch -DryRun    # safe to run non-interactively
```

Flags: `-NoPush`, `-NoBuild`, `-DryRun`.

### `build.ps1`

```powershell
.\build.ps1                  # version auto-detected from the plugin header
.\build.ps1 -Version "3.4.0"
```

Output: `build/3s-soft-price-stock-sync-for-dropshipzone-v<version>.zip`

## WordPress.org

The plugin is hosted on the WordPress.org directory (`3s-soft-price-stock-sync-for-dropshipzone`).
- SVN Repository: `https://plugins.svn.wordpress.org/3s-soft-price-stock-sync-for-dropshipzone`
- Public Page: `https://wordpress.org/plugins/3s-soft-price-stock-sync-for-dropshipzone`

To publish releases to WordPress.org, deploy code to SVN `trunk/` and tag the version under `tags/X.Y.Z/`. Assets (banners, icons, screenshots) go into the SVN `/assets` directory.
A GitHub release does **not** automatically publish to SVN unless an SVN sync workflow is configured.

## Rollback

```bash
git tag -d "vX.Y.Z"
git push origin --delete "vX.Y.Z"
git revert <commit>
git push
```

Then delete the GitHub Release at
`https://github.com/shauncuier/dropshipzone/releases`.

## Decision Guide

| User says | Action |
|-----------|--------|
| "release" with no version | Bump patch unless the changes warrant more; state which you chose |
| "patch/minor/major release" | Bump accordingly |
| "release X.Y.Z" | Use that exact version |
| "build only" | Run `build.ps1`, skip tag and push |
| "what version are we on?" | Read from the plugin header |
