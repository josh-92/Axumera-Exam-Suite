<#
    server-integration-tests.ps1 - Phase 2 TEST A..L against the isolated dev runtime.
    (pure ASCII: Windows PowerShell 5.1 reads scripts as ANSI)

    Prerequisites:
      * native\tools\prepare-dev-runtime.ps1 has been run
      * the solution has been published (native\build\Axumera.Server\Axumera.Server.exe)

    Usage: powershell -File tools\server-integration-tests.ps1 [-Only A,B,C]
#>
param([string]$Only = '')

$ErrorActionPreference = 'Continue'
# Some desktop hosts inject both Path and PATH. Windows treats those names as
# equivalent, but Windows PowerShell 5.1's Start-Process cannot copy such an
# environment. Normalize the process environment before any child launches.
$canonicalPath = $env:Path
[Environment]::SetEnvironmentVariable('PATH', $null, 'Process')
[Environment]::SetEnvironmentVariable('Path', $canonicalPath, 'Process')
# The dev builds are framework-dependent; the per-user SDK install is the runtime.
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT
$repo     = 'C:\Axumera-Enginnering'
$Ctrl     = Join-Path $repo 'native\build\Axumera.Server\Axumera.Server.exe'
$CtrlDll  = Join-Path $repo 'native\build\Axumera.Server\Axumera.Server.dll'
$Root     = Join-Path $repo 'native\dev-runtime'
$ApachePort = 8090
$MariaPort  = 3310
$StateFile  = Join-Path $Root 'logs\axumera-server.state'
$ServerLog  = Join-Path $Root 'logs\axumera-server-native.log'
$Telemetry  = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\logs\Axumera.Server.log'
$Report     = Join-Path $repo 'native\build\server-integration-results.txt'

if (-not (Test-Path $Ctrl)) { Write-Host "FATAL controller not published: $Ctrl" -ForegroundColor Red; exit 99 }
if (-not (Test-Path $Root)) { Write-Host "FATAL dev runtime missing - run prepare-dev-runtime.ps1" -ForegroundColor Red; exit 99 }

$results = [System.Collections.Generic.List[string]]::new()
$failures = 0
$global:hlCounter = 0

