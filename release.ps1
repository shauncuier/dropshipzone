# Dropshipzone Plugin Release Script
# Handles versioning, changelog updates, git tagging, and optional build
#
# Usage:
#   .\release.ps1 -BumpType patch     # 2.0.0 -> 2.0.1
#   .\release.ps1 -BumpType minor     # 2.0.0 -> 2.1.0
#   .\release.ps1 -BumpType major     # 2.0.0 -> 3.0.0
#   .\release.ps1 -Version "2.5.0"    # Set specific version
#   .\release.ps1 -BumpType patch -NoPush  # Don't push to remote

param(
    [ValidateSet("major", "minor", "patch")]
    [string]$BumpType = "",
    [string]$Version = "",
    [switch]$NoPush,
    [switch]$NoBuild,
    [switch]$DryRun
)

# Colors for output
function Write-Step { param($msg) Write-Host "`n[$script:StepNum/$script:TotalSteps] $msg" -ForegroundColor Yellow; $script:StepNum++ }
function Write-Success { param($msg) Write-Host "  ✓ $msg" -ForegroundColor Green }
function Write-Info { param($msg) Write-Host "  → $msg" -ForegroundColor Cyan }
function Write-Warn { param($msg) Write-Host "  ! $msg" -ForegroundColor Magenta }

$script:StepNum = 1
$script:TotalSteps = 7

# Get script directory
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ScriptDir

Write-Host ""
Write-Host "╔══════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║   Dropshipzone Plugin Release Script    ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════════╝" -ForegroundColor Cyan

# ============================================
# STEP 1: Get current version
# ============================================
Write-Step "Reading current version..."

$PluginFile = "dropshipzone-price-stock-sync.php"
$PluginContent = Get-Content $PluginFile -Raw

if ($PluginContent -match "Version:\s*([0-9]+)\.([0-9]+)\.([0-9]+)") {
    $CurrentMajor = [int]$Matches[1]
    $CurrentMinor = [int]$Matches[2]
    $CurrentPatch = [int]$Matches[3]
    $CurrentVersion = "$CurrentMajor.$CurrentMinor.$CurrentPatch"
    Write-Success "Current version: $CurrentVersion"
} else {
    Write-Error "Could not parse version from plugin file"
    exit 1
}

# ============================================
# STEP 2: Calculate new version
# ============================================
Write-Step "Calculating new version..."

if (-not [string]::IsNullOrEmpty($Version)) {
    # Use specified version
    if ($Version -match "^([0-9]+)\.([0-9]+)\.([0-9]+)$") {
        $NewMajor = [int]$Matches[1]
        $NewMinor = [int]$Matches[2]
        $NewPatch = [int]$Matches[3]
        $NewVersion = $Version
    } else {
        Write-Error "Invalid version format. Use: X.Y.Z (e.g., 2.1.0)"
        exit 1
    }
} elseif (-not [string]::IsNullOrEmpty($BumpType)) {
    # Calculate based on bump type
    switch ($BumpType) {
        "major" {
            $NewMajor = $CurrentMajor + 1
            $NewMinor = 0
            $NewPatch = 0
        }
        "minor" {
            $NewMajor = $CurrentMajor
            $NewMinor = $CurrentMinor + 1
            $NewPatch = 0
        }
        "patch" {
            $NewMajor = $CurrentMajor
            $NewMinor = $CurrentMinor
            $NewPatch = $CurrentPatch + 1
        }
    }
    $NewVersion = "$NewMajor.$NewMinor.$NewPatch"
} else {
    Write-Error "Please specify -BumpType (major|minor|patch) or -Version X.Y.Z"
    exit 1
}

Write-Success "New version: $NewVersion"
Write-Info "$CurrentVersion → $NewVersion ($BumpType)"

if ($DryRun) {
    Write-Warn "DRY RUN - No changes will be made"
}

# Confirm with user
Write-Host ""
$confirm = Read-Host "Proceed with release v$NewVersion? (y/n)"
if ($confirm -ne "y" -and $confirm -ne "Y") {
    Write-Host "Release cancelled." -ForegroundColor Red
    exit 0
}

# ============================================
# STEP 3: Update version in files
# ============================================
Write-Step "Updating version in files..."

$Today = Get-Date -Format "yyyy-MM-dd"

# Files to update
$FilesToUpdate = @(
    @{
        Path = "dropshipzone-price-stock-sync.php"
        Patterns = @(
            @{ Find = "Version:\s*$CurrentVersion"; Replace = "Version: $NewVersion" },
            @{ Find = "define\('DSZ_SYNC_VERSION',\s*'$CurrentVersion'\)"; Replace = "define('DSZ_SYNC_VERSION', '$NewVersion')" }
        )
    },
    @{
        Path = "readme.txt"
        Patterns = @(
            @{ Find = "Stable tag:\s*$CurrentVersion"; Replace = "Stable tag: $NewVersion" }
        )
    },
    @{
        Path = "README.md"
        Patterns = @(
            @{ Find = "version-$CurrentVersion-blue"; Replace = "version-$NewVersion-blue" }
        )
    }
)

