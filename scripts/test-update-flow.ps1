[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

Write-Host "=================================================="
Write-Host "TEST: Transactional Update & Data Preservation Flow"
Write-Host "=================================================="

$testRuntimeDir = Join-Path $root "build\test-update-runtime"
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
& (Join-Path $PSScriptRoot 'initialize-database.ps1') -AdminUsername "testadmin" -AdminPassword $secPass -AppName "Axumera Test Site"

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
    $seedSql = "INSERT INTO students (full_name, roll_number, stream, section) VALUES ('Test Student 1', 1001, 'Natural', 'A'); INSERT INTO settings (setting_key, setting_value) VALUES ('custom_customer_key', 'CustomerPreservedValue') ON DUPLICATE KEY UPDATE setting_value='CustomerPreservedValue';"
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -e $seedSql

} finally {
    try { & "$dbBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 shutdown 2>$null } catch {}
    if ($dbProc -and !$dbProc.HasExited) {
        $null = $dbProc.WaitForExit(5000)
        if (!$dbProc.HasExited) { Stop-Process -Id $dbProc.Id -Force -ErrorAction SilentlyContinue }
    }

}



# Copy base runtime (with seeded DB, .env, installed.lock) to testRuntimeDir
Copy-Item -Recurse -Force $source $testRuntimeDir
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Admin.exe') $testRuntimeDir
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Update.exe') $testRuntimeDir
# The generated MariaDB configuration contains installation-derived absolute
# paths. Rebase it for this disposable clone before the updater starts MariaDB.
$sourceRuntimePath = $source.Replace('\', '/')
$testRuntimePath = $testRuntimeDir.Replace('\', '/')
$cloneMyIni = Join-Path $testRuntimeDir 'config\axumera-my.ini'
(Get-Content -Raw $cloneMyIni).Replace($sourceRuntimePath, $testRuntimePath) | Set-Content -NoNewline -Encoding ascii $cloneMyIni

# 2. Build 1.1.0 Update Package with a new migration
Write-Host "Building 1.1.0 update package with new database migration..."
$migDir = Join-Path $root 'application\eaes_exam_system\database\migrations'
$newMigFile = Join-Path $migDir '2026_08_05_120000_add_test_upgrade_column.sql'
Set-Content -Encoding utf8 $newMigFile "ALTER TABLE ``settings`` ADD COLUMN ``upgrade_notes`` VARCHAR(255) DEFAULT 'v1.1.0'; INSERT IGNORE INTO ``schema_migrations`` (``version``) VALUES ('2026_08_05_120000_add_test_upgrade_column');"

try {
    & (Join-Path $PSScriptRoot 'build-update.ps1') -TargetVersion '1.1.0'

    # 3. Execute Axumera_Update.exe against testRuntimeDir
    Write-Host "Executing Axumera_Update.exe against test runtime..."
    $pkgDir = Join-Path $root 'distribution\staging\Axumera_Update'
    $updaterExe = Join-Path $pkgDir 'Axumera_Update.exe'

    $proc = Start-Process -FilePath $updaterExe -WorkingDirectory $testRuntimeDir -PassThru
    while (!$proc.HasExited) { Start-Sleep -Milliseconds 100 }

    if ($proc.ExitCode -ne 0) {
        throw "Axumera_Update.exe failed with exit code $($proc.ExitCode)"
    }

    # 4. Verify Post-Update Operational & Data State
    Write-Host "Verifying updated system operational state..."
    $res = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:8088/health.php" -TimeoutSec 15
    [string]$body = "$($res.Content)"
    if ($res.StatusCode -ne 200 -or $body -notmatch '"status"\s*:\s*"ok"') {
        throw "Post-update health check failed!"
    }


    # Stop server to verify database content safely
    $controllerExe = Join-Path $testRuntimeDir 'AxumeraServer.exe'
    & $controllerExe "stop" | Out-Null
    Start-Sleep -Seconds 2

    # Verify synthetic customer data survived
    $dbTestBin = "$testRuntimeDir\runtime\mariadb\bin"
    $dbTestProc = Start-Process -FilePath "$dbTestBin\mysqld.exe" -ArgumentList "--defaults-file=$testRuntimeDir\config\axumera-my.ini" -PassThru

    for ($i=0; $i -lt 30; $i++) {
        & "$dbTestBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 ping --silent 2>$null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 1
    }

    try {
        $rawStudent = & "$dbTestBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -N -e "SELECT full_name FROM students WHERE roll_number=1001;"
        $studentCheck = if ($rawStudent) { "$rawStudent".Trim() } else { "" }
        if ($studentCheck -ne 'Test Student 1') { throw "Synthetic student record was lost during update!" }

        $rawSetting = & "$dbTestBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -N -e "SELECT setting_value FROM settings WHERE setting_key='custom_customer_key';"
        $settingCheck = if ($rawSetting) { "$rawSetting".Trim() } else { "" }
        if ($settingCheck -ne 'CustomerPreservedValue') { throw "Synthetic setting record was lost during update!" }

        $rawMig = & "$dbTestBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P 3308 -u root eaes_exam -N -e "SELECT version FROM schema_migrations WHERE version='2026_08_05_120000_add_test_upgrade_column';"
        $migCheck = if ($rawMig) { "$rawMig".Trim() } else { "" }
        if ($migCheck -ne '2026_08_05_120000_add_test_upgrade_column') { throw "Database migration record was not logged in schema_migrations!" }



        Write-Host "PASS: Synthetic student data, custom settings, and new schema migration verified in database!" -ForegroundColor Green
    } finally {
        try { & "$dbTestBin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P 3308 shutdown 2>$null } catch {}
        if ($dbTestProc -and !$dbTestProc.HasExited) {
            $null = $dbTestProc.WaitForExit(5000)
            if (!$dbTestProc.HasExited) { Stop-Process -Id $dbTestProc.Id -Force -ErrorAction SilentlyContinue }
        }
    }




    $verContent = Get-Content -Raw "$testRuntimeDir\application\eaes_exam_system\VERSION"
    if ($verContent.Trim() -ne '1.1.0') { throw "VERSION file was not updated to 1.1.0!" }

    Write-Host "--------------------------------------------------" -ForegroundColor Green
    Write-Host "PASS: Transactional update and data preservation test SUCCESSFUL!" -ForegroundColor Green
    Write-Host "--------------------------------------------------" -ForegroundColor Green
} finally {
    Remove-Item -Force -ErrorAction SilentlyContinue $newMigFile
}
