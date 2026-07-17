---
name: release
description: Handles the full release lifecycle for the Dropshipzone WordPress plugin — version bumping, changelog management, file updates, build creation, git tagging, and pushing. Use when the user asks to release, publish, version bump, tag, ship, or cut a new version.
---

# Release Skill — Dropshipzone Plugin

This skill automates the complete release workflow for the **DropshipZone Sync** WordPress/WooCommerce plugin. It covers version bumping, multi-file version updates, changelog formatting, zip build creation, git commit/tag, and push to remote.

## When to Use

Trigger this skill when the user requests any of:
- "release", "cut a release", "ship it", "publish a new version"
- "bump version", "version bump", "patch/minor/major"
- "tag a release", "create a release"
- "prepare a release"

## Project Context

| Item | Value |
|------|-------|
| Plugin slug | `dropshipzone-price-stock-sync` |
| Main file | `dropshipzone-price-stock-sync.php` |
| Namespace | `Dropshipzone` |
| GitHub repo | `shauncuier/dropshipzone` |
| Build script | `build.ps1` |
| Release script | `release.ps1` |
| GitHub Actions | `.github/workflows/release.yml` (triggers on `v*` tags) |

## Files That Contain the Version

All of these **must** be updated in lockstep during a release:

| File | Pattern | Example |
|------|---------|---------|
| `dropshipzone-price-stock-sync.php` | `Version: X.Y.Z` (plugin header) | `Version: 2.5.0` |
| `dropshipzone-price-stock-sync.php` | `define('DSZ_SYNC_VERSION', 'X.Y.Z')` | `define('DSZ_SYNC_VERSION', '2.5.0')` |
| `readme.txt` | `Stable tag: X.Y.Z` | `Stable tag: 2.5.0` |
| `README.md` | Version badge URL (if present) | `version-2.5.0-blue` |
| `CHANGELOG.md` | Release heading & links | `## [2.5.0] - 2025-12-30` |

## Versioning Scheme

