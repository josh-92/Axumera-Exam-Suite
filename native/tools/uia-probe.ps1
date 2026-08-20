<#
    uia-probe.ps1 - launch the Student client against the rehearsal runtime and
    dump the UI Automation properties (Name / ControlType / ValuePattern) of the
    login page's focusable fields, plus whether FindAll(RadioButton) works on the
    WebView2 child window. This answers two design questions for Phase 8:
      1. Does UIA expose WebView2 web content here at all (names/control types)?
      2. What are the exact UIA properties of the slogin fields so the E2E login
         can verify focus/values deterministically?
    The app is closed afterwards. Runtime must already be running on 8288/3488.
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
public static class UiaProbeWin {
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT rect);
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
    [DllImport("user32.dll")] public static extern bool EnumChildWindows(IntPtr hWndParent, EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetClassName(IntPtr hWnd, System.Text.StringBuilder lpClassName, int nMaxCount);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint MOUSEEVENTF_LEFTDOWN = 0x0002;
    public const uint MOUSEEVENTF_LEFTUP = 0x0004;
    public static IntPtr FindChildByClass(IntPtr parent, string className) {
        IntPtr found = IntPtr.Zero;
        EnumChildWindows(parent, (h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetClassName(h, sb, 256);
            if (sb.ToString() == className) { found = h; return false; }
            return true;
        }, IntPtr.Zero);
        return found;
    }
    public static string[] ChildClasses(IntPtr parent) {
        var names = new System.Collections.Generic.List<string>();
        EnumChildWindows(parent, (h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetClassName(h, sb, 256);
            if (sb.Length > 0) names.Add(h + ":" + sb.ToString());
            return true;
        }, IntPtr.Zero);
        return names.ToArray();
    }
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
$winOk = $false
for ($i = 0; $i -lt 40; $i++) {
    $p.Refresh()
    if ($p.MainWindowHandle -ne [IntPtr]::Zero) { $winOk = $true; break }
    Start-Sleep -Milliseconds 500
}
Write-Host "window-appears: $winOk"
Write-Host "page-loaded: $(Wait-Telemetry 'page-loaded' 30)"

$main = [IntPtr]$p.MainWindowHandle
$wv = [UiaProbeWin]::FindChildByClass($main, 'Chrome_WidgetWin_1')
if ($wv -eq [IntPtr]::Zero) { $wv = [UiaProbeWin]::FindChildByClass($main, 'Chrome_RenderWidgetHostHWND') }
Write-Host "webview hwnd: $wv"
Write-Host "main children: $(([UiaProbeWin]::ChildClasses($main)) -join ' | ')"

# ---- 1. Can we FindAll(RadioButton) on the webview subtree? (expect 0 on login) ----
try {
    $root = [System.Windows.Automation.AutomationElement]::FromHandle($wv)
    $rc = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ControlTypeProperty, [System.Windows.Automation.ControlType]::RadioButton)
    $radios = $root.FindAll([System.Windows.Automation.TreeScope]::Descendants, $rc)
    Write-Host "UIA FindAll(RadioButton) on webview: OK count=$($radios.Count)"
} catch {
    Write-Host "UIA FindAll(RadioButton) FAILED: $($_.Exception.Message)"
}

# ---- 2. Dump focusables: click into page, then TAB and dump each field ----
$rect = New-Object UiaProbeWin+RECT
[void][UiaProbeWin]::GetWindowRect($main, [ref]$rect)
$cx = [int](($rect.Left + $rect.Right) / 2)
$cy = [int]($rect.Top + 80 + ($rect.Bottom - $rect.Top - 140) / 2)
[UiaProbeWin]::ClickAt($cx, $cy)
Start-Sleep -Milliseconds 700

function Dump-Focus([string]$tag) {
    try {
        $fe = [System.Windows.Automation.AutomationElement]::FocusedElement
        $name = if ($fe) { $fe.Current.Name } else { '<none>' }
        $ct = if ($fe) { $fe.Current.ControlType.ProgrammaticName } else { '' }
        $val = ''
        if ($fe) {
            try {
                $vp = $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern)
                $val = $vp.Current.Value
            } catch { $val = '<no-value>' }
        }
        Write-Host "$tag => name='$name' ct=$ct val='$val'"
    } catch {
        Write-Host "$tag => UIA error: $($_.Exception.Message)"
    }
}
for ($i = 0; $i -lt 10; $i++) {
    Dump-Focus "field$i"
    [System.Windows.Forms.SendKeys]::SendWait('{TAB}')
    Start-Sleep -Milliseconds 500
}

# ---- 3. Type into the fields the way the E2E does, dumping the value seen ---
function FV() {
    try {
        $fe = [System.Windows.Automation.AutomationElement]::FocusedElement
        if (-not $fe) { return '<no-focus>' }
        $vp = $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern)
        $isPwd = $vp.Current.IsReadOnly
        return "'$($vp.Current.Value)' isPwd=$isPwd"
    } catch { return "<no-value: $($_.Exception.Message)>" }
}
# reset to Roll field
[UiaProbeWin]::ClickAt($cx, $cy)
Start-Sleep -Milliseconds 500
for ($i = 0; $i -lt 6; $i++) { [System.Windows.Forms.SendKeys]::SendWait('{TAB}'); Start-Sleep -Milliseconds 350 }
Write-Host "after 6 TABs focus => $(([System.Windows.Automation.AutomationElement]::FocusedElement).Current.Name)"
Write-Host "roll typing..."
[System.Windows.Forms.SendKeys]::SendWait('108'); Start-Sleep -Milliseconds 400
Write-Host "roll value => $(FV)"
[System.Windows.Forms.SendKeys]::SendWait('{TAB}'); Start-Sleep -Milliseconds 400
Write-Host "stream focus => $(([System.Windows.Automation.AutomationElement]::FocusedElement).Current.Name)"
[System.Windows.Forms.SendKeys]::SendWait('{DOWN}'); Start-Sleep -Milliseconds 400
Write-Host "stream value => $(FV)"
[System.Windows.Forms.SendKeys]::SendWait('{TAB}'); Start-Sleep -Milliseconds 400
Write-Host "password focus => $(([System.Windows.Automation.AutomationElement]::FocusedElement).Current.Name)"
[System.Windows.Forms.SendKeys]::SendWait('Kiosk2026'); Start-Sleep -Milliseconds 500
Write-Host "password value => $(FV)"
Write-Host 'submitting with ENTER...'
[System.Windows.Forms.SendKeys]::SendWait('{ENTER}')
Start-Sleep -Seconds 4
Write-Host "focus after submit => $(([System.Windows.Automation.AutomationElement]::FocusedElement).Current.Name)"
Write-Host '--- access log (last 4 entries) ---'
Get-Content 'C:\Axumera-Enginnering\rehearsal\app\logs\access.log' -Tail 4

$p.CloseMainWindow() | Out-Null
if (-not $p.WaitForExit(8000)) { $p.Kill() | Out-Null }
Write-Host 'probe done'