foreach ($file in $FilesToUpdate) {
    if (Test-Path $file.Path) {
        $content = Get-Content $file.Path -Raw
        foreach ($pattern in $file.Patterns) {
            $content = $content -replace $pattern.Find, $pattern.Replace
        }
        if (-not $DryRun) {
            Set-Content -Path $file.Path -Value $content -NoNewline
        }
        Write-Success "Updated $($file.Path)"
    }
}

# ============================================
# STEP 4: Update CHANGELOG.md
# ============================================
Write-Step "Updating CHANGELOG.md..."

$ChangelogPath = "CHANGELOG.md"
if (Test-Path $ChangelogPath) {
    $changelog = Get-Content $ChangelogPath -Raw
    
    # Check if there's an Unreleased section to convert
    if ($changelog -match "\[Unreleased\]|\[$NewVersion-dev\]") {
        $changelog = $changelog -replace "\[Unreleased\]|\[$NewVersion-dev\]", "[$NewVersion]"
        $changelog = $changelog -replace "## \[$NewVersion\].*", "## [$NewVersion] - $Today"
    }
    
    # Add link at bottom if not exists
    $linkPattern = "\[$NewVersion\]: https://github.com"
    if ($changelog -notmatch [regex]::Escape("[$NewVersion]:")) {
        $newLink = "[$NewVersion]: https://github.com/shauncuier/dropshipzone/releases/tag/v$NewVersion"
        # Insert before [1.0.0] link or at end
        if ($changelog -match "\[[\d]+\.[\d]+\.[\d]+\]: https://github.com") {
            $changelog = $changelog -replace "(\[[\d]+\.[\d]+\.[\d]+\]: https://github.com)", "$newLink`n`$1"
        }
    }
    
    if (-not $DryRun) {
        Set-Content -Path $ChangelogPath -Value $changelog -NoNewline
    }
    Write-Success "Updated CHANGELOG.md with date $Today"
}

# ============================================
# STEP 5: Git commit
# ============================================
Write-Step "Creating git commit..."

if (-not $DryRun) {
    git add -A
    $commitMsg = "Release v$NewVersion"
    git commit -m $commitMsg
    Write-Success "Committed: $commitMsg"
} else {
    Write-Info "Would commit: Release v$NewVersion"
}

# ============================================
# STEP 6: Create git tag
# ============================================
Write-Step "Creating git tag..."

$TagName = "v$NewVersion"
$TagMsg = "Version $NewVersion"

if (-not $DryRun) {
    git tag -a $TagName -m $TagMsg
    Write-Success "Created tag: $TagName"
} else {
    Write-Info "Would create tag: $TagName"
}

# ============================================
# STEP 7: Push to remote
# ============================================
Write-Step "Pushing to remote..."

if (-not $NoPush -and -not $DryRun) {
    $currentBranch = git rev-parse --abbrev-ref HEAD
    git push origin $currentBranch
    git push origin $TagName
    Write-Success "Pushed branch '$currentBranch' and tag '$TagName' to origin"
} elseif ($NoPush) {
    Write-Warn "Skipped push (-NoPush flag)"
    Write-Info "Run manually: git push origin main && git push origin $TagName"
} else {
    Write-Info "Would push to origin"
}

# ============================================
# Build (optional)
# ============================================
if (-not $NoBuild -and -not $DryRun) {
    Write-Host ""
    Write-Host "Building distribution zip..." -ForegroundColor Yellow
    & "$ScriptDir\build.ps1"
}

# ============================================
# Summary
# ============================================
Write-Host ""
Write-Host "╔══════════════════════════════════════════╗" -ForegroundColor Green
Write-Host "║          Release Complete! 🎉           ║" -ForegroundColor Green
Write-Host "╚══════════════════════════════════════════╝" -ForegroundColor Green
Write-Host ""
Write-Host "  Version:  $CurrentVersion → $NewVersion" -ForegroundColor White
Write-Host "  Tag:      $TagName" -ForegroundColor White
Write-Host "  Date:     $Today" -ForegroundColor White
Write-Host ""

if (-not $NoBuild -and -not $DryRun) {
    $zipPath = Join-Path $ScriptDir "build\dropshipzone-price-stock-sync-v$NewVersion.zip"
    if (Test-Path $zipPath) {
        Write-Host "  Zip:      $zipPath" -ForegroundColor White
    }
}

Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "  1. Create GitHub Release: https://github.com/shauncuier/dropshipzone/releases/new?tag=$TagName" -ForegroundColor Gray
Write-Host "  2. Upload the zip file to the release" -ForegroundColor Gray
Write-Host "  3. Add release notes from CHANGELOG.md" -ForegroundColor Gray
Write-Host ""
