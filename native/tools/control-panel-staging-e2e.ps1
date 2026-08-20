<#
    control-panel-staging-e2e.ps1 - Control Panel E2E against the isolated
    STAGING runtime (Apache 8188 / MariaDB 3388) - FINAL ARCHITECTURE.

    Architecture under test:
        Axumera.Server.exe   = the SOLE server lifecycle controller
                              (--headless start|stop|restart|status|health)
        Axumera.ControlPanel = administration WebView shell ONLY
                              (no server lifecycle controls, never starts/
                              stops/restarts the server)

    The test drives the runtime with the Server EXE, launches the published
    Control Panel against the staging tree, and proves:
      * the Server EXE lifecycle commands work (start/status/health)
      * the Control Panel launches and its Admin WebView loads adminlogin.php
      * the Control Panel chrome contains NO lifecycle controls
        (START SERVER / STOP SERVER / RESTART / SERVER STATUS / DIAGNOSTICS /
         SERVER RUNNING / SERVER NOT RUNNING)
      * closing the Control Panel does NOT stop the server
      * a Control Panel launched while the server is stopped CANNOT start it
        (ports stay free, no server processes, professional error state shown)

    Usage: powershell -File tools\control-panel-staging-e2e.ps1
#>
$ErrorActionPreference = 'Continue'
$repo = 'C:\Axumera-Enginnering'
$stagingRoot = Join-Path $repo 'staging'
$serverExe = Join-Path $repo 'native\build\Axumera.Server\Axumera.Server.exe'
$cpExe = Join-Path $repo 'native\build\Axumera.ControlPanel\Axumera.ControlPanel.exe'
$cpLog = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\logs\Axumera.ControlPanel.log'
$accessLog = Join-Path $stagingRoot 'logs\apache-access.log'
$screenshot = Join-Path $repo 'native\build\control-panel-staging.png'
$apachePort = 8188
$mariaPort = 3388

if (-not (Test-Path $serverExe)) { Write-Host "FATAL server exe missing: $serverExe" -ForegroundColor Red; exit 99 }
if (-not (Test-Path $cpExe)) { Write-Host "FATAL control panel exe missing: $cpExe" -ForegroundColor Red; exit 99 }

Add-Type -AssemblyName System.Windows.Forms
Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class Win32Cp {
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern IntPtr FindWindowW(string lpClassName, string lpWindowName);
    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")] public static extern bool EnumChildWindows(IntPtr hWndParent, EnumWindowsProc lpEnumFunc, IntPtr lParam);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetWindowText(IntPtr hWnd, System.Text.StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT rect);
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
    public const uint BM_CLICK = 0x00F5;
    public static IntPtr FindTopLevelContaining(string text) {
        IntPtr found = IntPtr.Zero;
        EnumWindows((h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetWindowText(h, sb, 256);
            if (sb.ToString().IndexOf(text, StringComparison.OrdinalIgnoreCase) >= 0) { found = h; return false; }
            return true;
        }, IntPtr.Zero);
        return found;
    }
    public static IntPtr FindChildContaining(IntPtr parent, string text) {
        IntPtr found = IntPtr.Zero;
        EnumChildWindows(parent, (h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetWindowText(h, sb, 256);
            if (sb.ToString().IndexOf(text, StringComparison.OrdinalIgnoreCase) >= 0) { found = h; return false; }
            return true;
        }, IntPtr.Zero);
        return found;
    }
    public static string[] ChildTexts(IntPtr parent) {
        var list = new System.Collections.Generic.List<string>();
        EnumChildWindows(parent, (h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetWindowText(h, sb, 256);
            if (sb.Length > 0) list.Add(sb.ToString());
            return true;
        }, IntPtr.Zero);
        return list.ToArray();
    }
}
"@