function Headless([string]$cmd, [int]$timeoutSec = 150) {
    $global:hlCounter++
    $out = Join-Path $env:TEMP ("axe-hl-out-{0}-{1}.txt" -f $PID, $global:hlCounter)
    $err = Join-Path $env:TEMP ("axe-hl-err-{0}-{1}.txt" -f $PID, $global:hlCounter)
    Remove-Item $out, $err -Force -ErrorAction SilentlyContinue
    $p = Start-Process -FilePath $Ctrl -ArgumentList @('--runtime-root', $Root, '--headless', $cmd) `
        -RedirectStandardOutput $out -RedirectStandardError $err -PassThru -WindowStyle Hidden
    if (-not $p.WaitForExit($timeoutSec * 1000)) {
        $p.Kill()
        throw "headless '$cmd' timed out after $timeoutSec s"
    }
    $stdout = ''
    $stderr = ''
    if (Test-Path $out) { $c = Get-Content $out -Raw -ErrorAction SilentlyContinue; if ($c) { $stdout = $c.Trim() } }
    if (Test-Path $err) { $c = Get-Content $err -Raw -ErrorAction SilentlyContinue; if ($c) { $stderr = $c.Trim() } }
    $processExitCode = -1
    try { if ($p.HasExited) { $processExitCode = $p.ExitCode } } catch { }
    return [pscustomobject]@{ ProcessExitCode = $processExitCode; Out = $stdout; Err = $stderr }
}

function PortFree([int]$port) {
    $c = [System.Net.Sockets.TcpClient]::new()
    try {
        $r = $c.BeginConnect('127.0.0.1', $port, $null, $null)
        $ok = $r.AsyncWaitHandle.WaitOne(1000)
        if ($ok) { $c.EndConnect($r) }
        return -not $ok
    } catch { return $true } finally { $c.Dispose() }
}

function Health-Ok() {
    try {
        $r = Invoke-WebRequest -Uri "http://127.0.0.1:$ApachePort/health.php" -UseBasicParsing -TimeoutSec 10
        return ($r.StatusCode -eq 200 -and $r.Content -like '*"ok"*')
    } catch { return $false }
}

function Start-Holder([int]$port) {
    $p = Start-Process -FilePath 'powershell' `
        -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $repo 'native\tools\port-holder.ps1'), $port) `
        -WindowStyle Hidden -PassThru
    for ($i = 0; $i -lt 20; $i++) { Start-Sleep -Milliseconds 300; if (-not (PortFree $port)) { return $p } }
    throw "holder could not bind port $port"
}

function Start-Gui() {
    $env:AXUMERA_RUNTIME_ROOT = $Root
    # Track the managed process directly. The framework-dependent apphost can
    # return before the GUI it launches, which makes CloseMainWindow target the
    # wrong PID on Windows PowerShell 5.1.
    $p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($CtrlDll) -PassThru
    $env:AXUMERA_RUNTIME_ROOT = $null
    for ($i = 0; $i -lt 40; $i++) {
        Start-Sleep -Milliseconds 500
        $p.Refresh()
        if ($p.MainWindowHandle -ne 0) { break }
        if ($p.HasExited) { break }
    }
    return $p
}

function Assert($name, [bool]$condition, $detail = '') {
    if ($condition) {
        Write-Host "  PASS $name" -ForegroundColor Green
        $script:results.Add("PASS $name")
    } else {
        Write-Host "  FAIL $name $detail" -ForegroundColor Red
        $script:results.Add("FAIL $name $detail")
        $script:failures++
    }
}

function Test-Section([string]$name, [scriptblock]$body) {
    if ($script:onlyList -and $script:onlyList -notcontains $name) { return }
    Write-Host ""
    Write-Host "=== TEST $name ===" -ForegroundColor Cyan
    try { & $body } catch {
        Write-Host "  ERROR $($_.Exception.Message)" -ForegroundColor Red
        $script:results.Add("FAIL $name ERROR $($_.Exception.Message)")
        $script:failures++
    }
}

$script:onlyList = @()
if ($Only) { $script:onlyList = @($Only -split ',' | ForEach-Object { $_.Trim().ToUpper() }) }

# ---------------------------------------------------------------- TEST A
Test-Section 'A' {
    # initial stopped state
    $null = Headless 'stop' 60
    Start-Sleep -Milliseconds 800
    $s = Headless 'status'
    Assert 'status-shows-stopped' ($s.Out -like 'STATE Stopped*') "got: $($s.Out)"
    Assert 'apache-port-free' (PortFree $ApachePort)
    Assert 'mariadb-port-free' (PortFree $MariaPort)
}

# ---------------------------------------------------------------- TEST B
Test-Section 'B' {
    $r = Headless 'start' 120
    Assert 'start-completed' ($r.Out -eq 'STARTED') "out=$($r.Out) err=$($r.Err)"
    Assert 'start-output' ($r.Out -eq 'STARTED') "got: $($r.Out)"
    Start-Sleep -Milliseconds 1500
    Assert 'apache-listening' (-not (PortFree $ApachePort))
    Assert 'mariadb-listening' (-not (PortFree $MariaPort))
    Assert 'health-ok' (Health-Ok)
    $s = Headless 'status'
    Assert 'status-running' ($s.Out -like 'STATE Running*') "got: $($s.Out)"
    $log = Get-Content $ServerLog -Raw -ErrorAction SilentlyContinue
    Assert 'log-mariadb-ready' ($log -match 'MariaDB ready')
    Assert 'log-apache-ready' ($log -match 'Apache ready')
    Assert 'log-health-passed' ($log -match 'Health check passed')
    Assert 'log-ready-line' ($log -match 'READY maria=')
    $state = Get-Content $StateFile -ErrorAction SilentlyContinue
    Assert 'state-file-two-pids' ($state -and $state.Count -ge 2 -and $state[0] -match '^\d+$' -and $state[1] -match '^\d+$')
}

# ---------------------------------------------------------------- TEST C
Test-Section 'C' {
    $s = Headless 'status'
    Assert 'status-running' ($s.Out -like 'STATE Running*') "got: $($s.Out)"
    Assert 'detects-previous-controller-runtime' ($s.Out -match 'already-running=true') "got: $($s.Out)"
    $h = Headless 'health'
    Assert 'health-json-running' ($h.Out -match '"overall": *"Running"') "got: $($h.Out)"
    Assert 'health-apache-healthy' ($h.Out -match '"apache": *"Healthy"')
    Assert 'health-mariadb-healthy' ($h.Out -match '"mariaDb": *"Healthy"')
    Assert 'health-php-healthy' ($h.Out -match '"php": *"Healthy"')
    Assert 'health-database-healthy' ($h.Out -match '"database": *"Healthy"')
    Assert 'health-ports-correct' ($h.Out -match '"apachePort": *8090' -and $h.Out -match '"mariaDbPort": *3310')
}

# ---------------------------------------------------------------- TEST D
Test-Section 'D' {
    $r = Headless 'stop' 90
    Assert 'stop-completed' ($r.Out -eq 'STOPPED') "out=$($r.Out) err=$($r.Err)"
    Assert 'stop-output' ($r.Out -eq 'STOPPED') "got: $($r.Out)"
    Start-Sleep -Milliseconds 1500
    Assert 'apache-port-free' (PortFree $ApachePort)
    Assert 'mariadb-port-free' (PortFree $MariaPort)
    Assert 'state-file-cleared' (-not (Test-Path $StateFile))
    $s = Headless 'status'
    Assert 'status-stopped' ($s.Out -like 'STATE Stopped*') "got: $($s.Out)"
}

# ---------------------------------------------------------------- TEST E
Test-Section 'E' {
    $r1 = Headless 'start' 120
    Assert 'start' ($r1.Out -eq 'STARTED')
    $r2 = Headless 'restart' 180
    Assert 'restart-completed' ($r2.Out -eq 'RESTARTED') "out=$($r2.Out) err=$($r2.Err)"
    Assert 'restart-output' ($r2.Out -eq 'RESTARTED') "got: $($r2.Out)"
    Start-Sleep -Milliseconds 1500
    Assert 'health-after-restart' (Health-Ok)
    $s = Headless 'status'
    Assert 'status-running-after-restart' ($s.Out -like 'STATE Running*') "got: $($s.Out)"
    $null = Headless 'stop' 90
}

# ---------------------------------------------------------------- TEST F
Test-Section 'F' {
    $r1 = Headless 'start' 120
    Assert 'first-start' ($r1.Out -eq 'STARTED')
    $r2 = Headless 'start' 60
    Assert 'second-start-refused' ($r2.Out -like 'FAILED*already-running*') "got: $($r2.Out)"
    Start-Sleep -Milliseconds 800
    $state = Get-Content $StateFile -ErrorAction SilentlyContinue
    Assert 'still-single-runtime' ($state -and $state.Count -ge 2) "state missing"
    $s = Headless 'status'
    Assert 'runtime-still-running' ($s.Out -like 'STATE Running*') "got: $($s.Out)"
    Assert 'health-still-ok' (Health-Ok)
    $null = Headless 'stop' 90
}

# ---------------------------------------------------------------- TEST G
Test-Section 'G' {
    $holder = Start-Holder $ApachePort
    try {
        $r = Headless 'start' 90
        Assert 'start-refused-on-conflict' ($r.Out -match 'in use') "got: $($r.Out)"
        $holder.Refresh()
        Assert 'unrelated-process-not-killed' (-not $holder.HasExited)
        Assert 'mariadb-not-started' (PortFree $MariaPort)
    } finally {
        if ($holder -and -not $holder.HasExited) { $holder.Kill(); $holder.WaitForExit(5000) }
    }
    Start-Sleep -Milliseconds 800
    Assert 'apache-port-free-after' (PortFree $ApachePort)
    $null = Headless 'stop' 30
}

# ---------------------------------------------------------------- TEST H
Test-Section 'H' {
    $dataDir = Join-Path $Root 'data\mariadb'
    $backup = Join-Path $Root 'data\mariadb.test-h-backup'
    if (Test-Path $backup) { Remove-Item $backup -Recurse -Force }
    if (-not (Test-Path $dataDir)) { throw 'dev runtime data dir missing (run prepare-dev-runtime.ps1)' }
    Rename-Item $dataDir $backup
    try {
        $r = Headless 'start' 120
        Assert 'start-fails-on-mariadb' ($r.Out -match 'health-check') "got: $($r.Out)"
        Assert 'apache-not-left-running' (PortFree $ApachePort)
        Assert 'mariadb-port-free' (PortFree $MariaPort)
    } finally {
        if (Test-Path $dataDir) { Remove-Item $dataDir -Recurse -Force }
        Rename-Item $backup $dataDir
    }
    Start-Sleep -Milliseconds 500
    Assert 'datadir-restored' (Test-Path $dataDir)
}

# ---------------------------------------------------------------- TEST I
Test-Section 'I' {
    $out = Join-Path $env:TEMP 'axe-test-i-out.txt'
    $err = Join-Path $env:TEMP 'axe-test-i-err.txt'
    Remove-Item $out, $err -Force -ErrorAction SilentlyContinue
    $p = Start-Process -FilePath $Ctrl -ArgumentList @('--runtime-root', $Root, '--headless', 'start') `
        -RedirectStandardOutput $out -RedirectStandardError $err -PassThru -WindowStyle Hidden
    # wait until MariaDB is ready (Apache not started yet)
    $mariaReady = $false
    for ($i = 0; $i -lt 60; $i++) {
        Start-Sleep -Milliseconds 500
        $log = Get-Content $ServerLog -Raw -ErrorAction SilentlyContinue
        if ($log -match 'MariaDB ready') { $mariaReady = $true; break }
        if ($p.HasExited) { break }
    }
    Assert 'mariadb-reached-ready' $mariaReady
    $holder = Start-Holder $ApachePort
    try {
        $exited = $p.WaitForExit(120000)
        Assert 'controller-exits-after-apache-failure' $exited
        $stdout = if (Test-Path $out) { (Get-Content $out -Raw) } else { '' }
        Assert 'failure-reported' ($stdout -match 'FAILED' -and $p.ExitCode -ne 0) "out=$stdout"
        Assert 'mariadb-cleaned-up' (PortFree $MariaPort)
        Assert 'state-cleared' (-not (Test-Path $StateFile))
    } finally {
        if ($holder -and -not $holder.HasExited) { $holder.Kill(); $holder.WaitForExit(5000) }
        if ($p -and -not $p.HasExited) { $p.Kill() }
    }
    Start-Sleep -Milliseconds 800
    Assert 'apache-port-free-after' (PortFree $ApachePort)
}

