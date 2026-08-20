<#
    prepare-dev-runtime.ps1 — Phase 2 isolated development runtime.

    Creates native\dev-runtime from the pristine AXE base (build\runtime) with:
      * separate ports       : Apache 8090, MariaDB 3310 (NOT 8088/3308)
      * separate data        : own data\mariadb copy
      * separate config      : own config\ports.json + generated runtime configs
      * separate logs/state  : own logs\ (cleaned) + own state file
      * app .env DB port     : 3308 -> 3310 (dev copy only)
      * controller config    : %LOCALAPPDATA%\Axumera 2.0\config\server-controller.json

    The production installation and build\runtime are never modified.
    Idempotent: re-running reuses the existing dev-runtime.
#>
$ErrorActionPreference = 'Stop'

$repo   = 'C:\Axumera-Enginnering'
$source = Join-Path $repo 'build\runtime'
$target = Join-Path $repo 'native\dev-runtime'

if (-not (Test-Path $source)) { throw "Source runtime not found: $source" }
Write-Output "Source : $source"
Write-Output "Target : $target"

if (-not (Test-Path $target)) {
    Write-Output "Copying runtime (first run)..."
    Copy-Item -Path $source -Destination $target -Recurse -Force
    # The native controller is the lifecycle owner in Phase 2; drop the legacy
    # console controller/updater from the isolated dev copy to avoid confusion.
    Remove-Item -Path (Join-Path $target 'AxumeraServer.exe') -Force -ErrorAction SilentlyContinue
    Remove-Item -Path (Join-Path $target 'Axumera_Update.exe') -Force -ErrorAction SilentlyContinue
} else {
    Write-Output "Existing dev-runtime found; reusing."
}

# 1. Separate ports.
$portsFile = Join-Path $target 'config\ports.json'
$portsJson = @"
{
    "mariadb":  3310,
    "apache":  8090
}
"@
[System.IO.File]::WriteAllText($portsFile, $portsJson, [System.Text.Encoding]::ASCII)
Write-Output "ports.json -> Apache 8090, MariaDB 3310"

# 2. App .env DB port (dev copy only).
$envFile = Join-Path $target 'application\eaes_exam_system\.env'
if (Test-Path $envFile) {
    $content = Get-Content $envFile -Raw
    $content = $content -replace 'DB_PORT=3308', 'DB_PORT=3310'
    [System.IO.File]::WriteAllText($envFile, $content, [System.Text.Encoding]::UTF8)
    Write-Output ".env DB_PORT -> 3310"
}

# 3. Clean stale state/logs/pid so tests start from a stopped state.
$stateFile = Join-Path $target 'logs\axumera-server.state'
if (Test-Path $stateFile) { Remove-Item $stateFile -Force }
$pidFile = Join-Path $target 'data\mariadb\axumera-mariadb.pid'
if (Test-Path $pidFile) { Remove-Item $pidFile -Force }
$logsDir = Join-Path $target 'logs'
if (Test-Path $logsDir) {
    Get-ChildItem $logsDir -File | Where-Object { $_.Extension -in '.log', '.state' } | Remove-Item -Force
}
Write-Output "Stale state/logs/pid cleared."

# 4. Controller config (runtime root for the native server app).
$configDir = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\config'
New-Item -ItemType Directory -Path $configDir -Force | Out-Null
$controllerJson = @"
{
  "runtimeRoot": "$($target.Replace('\', '\\'))"
}
"@
$controllerFile = Join-Path $configDir 'server-controller.json'
[System.IO.File]::WriteAllText($controllerFile, $controllerJson, [System.Text.Encoding]::UTF8)
Write-Output "Controller config -> $controllerFile"

Write-Output ""
Write-Output "Development runtime ready: $target"
Write-Output "Apache  http://127.0.0.1:8090  ·  MariaDB 127.0.0.1:3310"
