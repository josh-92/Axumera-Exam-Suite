<#
    uia-invoke-probe.ps1 - launch the Student client against the rehearsal
    runtime and check whether the LOG IN button on the login page exposes the
    UIA InvokePattern via FocusedElement (the mechanism the E2E will use to
    click in-page buttons without keystrokes or coordinate clicks). Closes app.
#>
$ErrorActionPreference = 'Continue'
$repo = 'C:\Axumera-Enginnering'
$StudentDll = Join-Path $repo 'native\build\Axumera.Student\Axumera.Student.dll'
$Config = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\config\student-server.json'
$Telemetry = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\logs\Axumera.Student.log'
$WebViewProfile = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\WebView2\Student'

Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type -AssemblyName System.Windows.Forms
Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class UiaInvokeWin2 {
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT rect);
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint MOUSEEVENTF_LEFTDOWN = 0x0002;
    public const uint MOUSEEVENTF_LEFTUP = 0x0004;
    public static void ClickAt(int x, int y) {
        SetCursorPos(x, y);
        System.Threading.Thread.Sleep(120);
        mouse_event(MOUSEEVENTF_LEFTDOWN, 0, 0, 0, UIntPtr.Zero);
        System.Threading.Thread.Sleep(60);
        mouse_event(MOUSEEVENTF_LEFTUP, 0, 0, 0, UIntPtr.Zero);
    }
}
"@

if (-not (Get-NetTCPConnection -LocalPort 8288 -State Listen -ErrorAction SilentlyContinue)) {
    Write-Host 'FATAL: rehearsal runtime not listening on 8288' -ForegroundColor Red
    exit 99
}
if (Test-Path $WebViewProfile) { Remove-Item $WebViewProfile -Recurse -Force }
Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path (Split-Path $Config) -Force | Out-Null
@{ ServerAddress = '127.0.0.1'; ApachePort = 8288 } | ConvertTo-Json | Set-Content -Path $Config -Encoding UTF8
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT
$p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($StudentDll) -PassThru

function TelemetryContains([string]$marker) {
    $c = Get-Content $Telemetry -Raw -ErrorAction SilentlyContinue
    return $c -and $c -match [regex]::Escape($marker)
}
function Wait-Telemetry([string]$marker, [int]$timeoutSec) {
    $dl = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $dl) { if (TelemetryContains $marker) { return $true }; Start-Sleep -Milliseconds 500 }
    return $false
}
$ok = $false
for ($i = 0; $i -lt 40; $i++) {
    $p.Refresh()
    if ($p.MainWindowHandle -ne [IntPtr]::Zero) { $ok = $true; break }
    Start-Sleep -Milliseconds 500
}
Write-Host "window: $ok page-loaded: $(Wait-Telemetry 'page-loaded' 30)"

$main = [IntPtr]$p.MainWindowHandle
$rect = New-Object UiaInvokeWin2+RECT
[void][UiaInvokeWin2]::GetWindowRect($main, [ref]$rect)
$cx = [int](($rect.Left + $rect.Right) / 2)
$cy = [int]($rect.Top + 80 + ($rect.Bottom - $rect.Top - 140) / 2)
[UiaInvokeWin2]::ClickAt($cx, $cy)
Start-Sleep -Milliseconds 700

function Dump-Patterns([string]$tag) {
    try {
        $fe = [System.Windows.Automation.AutomationElement]::FocusedElement
        if (-not $fe) { Write-Host "$tag => <no focus>"; return }
        Write-Host "$tag => name='$($fe.Current.Name)' ct=$($fe.Current.ControlType.ProgrammaticName)"
        $pats = @()
        try { foreach ($pid in $fe.GetSupportedPatterns()) { $pats += $pid.ProgrammaticName } } catch {}
        Write-Host "    supported patterns: $($pats -join ', ')"
        foreach ($name in @('InvokePattern','SelectionItemPattern')) {
            try {
                $found = $fe.TryGetCurrentPattern(([System.Windows.Automation.InvokePattern]::Pattern), [ref]$null)
            } catch {}
        }
    } catch { Write-Host "$tag => UIA error: $($_.Exception.Message)" }
}
# Walk to the LOG IN button and test InvokePattern directly.
$invokeOk = $false
for ($i = 0; $i -lt 8; $i++) {
    [System.Windows.Forms.SendKeys]::SendWait('{TAB}')
    Start-Sleep -Milliseconds 400
    $fe = [System.Windows.Automation.AutomationElement]::FocusedElement
    if ($fe -and $fe.Current.Name -match 'LOG IN') {
        try {
            $ip = $fe.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern)
            Write-Host "LOG IN supports InvokePattern - invoking now..."
            $ip.Invoke()
            $invokeOk = $true
            break
        } catch {
            Write-Host "InvokePattern failed: $($_.Exception.Message)"
        }
    }
}
Write-Host "invoke-ok: $invokeOk"
Start-Sleep -Seconds 3
Write-Host "portal-loaded-after-invoke: $(TelemetryContains 'exam-portal-loaded')"

$p.CloseMainWindow() | Out-Null
if (-not $p.WaitForExit(8000)) { $p.Kill() | Out-Null }
