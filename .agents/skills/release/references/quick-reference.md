# Release Skill — Quick Reference

## Commands Cheat Sheet

```powershell
# === Quick Release ===
.\release.ps1 -BumpType patch          # 2.5.0 → 2.5.1
.\release.ps1 -BumpType minor          # 2.5.0 → 2.6.0
.\release.ps1 -BumpType major          # 2.5.0 → 3.0.0
.\release.ps1 -Version "3.0.0"         # explicit version

# === Options ===
.\release.ps1 -BumpType patch -DryRun  # preview only
.\release.ps1 -BumpType patch -NoPush  # local only
.\release.ps1 -BumpType patch -NoBuild # skip zip

# === Build Only ===
.\build.ps1                             # auto-detect version
.\build.ps1 -Version "2.5.1"           # explicit version
```

## Version Locations (must all match)

| File | Search for |
|------|-----------|
| `dropshipzone-price-stock-sync.php` L6 | `Version: X.Y.Z` |
| `dropshipzone-price-stock-sync.php` L27 | `define('DSZ_SYNC_VERSION', 'X.Y.Z')` |
| `readme.txt` L9 | `Stable tag: X.Y.Z` |
| `README.md` badge | `version-X.Y.Z-blue` |
| `CHANGELOG.md` | `## [X.Y.Z] - YYYY-MM-DD` |

## Build Output Exclusions

These are **excluded** from the distribution zip (defined in `build.ps1`):

```
.git, .github, .gitignore, .gitattributes, .claude, .agent
build, build.ps1, release.ps1, build.sh
node_modules, .DS_Store, Thumbs.db, *.log, *.md
```

**Included:** `readme.txt`, `LICENSE`, `dropshipzone-price-stock-sync.php`, `includes/`, `assets/`, `languages/`

## GitHub Actions Flow

```
Push v* tag → .github/workflows/release.yml triggers:
  1. Checkout code
  2. Validate plugin file
  3. Build zip (same exclusions)
  4. Extract changelog section
  5. Create GitHub Release + attach zip
  6. Upload build artifact (30 days)
```