$results = [System.Collections.Generic.List[string]]::new()
$failures = 0
function Assert([string]$name, [bool]$condition, $detail = '') {
    $stamp = (Get-Date).ToString('HH:mm:ss')
    if ($condition) { Write-Host "  [$stamp] PASS $name" -ForegroundColor Green; $results.Add("PASS $name") }
    else { Write-Host "  [$stamp] FAIL $name $detail" -ForegroundColor Red; $results.Add("FAIL $name $detail"); $script:failures++ }
}
function TelemetryContains([string]$marker) {
    $content = Get-Content $cpLog -Raw -ErrorAction SilentlyContinue
    return $content -and $content -match [regex]::Escape($marker)
}
function Wait-Telemetry([string]$marker, [int]$timeoutSec = 30) {
    $deadline = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $deadline) {
        if (TelemetryContains $marker) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
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
$script:hlCounter = 0
function Headless([string]$cmd, [int]$timeoutSec = 180) {
    $script:hlCounter++
    $out = Join-Path $env:TEMP ("axe-cp-hl-out-{0}-{1}.txt" -f $PID, $script:hlCounter)
    $err = Join-Path $env:TEMP ("axe-cp-hl-err-{0}-{1}.txt" -f $PID, $script:hlCounter)
    Remove-Item $out, $err -Force -ErrorAction SilentlyContinue
    $p = Start-Process -FilePath $serverExe -ArgumentList @('--runtime-root', $stagingRoot, '--headless', $cmd) `
        -RedirectStandardOutput $out -RedirectStandardError $err -PassThru -WindowStyle Hidden
    if (-not $p.WaitForExit($timeoutSec * 1000)) {
        $p.Kill() | Out-Null
        return [pscustomobject]@{ ProcessExitCode = -1; Out = 'TIMEOUT'; Err = '' }
    }
    $stdout = ''; $stderr = ''
    if (Test-Path $out) { $c = Get-Content $out -Raw -ErrorAction SilentlyContinue; if ($c) { $stdout = $c.Trim() } }
    if (Test-Path $err) { $c = Get-Content $err -Raw -ErrorAction SilentlyContinue; if ($c) { $stderr = $c.Trim() } }
    $exited = $p.HasExited
    return [pscustomobject]@{ Exited = $exited; Out = $stdout; Err = $stderr }
}
function Launch-Cp() {
    $env:AXUMERA_RUNTIME_ROOT = $stagingRoot
    $p = Start-Process -FilePath $cpExe -PassThru
    if ($null -eq $p) { Write-Host 'FATAL: Control Panel process did not start.' -ForegroundColor Red; exit 97 }
    $env:AXUMERA_RUNTIME_ROOT = $null
    for ($i = 0; $i -lt 60; $i++) {
        Start-Sleep -Milliseconds 500
        $p.Refresh()
        if ($p.MainWindowHandle -ne 0) { break }
        if ($p.HasExited) { break }
    }
    return $p
}
function Resolve-MainHwnd([System.Diagnostics.Process]$p) {
    $h = [IntPtr]::Zero
    $deadline = (Get-Date).AddSeconds(12)
    while ((Get-Date) -lt $deadline) {
        $h = [Win32Cp]::FindTopLevelContaining('Axumera Control Panel')
        if ($h -ne [IntPtr]::Zero) { break }
        Start-Sleep -Milliseconds 500
    }
    if ($h -eq [IntPtr]::Zero) { $h = [IntPtr]$p.MainWindowHandle }
    return $h
}
function Close-Cp([System.Diagnostics.Process]$p) {
    if ($null -eq $p) { return $true }
    $null = $p.CloseMainWindow()
    $closed = $p.WaitForExit(15000)
    if (-not ($closed -or $p.HasExited)) { $p.Kill() | Out-Null; $p.WaitForExit(5000) }
    return ($closed -or $p.HasExited)
}
function Assert-NoLifecycleChrome([IntPtr]$hwnd, [string]$prefix) {
    $texts = ([Win32Cp]::ChildTexts($hwnd) -join ' | ')
    foreach ($forbidden in @('START SERVER', 'STOP SERVER', 'RESTART', 'SERVER STATUS', 'SERVER RUNNING', 'SERVER NOT RUNNING', 'DIAGNOSTICS')) {
        $found = $texts -match [regex]::Escape($forbidden)
        Assert "$prefix-no-$($forbidden -replace ' ', '-')" (-not $found) "chrome contains '$forbidden'"
    }
}

# ---- hermetic prep: no stale Control Panel instance, fresh telemetry ----
$stale = Get-CimInstance Win32_Process -Filter "Name='Axumera.ControlPanel.exe'" -ErrorAction SilentlyContinue
if ($stale) { Write-Host "MANUAL ACTIVITY: a Control Panel instance is already running (PID $($stale[0].ProcessId)). Nothing was killed." -ForegroundColor Yellow; exit 98 }
Remove-Item $cpLog -Force -ErrorAction SilentlyContinue

Write-Host "=== PHASE A: Axumera.Server.exe is the SOLE lifecycle controller (start/status/health) ==="

# Clean slate: ensure the isolated runtime is stopped (Server EXE only).
$stop0 = Headless 'stop' 60
Start-Sleep -Milliseconds 800
Assert 'hl-pre-start-port-free' ((PortFree $apachePort) -and (PortFree $mariaPort)) "apache=$apachePort maria=$mariaPort"

# Server EXE starts the runtime.
$start = Headless 'start' 180
Assert 'hl-start-started' ($start.Out -eq 'STARTED') "out=$($start.Out) err=$($start.Err)"
Assert 'hl-start-process-exited' $start.Exited "controller process did not exit"
Start-Sleep -Milliseconds 1500
Assert 'hl-ports-listening' ((-not (PortFree $apachePort)) -and (-not (PortFree $mariaPort)))
$status = Headless 'status'
Assert 'hl-status-running' ($status.Out -match 'STATE Running') "got: $($status.Out)"
Assert 'hl-status-ports' ($status.Out -match "apache=$apachePort" -and $status.Out -match "mariadb=$mariaPort") "got: $($status.Out)"
$health = Headless 'health'
Assert 'hl-health-running' ($health.Out -match '"overall": *"Running"') "got: $($health.Out)"
Assert 'hl-health-ports' ($health.Out -match ('"apachePort": *' + $apachePort) -and $health.Out -match ('"mariaDbPort": *' + $mariaPort)) "got: $($health.Out)"

Write-Host "=== PHASE B: Control Panel admin WebView loads (server started by Server EXE) ==="
$p = Launch-Cp
Assert 'cp-window-appears' ($p.MainWindowHandle -ne 0) "hwnd=$($p.MainWindowHandle) exited=$($p.HasExited)"
$mainHwnd = Resolve-MainHwnd $p
Assert 'cp-main-form-title' ($mainHwnd -ne [IntPtr]::Zero)
$init = Wait-Telemetry 'webview-init-complete' 30
Assert 'cp-webview-init' $init
$nav = Wait-Telemetry 'admin-login-navigation-requested' 30
Assert 'cp-admin-login-navigation' $nav
$sentinel = Wait-Telemetry 'AXUMERA_SENTINEL_FORM_8B2E' 10
Assert 'cp-sentinel-form' $sentinel
$loaded = Wait-Telemetry 'page-loaded' 40
Assert 'cp-page-loaded' $loaded

# Server-side proof: Apache access log must show the adminlogin.php request on the staging port.
Start-Sleep -Seconds 2
$access = Get-Content $accessLog -Raw -ErrorAction SilentlyContinue
Assert 'cp-adminlogin-in-access-log' ($access -match 'adminlogin\.php') "accessLog=$accessLog"

Write-Host "=== PHASE C: Control Panel chrome has NO server lifecycle controls ==="
$mainHwnd = Resolve-MainHwnd $p
Assert-NoLifecycleChrome $mainHwnd 'cp'
# The Control Panel log must not contain lifecycle controller activity.
$cpLogContent = Get-Content $cpLog -Raw -ErrorAction SilentlyContinue
Assert 'cp-log-no-lifecycle-activity' (-not ($cpLogContent -cmatch 'ServerController' -or $cpLogContent -cmatch 'STARTED' -or $cpLogContent -cmatch 'STOPPED')) "cp log has lifecycle markers"

# Screenshot for the visual record.
Start-Sleep -Seconds 1
Add-Type -AssemblyName System.Drawing
$b = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds
$bmp = New-Object System.Drawing.Bitmap($b.Width, $b.Height)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.CopyFromScreen(0, 0, 0, 0, $bmp.Size)
$g.Dispose()
$bmp.Save($screenshot, [System.Drawing.Imaging.ImageFormat]::Png)
$bmp.Dispose()
Assert 'cp-screenshot-saved' (Test-Path $screenshot)

Write-Host "=== PHASE D: Closing the Control Panel does NOT stop the server ==="
$closed = Close-Cp $p
Assert 'cp-closes-cleanly' $closed
Start-Sleep -Milliseconds 1500
$statusAfter = Headless 'status'
Assert 'server-still-running-after-cp-close' ($statusAfter.Out -match 'STATE Running') "got: $($statusAfter.Out)"
$healthAfter = Headless 'health'
Assert 'server-healthy-after-cp-close' ($healthAfter.Out -match '"overall": *"Running"') "got: $($healthAfter.Out)"

Write-Host "=== PHASE E: Control Panel CANNOT start a stopped server ==="
$stop1 = Headless 'stop' 120
Assert 'hl-stop-stopped' ($stop1.Out -eq 'STOPPED') "out=$($stop1.Out)"
Start-Sleep -Milliseconds 1500
Assert 'ports-free-after-stop' ((PortFree $apachePort) -and (PortFree $mariaPort))

# Fresh telemetry so this phase's markers are unambiguous.
Remove-Item $cpLog -Force -ErrorAction SilentlyContinue
$p2 = Launch-Cp
Assert 'cp2-window-appears' ($p2.MainWindowHandle -ne 0) "hwnd=$($p2.MainWindowHandle)"
$mainHwnd2 = Resolve-MainHwnd $p2
Assert 'cp2-main-form-title' ($mainHwnd2 -ne [IntPtr]::Zero)
$init2 = Wait-Telemetry 'webview-init-complete' 30
Assert 'cp2-webview-init' $init2
$nav2 = Wait-Telemetry 'admin-login-navigation-requested' 30
Assert 'cp2-navigation-attempted' $nav2

# The Control Panel must NOT have started the runtime: ports stay free.
Start-Sleep -Seconds 8
Assert 'cp2-apache-port-stays-free' (PortFree $apachePort) "apache port $apachePort is listening"
Assert 'cp2-mariadb-port-stays-free' (PortFree $mariaPort) "mariadb port $mariaPort is listening"
# Scoped to THIS runtime: the Control Panel must not have started a server on the
# staging ports. (A machine-wide process check false-positives when a separate
# production runtime is legitimately running, which is the post-deployment state.)
$stagingListeners = Get-NetTCPConnection -State Listen -LocalPort $apachePort, $mariaPort -ErrorAction SilentlyContinue
Assert 'cp2-no-staging-server-started' (-not $stagingListeners) "staging ports busy: $(($stagingListeners | ForEach-Object { $_.LocalPort }) -join ',')"
$cpLog2 = Get-Content $cpLog -Raw -ErrorAction SilentlyContinue
Assert 'cp2-log-no-lifecycle-activity' (-not ($cpLog2 -cmatch 'ServerController' -or $cpLog2 -cmatch 'STARTED' -or $cpLog2 -cmatch 'STOPPED')) "cp log has lifecycle markers"

# Professional error state: the WebView host shows its native unavailable overlay.
$errorSeen = $false
$deadline = (Get-Date).AddSeconds(20)
while ((Get-Date) -lt $deadline) {
    if ([Win32Cp]::FindChildContaining($mainHwnd2, 'could not be loaded') -ne [IntPtr]::Zero) { $errorSeen = $true; break }
    Start-Sleep -Milliseconds 500
}
Assert 'cp2-professional-error-state' $errorSeen

$closed2 = Close-Cp $p2
Assert 'cp2-closes-cleanly' $closed2
Start-Sleep -Milliseconds 1000
Assert 'cp2-ports-still-free-after-close' ((PortFree $apachePort) -and (PortFree $mariaPort))

$resultsFile = Join-Path $repo 'native\build\control-panel-staging-results.txt'
$summary = @()
$summary += ''
$summary += '=================================================='
$summary += 'CONTROL PANEL STAGING RESULTS (FINAL ARCHITECTURE)'
$summary += '=================================================='
foreach ($line in $results) { $summary += "  $line"; Write-Host "  $line" }
if ($failures -eq 0) { $summary += 'ALL CONTROL PANEL STAGING TESTS PASSED'; Write-Host 'ALL CONTROL PANEL STAGING TESTS PASSED' -ForegroundColor Green }
else { $summary += "$failures FAILURE(S)"; Write-Host "$failures FAILURE(S)" -ForegroundColor Red }
$summary | Set-Content -Path $resultsFile -Encoding UTF8
exit $failures
