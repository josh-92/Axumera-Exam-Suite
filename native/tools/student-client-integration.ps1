<#
    student-client-integration.ps1 - Phase 4 Student client verification
    against the isolated dev runtime (Apache 8090 / MariaDB 3310).

    Prerequisites:
      * dev runtime running (Axumera.Server.exe --headless start)
      * native\build\Axumera.Student\Axumera.Student.exe published
      * the dev app licensed (storage\license.lic present)

    Usage: powershell -File tools\student-client-integration.ps1 [-Mode launch|full]
#>
param([string]$Mode = 'launch')

$ErrorActionPreference = 'Continue'
$canonicalPath = $env:Path
[Environment]::SetEnvironmentVariable('PATH', $null, 'Process')
[Environment]::SetEnvironmentVariable('Path', $canonicalPath, 'Process')
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT

$repo     = 'C:\Axumera-Enginnering'
$Student  = Join-Path $repo 'native\build\Axumera.Student\Axumera.Student.exe'
$StudentDll = Join-Path $repo 'native\build\Axumera.Student\Axumera.Student.dll'
$Config   = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\config\student-server.json'
$Telemetry = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\logs\Axumera.Student.log'
$ApachePort = 8090

if (-not (Test-Path $Student)) { Write-Host "FATAL student client not published: $Student" -ForegroundColor Red; exit 99 }

# Pre-seed the student server config so the connection dialog is skipped.
New-Item -ItemType Directory -Path (Split-Path $Config) -Force | Out-Null
@{ ServerAddress = '127.0.0.1'; ApachePort = $ApachePort } | ConvertTo-Json | Set-Content -Path $Config -Encoding UTF8

# Fresh telemetry so the assertions read only this run.
Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue

$p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($StudentDll) -PassThru
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Milliseconds 500
    $p.Refresh()
    if ($p.MainWindowHandle -ne 0) { break }
    if ($p.HasExited) { break }
}

$results = [System.Collections.Generic.List[string]]::new()
$failures = 0
function Assert([string]$name, [bool]$condition, $detail = '') {
    if ($condition) {
        Write-Host "  PASS $name" -ForegroundColor Green
        $results.Add("PASS $name")
    } else {
        Write-Host "  FAIL $name $detail" -ForegroundColor Red
        $results.Add("FAIL $name $detail")
        $script:failures++
    }
}
function TelemetryContains([string]$marker) {
    $content = Get-Content $Telemetry -Raw -ErrorAction SilentlyContinue
    return $content -and $content -match [regex]::Escape($marker)
}

Assert 'window-appears' ($p.MainWindowHandle -ne 0)

Start-Sleep -Milliseconds 8000
Assert 'process-started' (TelemetryContains 'process-started')
Assert 'splash-shown' (TelemetryContains 'splash-shown')
Assert 'main-form-shown' (TelemetryContains 'main-form-shown')
Assert 'webview-init-complete' (TelemetryContains 'webview-init-complete')
Assert 'connect-flow-started' (TelemetryContains 'connect-flow-started')
Assert 'server-health-ok' (TelemetryContains 'server-health-ok')
Assert 'student-login-navigation-requested' (TelemetryContains 'student-login-navigation-requested')
Assert 'login-page-loaded' (TelemetryContains 'page-loaded')

if ($Mode -eq 'full') {
    # Full interactive exam flow is driven by the caller; here we only assert
    # the client reached the login surface and stays healthy.
    Write-Host ''
    Write-Host 'FULL MODE: launch verification complete. Drive the exam flow via the UI.'
}

if ($p.MainWindowHandle -ne 0) {
    $null = $p.CloseMainWindow()
    $closed = $p.WaitForExit(15000)
    Assert 'closes-cleanly' ($closed -or $p.HasExited) "exit=$($p.ExitCode)"
} else {
    $p.Kill()
    Assert 'closes-cleanly' $false 'no window'
}

Write-Host ''
Write-Host '=================================================='
Write-Host 'PHASE 4 STUDENT CLIENT LAUNCH RESULTS'
Write-Host '=================================================='
foreach ($line in $results) { Write-Host "  $line" }
if ($failures -eq 0) { Write-Host 'ALL LAUNCH TESTS PASSED' -ForegroundColor Green }
else { Write-Host "$failures FAILURE(S)" -ForegroundColor Red }
exit $failures
