[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

Write-Host "=================================================="
Write-Host "TEST: Automated Update Rollback Verification"
Write-Host "=================================================="

$testRuntimeDir = Join-Path $root "build\test-rollback-runtime"
if (Test-Path $testRuntimeDir) {
    $maria = Get-Process mysqld -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$testRuntimeDir*" }
    if ($maria) { Stop-Process -Id $maria.Id -Force -ErrorAction SilentlyContinue }
    $httpd = Get-Process httpd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$testRuntimeDir*" }
    if ($httpd) { Stop-Process -Id $httpd.Id -Force -ErrorAction SilentlyContinue }
    Remove-Item -Recurse -Force -LiteralPath $testRuntimeDir
}

# 1. Prepare base runtime in build/runtime
Write-Host "Building disposable base runtime..."
& (Join-Path $PSScriptRoot 'build-runtime.ps1') -Force
& (Join-Path $PSScriptRoot 'build-controller.ps1')
& (Join-Path $PSScriptRoot 'build-launchers.ps1')
& (Join-Path $PSScriptRoot 'build-updater.ps1')

$source = Join-Path $root 'build\runtime'

# Initialize synthetic database in build/runtime
Write-Host "Initializing synthetic customer database in base runtime..."
$secPass = ConvertTo-SecureString "TestAdminPass123!" -AsPlainText -Force
& (Join-Path $PSScriptRoot 'initialize-database.ps1') -AdminUsername "testadmin" -AdminPassword $secPass -AppName "Axumera Rollback Test Site"

# Start MariaDB in base runtime to seed synthetic customer data
Write-Host "Seeding synthetic customer data..."
$dbBin = "$source\runtime\mariadb\bin"
$dbProc = Start-Process -FilePath "$dbBin\mysqld.exe" -ArgumentList "--defaults-file=$source\config\axumera-my.ini" -PassThru
for ($i=0; $i -lt 30; $i++) {
    & "$dbBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 ping --silent 2>$null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 1
}

try {
    $seedSql = "INSERT INTO students (full_name, roll_number, stream, section) VALUES ('Pre-Rollback Student', 9999, 'Natural', 'B'); INSERT INTO settings (setting_key, setting_value) VALUES ('rollback_test_key', 'PreRollbackValue') ON DUPLICATE KEY UPDATE setting_value='PreRollbackValue';"
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -e $seedSql

} finally {
    try { & "$dbBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 shutdown 2>$null } catch {}
    if ($dbProc -and !$dbProc.HasExited) {
        $null = $dbProc.WaitForExit(5000)
        if (!$dbProc.HasExited) { Stop-Process -Id $dbProc.Id -Force -ErrorAction SilentlyContinue }
    }
}


# Copy base runtime to testRuntimeDir
Copy-Item -Recurse -Force $source $testRuntimeDir
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Admin.exe') $testRuntimeDir
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Update.exe') $testRuntimeDir
# The generated MariaDB configuration contains installation-derived absolute
# paths. Rebase it for this disposable clone before the updater starts MariaDB.
$sourceRuntimePath = $source.Replace('\', '/')
$testRuntimePath = $testRuntimeDir.Replace('\', '/')
$cloneMyIni = Join-Path $testRuntimeDir 'config\axumera-my.ini'
(Get-Content -Raw $cloneMyIni).Replace($sourceRuntimePath, $testRuntimePath) | Set-Content -NoNewline -Encoding ascii $cloneMyIni

# 2. Build a CORRUPTED 1.2.0 Update Package with invalid SQL migration
Write-Host "Building corrupted 1.2.0 update package (simulating migration failure)..."
$migDir = Join-Path $root 'application\eaes_exam_system\database\migrations'
$badMigFile = Join-Path $migDir '2026_08_05_999999_failing_corrupted_migration.sql'
Set-Content -Encoding utf8 $badMigFile "THIS IS AN INTENTIONALLY BROKEN SQL SYNTAX ERROR THAT WILL FAIL MIGRATION;"

try {
    & (Join-Path $PSScriptRoot 'build-update.ps1') -TargetVersion '1.2.0'

    # 3. Execute Axumera_Update.exe against testRuntimeDir (expecting failure)
    Write-Host "Executing Axumera_Update.exe against test runtime (expecting failure)..."
    $pkgDir = Join-Path $root 'distribution\staging\Axumera_Update'
    $updaterExe = Join-Path $pkgDir 'Axumera_Update.exe'

    $proc = Start-Process -FilePath $updaterExe -WorkingDirectory $testRuntimeDir -PassThru
    while (!$proc.HasExited) { Start-Sleep -Milliseconds 100 }

    if ($proc.ExitCode -eq 0) {
        throw "Axumera_Update.exe INCORRECTLY reported success on a corrupted update package!"
    }
    Write-Host "Updater correctly failed with non-zero exit code: $($proc.ExitCode)"

    # 4. Verify System Health and Restored Baseline
    Write-Host "Verifying that rollback restored operational system health..."
    $res = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:8088/health.php" -TimeoutSec 15
    [string]$body = "$($res.Content)"
    if ($res.StatusCode -ne 200 -or $body -notmatch '"status"\s*:\s*"ok"') {
        throw "Health check failed after rollback!"
    }


    # Stop server to verify database content safely
    $controllerExe = Join-Path $testRuntimeDir 'AxumeraServer.exe'
    & $controllerExe "stop" | Out-Null
    Start-Sleep -Seconds 2

    # Verify synthetic customer data survived intact
    $dbTestBin = "$testRuntimeDir\runtime\mariadb\bin"
    $dbTestProc = Start-Process -FilePath "$dbTestBin\mysqld.exe" -ArgumentList "--defaults-file=$testRuntimeDir\config\axumera-my.ini" -PassThru

    for ($i=0; $i -lt 30; $i++) {
        & "$dbTestBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 ping --silent 2>$null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 1
    }

    try {
        [string]$studentCheck = & "$dbTestBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -N -e "SELECT full_name FROM students WHERE roll_number=9999;"
        if ($studentCheck.Trim() -ne 'Pre-Rollback Student') { throw "Synthetic student record was lost during failed update!" }

        [string]$settingCheck = & "$dbTestBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -N -e "SELECT setting_value FROM settings WHERE setting_key='rollback_test_key';"
        if ($settingCheck.Trim() -ne 'PreRollbackValue') { throw "Synthetic setting record was lost during failed update!" }


        Write-Host "PASS: Synthetic customer data and database schema successfully restored by rollback!" -ForegroundColor Green
    } finally {
        try { & "$dbTestBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 shutdown 2>$null } catch {}
        if ($dbTestProc -and !$dbTestProc.HasExited) {
            $null = $dbTestProc.WaitForExit(5000)
            if (!$dbTestProc.HasExited) { Stop-Process -Id $dbTestProc.Id -Force -ErrorAction SilentlyContinue }
        }
    }




    $verContent = Get-Content -Raw "$testRuntimeDir\application\eaes_exam_system\VERSION"
    if ($verContent.Trim() -ne '1.0.0') { throw "VERSION file was not restored to 1.0.0 by rollback!" }

    Write-Host "--------------------------------------------------" -ForegroundColor Green
    Write-Host "PASS: Automated rollback verification test SUCCESSFUL!" -ForegroundColor Green
    Write-Host "--------------------------------------------------" -ForegroundColor Green
} finally {
    Remove-Item -Force -ErrorAction SilentlyContinue $badMigFile
}
