# Dropshipzone Plugin Build Script
# Creates a clean distribution zip for WordPress

param(
    [string]$Version = ""
)

# Get script directory
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ScriptDir

# If no version provided, extract from main plugin file
if ([string]::IsNullOrEmpty($Version)) {
    $PluginContent = Get-Content "dropshipzone-price-stock-sync.php" -Raw
    if ($PluginContent -match "Version:\s*([0-9]+\.[0-9]+\.[0-9]+)") {
        $Version = $Matches[1]
    } else {
        Write-Error "Could not extract version from plugin file"
        exit 1
    }
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Dropshipzone Plugin Build Script" -ForegroundColor Cyan
Write-Host "  Version: $Version" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Define paths
$PluginName = "dropshipzone-price-stock-sync"
$BuildDir = Join-Path $ScriptDir "build"
$DistDir = Join-Path $BuildDir $PluginName
$ZipFile = Join-Path $BuildDir "$PluginName-v$Version.zip"

# Files/folders to exclude from the zip
$Excludes = @(
    ".git",
    ".github",
    ".gitignore",
    ".gitattributes",
    "build",
    "build.ps1",
    "build.sh",
    "node_modules",
    ".DS_Store",
    "Thumbs.db",
    "*.log",
    ".agent"
)

# Clean up previous build
Write-Host "[1/4] Cleaning previous build..." -ForegroundColor Yellow
if (Test-Path $BuildDir) {
    Remove-Item -Recurse -Force $BuildDir
}
New-Item -ItemType Directory -Path $DistDir -Force | Out-Null

# Copy files
Write-Host "[2/4] Copying plugin files..." -ForegroundColor Yellow

# Get all items except excluded ones
$Items = Get-ChildItem -Path $ScriptDir | Where-Object {
    $item = $_
    $excluded = $false
    foreach ($exclude in $Excludes) {
        if ($item.Name -like $exclude) {
            $excluded = $true
            break
        }
    }
    -not $excluded
}

foreach ($item in $Items) {
    $dest = Join-Path $DistDir $item.Name
    if ($item.PSIsContainer) {
        Copy-Item -Path $item.FullName -Destination $dest -Recurse -Force
    } else {
        Copy-Item -Path $item.FullName -Destination $dest -Force
    }
    Write-Host "  + $($item.Name)" -ForegroundColor Gray
}

# Create zip file
Write-Host "[3/4] Creating zip archive..." -ForegroundColor Yellow

# Remove existing zip if present
if (Test-Path $ZipFile) {
    Remove-Item $ZipFile -Force
}

# Create zip
Compress-Archive -Path $DistDir -DestinationPath $ZipFile -CompressionLevel Optimal

# Get file size
$ZipSize = (Get-Item $ZipFile).Length / 1KB
$ZipSizeFormatted = "{0:N2} KB" -f $ZipSize

# Clean up dist folder (keep only zip)
Write-Host "[4/4] Cleaning up..." -ForegroundColor Yellow
Remove-Item -Recurse -Force $DistDir

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  Build Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "  Output: $ZipFile" -ForegroundColor White
Write-Host "  Size:   $ZipSizeFormatted" -ForegroundColor White
Write-Host ""
Write-Host "Ready for distribution!" -ForegroundColor Green
