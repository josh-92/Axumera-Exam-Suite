[CmdletBinding()]
param(
    [string]$TargetVersion = '1.0.1',
    [string]$MinSupportedVersion = '1.0.0'
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

Write-Host "Building Axumera Update Package v$TargetVersion..."

# 1. Compile updated controller & updater executables
& (Join-Path $PSScriptRoot 'build-controller.ps1')
& (Join-Path $PSScriptRoot 'build-launchers.ps1')
& (Join-Path $PSScriptRoot 'build-updater.ps1')

$staging = Join-Path $root "distribution\staging\Axumera_Update"
if (Test-Path $staging) { Remove-Item -Recurse -Force -LiteralPath $staging }
New-Item -ItemType Directory -Force -Path $staging | Out-Null

$appSource = Join-Path $root 'application\eaes_exam_system'
$appStaging = Join-Path $staging 'application\eaes_exam_system'

# Copy application files
Copy-Item -Recurse -Force $appSource $appStaging

# Exclude persistent customer state and secrets from update payload
Remove-Item -Force -ErrorAction SilentlyContinue "$appStaging\.env", "$appStaging\storage\license.lic", "$appStaging\storage\installed.lock"
Remove-Item -Force -ErrorAction SilentlyContinue "$appStaging\yakpro-po.cnf"
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue "$appStaging\docs", "$appStaging\tests", "$appStaging\storage\logs", "$appStaging\storage\cache", "$appStaging\storage\sessions"
New-Item -ItemType Directory -Force -Path "$appStaging\storage\logs", "$appStaging\storage\cache", "$appStaging\storage\sessions" | Out-Null

# Update VERSION file in staging
Set-Content -Encoding utf8 "$appStaging\VERSION" $TargetVersion

# Copy compiled updater executable
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Update.exe') $staging

# Build SHA256 file manifest
$manifestFiles = @()
$files = Get-ChildItem -Recurse -File $appStaging
foreach ($file in $files) {
    $relPath = $file.FullName.Substring($staging.Length + 1).Replace('\', '/')
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $file.FullName).Hash.ToLowerInvariant()
    $manifestFiles += @{
        path = $relPath
        sha256 = $hash
        type = "application"
    }
}

$manifest = @{
    product = "Axumera Exam Suite"
    targetVersion = $TargetVersion
    minSupportedVersion = $MinSupportedVersion
    createdAt = (Get-Date).ToUniversalTime().ToString("o")
    files = $manifestFiles
}

$manifestJson = $manifest | ConvertTo-Json -Depth 5
$manifestPath = Join-Path $staging "update-manifest.json"
Set-Content -Encoding utf8 -LiteralPath $manifestPath $manifestJson

# Package zip archive
$distDir = Join-Path $root "distribution"
$zipOutput = Join-Path $distDir "Axumera_Update_v$TargetVersion.zip"
if (Test-Path $zipOutput) { Remove-Item -Force $zipOutput }
Compress-Archive -Path "$staging\*" -DestinationPath $zipOutput -Force

Write-Host "Update package v$TargetVersion built successfully at $zipOutput"