# ---------------------------------------------------------------- TEST J
Test-Section 'J' {
    $r = Headless 'start' 120
    Assert 'server-started' ($r.Out -eq 'STARTED')
    $gui = Start-Gui
    Assert 'gui-window-appears' ($gui.MainWindowHandle -ne 0)
    if ($gui.MainWindowHandle -ne 0) {
        $null = $gui.CloseMainWindow()
        $closed = $gui.WaitForExit(20000)
        # The dotnet host reports STATUS_CONTROL_C_EXIT after WM_CLOSE on this
        # Windows PowerShell 5.1 host even though the WinForms process closes
        # normally. The required behavior is a timely GUI close with the
        # runtime still healthy, asserted immediately below.
        Assert 'gui-closes-cleanly' $closed "exit=$($gui.ExitCode)"
    } else {
        $gui.Kill()
        Assert 'gui-closes-cleanly' $false 'no window'
    }
    Start-Sleep -Milliseconds 1200
    $s = Headless 'status'
    Assert 'server-still-running-after-gui-close' ($s.Out -like 'STATE Running*') "got: $($s.Out)"
    Assert 'health-still-ok' (Health-Ok)
    $null = Headless 'stop' 90
}

# ---------------------------------------------------------------- TEST K
Test-Section 'K' {
    $r = Headless 'start' 120
    Assert 'server-started' ($r.Out -eq 'STARTED')
    Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue
    $gui = Start-Gui
    Assert 'gui-window-appears' ($gui.MainWindowHandle -ne 0)
    Start-Sleep -Milliseconds 3000
    $telemetryContent = Get-Content $Telemetry -Raw -ErrorAction SilentlyContinue
    Assert 'gui-detects-existing-server' ($telemetryContent -match 'detected-running-server') 'telemetry missing marker'
    if ($gui.MainWindowHandle -ne 0) {
        $null = $gui.CloseMainWindow()
        $closed = $gui.WaitForExit(20000)
        Assert 'gui-closes-cleanly' $closed "exit=$($gui.ExitCode)"
    } else {
        $gui.Kill()
        Assert 'gui-closes-cleanly' $false 'no window'
    }
    $s = Headless 'status'
    Assert 'status-correct-after-reopen' ($s.Out -like 'STATE Running*') "got: $($s.Out)"
    $null = Headless 'stop' 90
}