This project follows [Semantic Versioning](https://semver.org/):

- **MAJOR** (`X.0.0`): Breaking/incompatible API changes
- **MINOR** (`0.X.0`): New backwards-compatible features
- **PATCH** (`0.0.X`): Backwards-compatible bug fixes

## Release Workflow

### Step 0 — Gather Inputs

1. **Determine the bump type** (or explicit version):
   - Ask the user if not specified: `patch`, `minor`, `major`, or an exact `X.Y.Z`.
2. **Read the current version** from `dropshipzone-price-stock-sync.php`:
   - Parse `Version: X.Y.Z` from the plugin header.
   - Parse `define('DSZ_SYNC_VERSION', 'X.Y.Z')`.
3. **Calculate the new version** by applying the bump.

### Step 1 — Update Version in All Files

Update every file listed in the "Files That Contain the Version" table above. Use exact string replacements — never regex-replace blindly.

**dropshipzone-price-stock-sync.php:**
```
Version: <old> → Version: <new>
define('DSZ_SYNC_VERSION', '<old>') → define('DSZ_SYNC_VERSION', '<new>')
```

**readme.txt:**
```
Stable tag: <old> → Stable tag: <new>
```

**README.md:**
```
version-<old>-blue → version-<new>-blue
```

### Step 2 — Update CHANGELOG.md

1. If an `[Unreleased]` section exists, rename it to `[<new version>]`.
2. Add today's date in ISO format: `## [X.Y.Z] - YYYY-MM-DD`.
3. If there is no existing entry for the new version, **ask the user for changelog entries** or generate them from recent git commits.
4. Ensure a comparison link exists at the bottom of the file:
   ```
   [X.Y.Z]: https://github.com/shauncuier/dropshipzone/releases/tag/vX.Y.Z
   ```
5. Preserve the existing changelog structure exactly — do not reformat other sections.

### Step 3 — Build the Distribution Zip

Run the build script to create the clean distribution zip:

```powershell
& ".\build.ps1"
```

This will:
- Clean the `build/` directory
- Copy plugin files (excluding dev files, `.git`, `*.md`, etc.)
- Create `build/dropshipzone-vX.Y.Z.zip`

Verify the zip was created and report its size.

### Step 4 — Git Commit & Tag

```powershell
git add -A
git commit -m "Release vX.Y.Z"
git tag -a "vX.Y.Z" -m "Version X.Y.Z"
```

### Step 5 — Push to Remote

```powershell
$branch = git rev-parse --abbrev-ref HEAD
git push origin $branch
git push origin "vX.Y.Z"
```

> **Note:** Pushing the tag will trigger the GitHub Actions workflow at `.github/workflows/release.yml`, which automatically creates a GitHub Release with the zip attached.

### Step 6 — Post-Release Summary

Present a summary to the user:

| Field | Value |
|-------|-------|
| Previous version | `<old>` |
| New version | `<new>` |
| Tag | `v<new>` |
| Zip location | `build/dropshipzone-v<new>.zip` |
| GitHub Release URL | `https://github.com/shauncuier/dropshipzone/releases/tag/v<new>` |

## Existing Scripts Reference

### `release.ps1`

The project includes a PowerShell release script at the repo root. It can be run directly:

```powershell
# Bump patch: 2.5.0 → 2.5.1
.\release.ps1 -BumpType patch

# Bump minor: 2.5.0 → 2.6.0
.\release.ps1 -BumpType minor

# Bump major: 2.5.0 → 3.0.0
.\release.ps1 -BumpType major

# Set explicit version
.\release.ps1 -Version "3.0.0"

# Dry run (no changes)
.\release.ps1 -BumpType patch -DryRun

# Skip pushing to remote
.\release.ps1 -BumpType patch -NoPush

# Skip building zip
.\release.ps1 -BumpType patch -NoBuild
```

**Parameters:**
- `-BumpType` (`major` | `minor` | `patch`): Automatic semver bump
- `-Version` (`"X.Y.Z"`): Set an explicit version
- `-NoPush`: Skip `git push` (commit & tag locally only)
- `-NoBuild`: Skip running `build.ps1` after tagging
- `-DryRun`: Preview all changes without modifying any files

> **Important:** The script prompts for confirmation (`y/n`) before proceeding. When running via the agent, either use `-DryRun` first to preview, or be prepared to send `y` as input.

### `build.ps1`

Creates a clean distribution zip:

```powershell
# Auto-detect version from plugin header
.\build.ps1

# Specify version explicitly
.\build.ps1 -Version "2.5.1"
```

**Output:** `build/dropshipzone-v<version>.zip`

### GitHub Actions (`release.yml`)

Triggered automatically when a `v*` tag is pushed. It:
1. Checks out the code
2. Validates the plugin file
3. Creates a clean zip (same exclusions as `build.ps1`)
4. Extracts changelog from `CHANGELOG.md`
5. Creates/updates a GitHub Release with the zip attached
6. Uploads the zip as a build artifact (30-day retention)

Can also be triggered manually via `workflow_dispatch` with a version input.

## Pre-Release Checklist

Before starting a release, verify:

- [ ] All code changes are committed and pushed
- [ ] Tests pass (if applicable)
- [ ] `CHANGELOG.md` has entries for the new version
- [ ] No uncommitted changes in the working tree (`git status` is clean)
- [ ] The branch is up-to-date with remote (`git pull`)

## Rollback

If a release needs to be reverted:

```powershell
# Delete the local tag
git tag -d "vX.Y.Z"

# Delete the remote tag
git push origin --delete "vX.Y.Z"

# Revert the release commit
git revert HEAD
git push
```

Then delete the GitHub Release manually at:
`https://github.com/shauncuier/dropshipzone/releases`

## Decision Guide

| User says... | Action |
|-------------|--------|
| "release" (no version info) | Ask for bump type (patch/minor/major) |
| "patch release" | Run with `-BumpType patch` |
| "release 3.0.0" | Run with `-Version "3.0.0"` |
| "dry run release" | Run with `-DryRun` flag |
| "build only" | Run `build.ps1` only, skip release |
| "what version are we on?" | Read and report from `dropshipzone-price-stock-sync.php` |
