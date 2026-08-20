[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

Write-Host "=================================================="
Write-Host "Running Axumera Package Security & Hygiene Audit..."
Write-Host "=================================================="

$violations = @()

$stagingDirs = @(
    (Join-Path $root 'distribution\staging\Axumera'),
    (Join-Path $root 'distribution\staging\Axumera_Student'),
    (Join-Path $root 'distribution\staging\Axumera_Update')
)


foreach ($dir in $stagingDirs) {
    if (!(Test-Path -LiteralPath $dir)) { continue }
    Write-Host "Auditing staging directory: $dir..."

    # Check 1: Forbidden files
    $forbiddenFiles = @('.env', 'license.lic', 'installed.lock', 'private_key.pem', 'key.pem', 'wmic.cmd', 'wmic.bat')
    foreach ($f in $forbiddenFiles) {
        $matches = Get-ChildItem -Recurse -LiteralPath $dir -Filter $f -ErrorAction SilentlyContinue
        if ($matches) {
            foreach ($m in $matches) {
                $violations += "FORBIDDEN FILE FOUND: $($m.FullName)"
            }
        }
    }

    # Check 2: Customer database files in data/mariadb
    $dbDataDir = Join-Path $dir 'data\mariadb'
    if (Test-Path -LiteralPath $dbDataDir) {
        $dbFiles = Get-ChildItem -Recurse -File -LiteralPath $dbDataDir | Where-Object { $_.Extension -in '.ibd','.frm','.MYD','.MYI' -or $_.Name -in 'ibdata1','ib_logfile0','ib_logfile1' }
        if ($dbFiles) {
            foreach ($db in $dbFiles) {
                $violations += "CUSTOMER DATABASE ARTIFACT FOUND: $($db.FullName)"
            }
        }
    }


    # Check 3: Development .git directories
    $gitDirs = Get-ChildItem -Recurse -Directory -LiteralPath $dir -Filter '.git' -ErrorAction SilentlyContinue
    if ($gitDirs) {
        foreach ($g in $gitDirs) {
            $violations += "SOURCE CONTROL METADATA (.git) FOUND: $($g.FullName)"
        }
    }

    # Check 4: XAMPP path leaks
    $xamppHits = Get-ChildItem -Recurse -File -LiteralPath $dir | Select-String -Pattern 'C:\\xampp|C:/xampp' -ErrorAction SilentlyContinue
    if ($xamppHits) {
        foreach ($hit in $xamppHits) {
            $violations += "HARDCODED XAMPP PATH LEAK: $($hit.Path):$($hit.LineNumber)"
        }
    }

}

if ($violations.Count -gt 0) {
    Write-Host "--------------------------------------------------" -ForegroundColor Red
    Write-Host "SECURITY AUDIT FAILED! Forbidden artifacts detected:" -ForegroundColor Red
    foreach ($v in $violations) {
        Write-Host " - $v" -ForegroundColor Red
    }
    Write-Host "--------------------------------------------------" -ForegroundColor Red
    throw "Security audit failed with $($violations.Count) violation(s)."
}

Write-Host "--------------------------------------------------" -ForegroundColor Green
Write-Host "PASS: Security & hygiene audit completed with 0 violations." -ForegroundColor Green
Write-Host "--------------------------------------------------" -ForegroundColor Green