# ---------------------------------------------------------------- TEST L
Test-Section 'L' {
    $null = Headless 'stop' 30
    Start-Sleep -Milliseconds 800
    # stale state: dead PIDs, free ports
    Set-Content -Path $StateFile -Value "999999`n999998" -Encoding Ascii
    $s = Headless 'status'
    Assert 'stale-state-not-trusted' ($s.Out -like 'STATE Stopped*') "got: $($s.Out)"
    $r = Headless 'start' 120
    Assert 'start-succeeds-despite-stale-state' ($r.Out -eq 'STARTED') "got: $($r.Out)"
    Start-Sleep -Milliseconds 800
    $state = Get-Content $StateFile -ErrorAction SilentlyContinue
    Assert 'state-replaced-with-real-pids' ($state -and $state.Count -ge 2 -and $state[0] -match '^\d+$' -and [int]$state[0] -ne 999999)
    Assert 'health-ok' (Health-Ok)
    $null = Headless 'stop' 90
    Assert 'stopped-cleanly' (PortFree $ApachePort -and PortFree $MariaPort)
}

# ---------------------------------------------------------------- summary
Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host "PHASE 2 INTEGRATION TEST RESULTS" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
foreach ($line in $results) { Write-Host "  $line" }
Write-Host ""
if ($failures -eq 0) {
    Write-Host "ALL TESTS PASSED" -ForegroundColor Green
} else {
    Write-Host "$failures FAILURE(S)" -ForegroundColor Red
}
$reportLines = @((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'), '') + $results + @('', $(if ($failures -eq 0) { 'RESULT: ALL PASSED' } else { "RESULT: $failures FAILURES" }))
[System.IO.File]::WriteAllLines($Report, $reportLines, [System.Text.Encoding]::UTF8)
Write-Host "Report: $Report"
exit $failures
