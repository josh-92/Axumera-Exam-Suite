<#
    student-kiosk-e2e-staging.ps1 - Phase 6 full interactive kiosk test against the
    isolated STAGING runtime (Apache 8288 / MariaDB 3488). Drives the real
    Student client through the authoritative AXE 2.0 workflow:

      student login (roll + stream + password)
      -> waiting room -> exam portal -> integrity gate
      -> kiosk lockdown (borderless fullscreen)
      -> keyboard escape attempts (Win / Alt+Tab / Ctrl+Esc / F12) stay blocked
      -> Finish Attempt -> REVIEW PAGE DOES NOT RELEASE KIOSK
      -> Back To Exam -> Exit Exam (native) -> confirm
      -> server-side controlled_exit violation, kiosk STAYS locked
      -> Submit Final Exam -> exam-submitted -> kiosk releases -> slogin
      -> server-side attempt/score/integrity verification

    Security boundary under test (Phase 4): kiosk mode is released ONLY when
    the server finalizes the attempt (exam-submitted message / already_taken
    page), never merely because the URL changed to review.php.

    WebView2 notes (harness):
      * UIA does NOT expose WebView2 web content, and a TreeScope.Descendants
        search on the shell window wedges the UIA provider ("Operation timed
        out"). Native chrome (strip button, confirm dialog) is therefore driven
        with Win32 FindChildByText + BM_CLICK, and geometry comes from
        GetWindowRect / MainWindowHandle.
      * After the page is interacted with, the foreground window is often the
        WebView2 browser-process window (different PID), not the shell hwnd.
        Foreground checks accept the app PID or any of its msedgewebview2 PIDs.

    Prerequisites:
      * staging runtime running on 8288/3488 (Axumera.Server.exe --runtime-root rehearsal\app --headless start)
      * dev app licensed; a live exam exists; dedicated E2E student has a
        password (roll 108 / stream Natural Science / password Kiosk2026)
      * native\build\Axumera.Student\Axumera.Student.dll published

    Usage: powershell -File tools\student-kiosk-e2e.ps1 [-Roll 108]
#>
param([int]$Roll = 108)

$ErrorActionPreference = 'Continue'
$canonicalPath = $env:Path
[Environment]::SetEnvironmentVariable('PATH', $null, 'Process')
[Environment]::SetEnvironmentVariable('Path', $canonicalPath, 'Process')
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT

Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
Add-Type -AssemblyName System.Windows.Forms
Add-Type @"
using System;
using System.Runtime.InteropServices;
public static class Win32Kiosk {
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT rect);
    [DllImport("user32.dll")] public static extern int GetWindowLong(IntPtr hWnd, int nIndex);
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")] public static extern void keybd_event(byte bVk, byte bScan, uint dwFlags, UIntPtr dwExtraInfo);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern IntPtr FindWindowW(string lpClassName, string lpWindowName);
    [DllImport("user32.dll")] public static extern bool EnumChildWindows(IntPtr hWndParent, EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetWindowText(IntPtr hWnd, System.Text.StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")] public static extern IntPtr SendMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
    public const int GWL_STYLE = -16;
    public const long WS_CAPTION = 0x00C00000;
    public const long WS_THICKFRAME = 0x00040000;
    public const long WS_BORDER = 0x00800000;
    public const uint MOUSEEVENTF_LEFTDOWN = 0x0002;
    public const uint MOUSEEVENTF_LEFTUP = 0x0004;
    public const uint KEYEVENTF_KEYUP = 0x0002;
    public const uint BM_CLICK = 0x00F5;
    public static string[] TopLevelTitles(uint pid) {
        var titles = new System.Collections.Generic.List<string>();
        EnumWindows((h, l) => {
            uint wPid; GetWindowThreadProcessId(h, out wPid);
            if (wPid == pid) {
                var sb = new System.Text.StringBuilder(256);
                GetWindowText(h, sb, 256);
                if (sb.Length > 0) titles.Add(h + ":" + sb.ToString());
            }
            return true;
        }, IntPtr.Zero);
        return titles.ToArray();
    }
    // FindWindowW with a null class name cannot be called from PowerShell
    // ($null marshals to "" and never matches), so match the title by
    // enumerating top-level windows instead.
    public static IntPtr FindTopLevelByTitle(string title) {
        IntPtr found = IntPtr.Zero;
        EnumWindows((h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetWindowText(h, sb, 256);
            if (sb.ToString() == title) { found = h; return false; }
            return true;
        }, IntPtr.Zero);
        return found;
    }
    // A real mouse click at the child's screen center: the only mechanism that
    // guarantees a WinForms Button.OnClick (cursor over button => MouseIsOver
    // true), which closes a modal dialog whose button has DialogResult.OK.
    public static void RealClickChild(IntPtr parent, string text) {
        IntPtr child = FindChildByText(parent, text);
        if (child == IntPtr.Zero) return;
        RECT r; GetWindowRect(child, out r);
        ClickAt((r.Left + r.Right) / 2, (r.Top + r.Bottom) / 2);
    }
    [DllImport("user32.dll")] public static extern IntPtr WindowFromPoint(Point p);
    [StructLayout(LayoutKind.Sequential)] public struct Point { public int X, Y; }
    public static IntPtr WindowUnder(int x, int y) { Point p; p.X = x; p.Y = y; return WindowFromPoint(p); }
    public const byte VK_TAB = 0x09, VK_ESCAPE = 0x1B, VK_CONTROL = 0x11, VK_MENU = 0x12;
    public const byte VK_LWIN = 0x5B, VK_F12 = 0x7B;
    public static bool IsBorderless(IntPtr h) {
        int style = GetWindowLong(h, GWL_STYLE);
        return (style & (int)(WS_CAPTION | WS_THICKFRAME | WS_BORDER)) == 0;
    }
    public static string RectString(IntPtr h) {
        RECT r; GetWindowRect(h, out r);
        return string.Format("{0},{1} {2}x{3}", r.Left, r.Top, r.Right - r.Left, r.Bottom - r.Top);
    }
    public static uint ProcessIdOf(IntPtr h) {
        uint pid; GetWindowThreadProcessId(h, out pid); return pid;
    }
    public static IntPtr FindChildByText(IntPtr parent, string text) {
        IntPtr found = IntPtr.Zero;
        EnumChildWindows(parent, (h, l) => {
            var sb = new System.Text.StringBuilder(256);
            GetWindowText(h, sb, 256);
            if (sb.ToString() == text) { found = h; return false; }
            return true;
        }, IntPtr.Zero);
        return found;
    }
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
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern int GetClassName(IntPtr hWnd, System.Text.StringBuilder lpClassName, int nMaxCount);
    public static void ClickAt(int x, int y) {
        SetCursorPos(x, y);
        System.Threading.Thread.Sleep(120);
        mouse_event(MOUSEEVENTF_LEFTDOWN, 0, 0, 0, UIntPtr.Zero);
        System.Threading.Thread.Sleep(60);
        mouse_event(MOUSEEVENTF_LEFTUP, 0, 0, 0, UIntPtr.Zero);
    }
    public static void Tap(byte vk) {
        keybd_event(vk, 0, 0, UIntPtr.Zero);
        System.Threading.Thread.Sleep(60);
        keybd_event(vk, 0, KEYEVENTF_KEYUP, UIntPtr.Zero);
        System.Threading.Thread.Sleep(250);
    }
    public static void Chord(params byte[] keys) {
        foreach (byte k in keys) { keybd_event(k, 0, 0, UIntPtr.Zero); System.Threading.Thread.Sleep(60); }
        for (int i = keys.Length - 1; i >= 0; i--) { keybd_event(keys[i], 0, KEYEVENTF_KEYUP, UIntPtr.Zero); System.Threading.Thread.Sleep(60); }
        System.Threading.Thread.Sleep(350);
    }
    public static void AltTab() { Chord(VK_MENU, VK_TAB); }
    public static void CtrlEsc() { Chord(VK_CONTROL, VK_ESCAPE); }
    public static void WinKey() { Tap(VK_LWIN); }
    public static void F12() { Tap(VK_F12); }
}
"@

$repo      = 'C:\Axumera-Enginnering'
$StudentDll = Join-Path $repo 'native\build\Axumera.Student\Axumera.Student.dll'
$Config    = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\config\student-server.json'
$Telemetry = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\logs\Axumera.Student.log'
$WebViewProfile = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\WebView2\Student'
$Mysql = Join-Path $repo 'rehearsal\app\runtime\mariadb\bin\mysql.exe'

# Phase 8: server-side helpers for the explicit back-to-exam regression.
function Get-ViolationCount() {
    $row = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT ea.violation_count FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll ORDER BY ea.id DESC LIMIT 1;" 2>&1
    $s = ($row -as [string]).Trim()
    if ($s -match '^\d+$') { return [int]$s }
    return -1
}
function Get-ControlledExitCount() {
    $c = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT COUNT(*) FROM activity_log WHERE actor_identifier = '$Roll' AND action = 'integrity_violation' AND details LIKE '%controlled_exit%';" 2>&1
    $s = ($c -as [string]).Trim()
    if ($s -match '^\d+$') { return [int]$s }
    return -1
}
# Phase 8: server-side answers helper (the answers JSON blob of the latest attempt).
function Get-AnswersJson() {
    $a = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT ea.answers FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll ORDER BY ea.id DESC LIMIT 1;" 2>&1
    $s = ($a -as [string]).Trim()
    if ($s -match '^\{\s*\}$') { return $null }   # empty answers object
    if ($s -match '^\{.*\}$') { return $s }
    return $null
}
function Wait-AnswersJson([int]$timeoutSec) {
    $deadline = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $deadline) {
        $j = Get-AnswersJson
        if ($j) { return $j }
        Start-Sleep -Milliseconds 1000
    }
    return $null
}
function Wait-AnswersChanged([string]$old, [int]$timeoutSec) {
    $deadline = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $deadline) {
        $j = Get-AnswersJson
        if ($j -and $j -ne $old) { return $j }
        Start-Sleep -Milliseconds 1000
    }
    return $null
}
# Phase 8: deterministic interaction helpers built on UI Automation's
# FocusedElement, which is proven to expose WebView2 web content here (field
# names, control types, and ValuePattern values). UIA calls need no window
# activation, so they cannot fire window_blur on the exam page.
function Get-FocusEl() {
    try { return [System.Windows.Automation.AutomationElement]::FocusedElement } catch { return $null }
}
function Get-FocusValue() {
    $fe = Get-FocusEl
    if (-not $fe) { return $null }
    try {
        $vp = $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern)
        return $vp.Current.Value
    } catch { return $null }
}
function Wait-FocusControl([string]$namePattern, [string]$ctPattern, [int]$tries) {
    for ($i = 0; $i -lt $tries; $i++) {
        $fe = Get-FocusEl
        if ($fe) {
            $n = $fe.Current.Name
            $ct = $fe.Current.ControlType.ProgrammaticName
            if (($namePattern -eq '' -or $n -match $namePattern) -and $ct -match $ctPattern) { return $fe }
        }
        Send-Keys '{TAB}'
        Start-Sleep -Milliseconds 350
    }
    return $null
}
# Set the focused field's value and VERIFY it landed (ValuePattern).
# Preferred method is UIA ValuePattern.SetValue (deterministic - the probe
# proved WebView2 supports it); SendKeys keystrokes are only a fallback.
# Password fields are masked by UIA with a symbol char (Chromium uses U+0007
# BEL here), so a value of the SAME LENGTH with no alphanumeric characters is
# accepted as "typed" (a partial/wrong keystroke would change the length).
function Set-FieldValue([string]$value) {
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        $fe = Get-FocusEl
        $setViaPattern = $false
        if ($fe) {
            try {
                $vp = $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern)
                $vp.SetValue($value)
                $setViaPattern = $true
            } catch { }
        }
        if (-not $setViaPattern) { Send-Keys $value }
        Start-Sleep -Milliseconds 400
        $v = Get-FocusValue
        $masked = $v -and $v.Length -eq $value.Length -and ($v -replace '[A-Za-z0-9]', '') -eq $v
        if ($v -eq $value -or $masked) { return $true }
        if ($setViaPattern) {
            try {
                $vp = $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern)
                $vp.SetValue('')
            } catch { }
        } else {
            Send-Keys '^a'
            Send-Keys '{DELETE}'
        }
        Start-Sleep -Milliseconds 250
    }
    return $false
}
# Focus the exam workspace (sidebar header click resets page focus), TAB until
# a radio button is focused, then select radio #$radioIndex (0-based) through
# UIA SelectionItemPattern.Select (maps to a real click on the radio, which
# fires change -> selectAnswer without any window-activation side effects).
function Select-RadioViaTab([int]$radioIndex) {
    [Win32Kiosk]::ClickAt($screenW - 130, 120)
    Start-Sleep -Milliseconds 400
    $seen = -1
    for ($t = 0; $t -lt 14; $t++) {
        $fe = Get-FocusEl
        if ($fe -and $fe.Current.ControlType.ProgrammaticName -match 'RadioButton') {
            $seen++
            if ($seen -eq $radioIndex) {
                try {
                    $pat = $fe.GetCurrentPattern([System.Windows.Automation.SelectionItemPattern]::Pattern)
                    $pat.Select()
                    Start-Sleep -Milliseconds 800
                    return $true
                } catch { return $false }
            }
        }
        Send-Keys '{TAB}'
        Start-Sleep -Milliseconds 300
    }
    return $false
}

$results = [System.Collections.Generic.List[string]]::new()
$failures = 0
function Assert([string]$name, [bool]$condition, $detail = '') {
    $stamp = (Get-Date).ToString('HH:mm:ss')
    if ($condition) {
        Write-Host "  [$stamp] PASS $name" -ForegroundColor Green
        $results.Add("PASS $name")
    } else {
        Write-Host "  [$stamp] FAIL $name $detail" -ForegroundColor Red
        $results.Add("FAIL $name $detail")
        $script:failures++
    }
}
function TelemetryContains([string]$marker) {
    $content = Get-Content $Telemetry -Raw -ErrorAction SilentlyContinue
    return $content -and $content -match [regex]::Escape($marker)
}
function Wait-Telemetry([string]$marker, [int]$timeoutSec = 20) {
    $deadline = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $deadline) {
        if (TelemetryContains $marker) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}
function Send-Keys([string]$keys) {
    [System.Windows.Forms.SendKeys]::SendWait($keys)
    Start-Sleep -Milliseconds 250
}
function Get-MainHwnd() {
    $p.Refresh()
    return [IntPtr]$p.MainWindowHandle
}
function Get-Rect([IntPtr]$hwnd) {
    $rect = New-Object Win32Kiosk+RECT
    if ([Win32Kiosk]::GetWindowRect($hwnd, [ref]$rect)) { return $rect }
    return $null
}
$script:webviewPids = @()
function Refresh-WebViewPids() {
    $folder = [regex]::Escape((Join-Path $env:LOCALAPPDATA 'Axumera 2.0\WebView2\Student'))
    $script:webviewPids = @(Get-CimInstance Win32_Process -Filter "Name='msedgewebview2.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match $folder } |
        ForEach-Object { [uint32]$_.ProcessId })
}
function Get-ForegroundPid() {
    $fg = [Win32Kiosk]::GetForegroundWindow()
    if ($fg -eq [IntPtr]::Zero) { return 0 }
    return [Win32Kiosk]::ProcessIdOf($fg)
}
function Is-ForegroundInApp() {
    $fgPid = Get-ForegroundPid
    if ($fgPid -eq [uint32]$p.Id) { return $true }
    Refresh-WebViewPids
    return $script:webviewPids -contains $fgPid
}
function Focus-WebView([int]$yOffsetPx = 0) {
    $hwnd = Get-MainHwnd
    $rect = Get-Rect $hwnd
    if (-not $rect) { return }
    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    $cy = [int]($rect.Top + 80 + ($h - 140) / 2) + $yOffsetPx
    [Win32Kiosk]::ClickAt([int]($rect.Left + $w / 2), $cy)
    Start-Sleep -Milliseconds 500
}
function Ensure-AppForeground() {
    $null = $shell.AppActivate($p.Id)
    Start-Sleep -Milliseconds 400
    $hwnd = Get-MainHwnd
    if ($hwnd -ne [IntPtr]::Zero) { [Win32Kiosk]::SetForegroundWindow($hwnd) | Out-Null }
    Start-Sleep -Milliseconds 300
}
function Invoke-FocusedButton([string]$namePattern, [int]$tries = 16, [int]$extraTabs = 0) {
    # Walk the page's focusables with TAB (in-page only; proven not to fire
    # window_blur) until a Button whose name matches is focused, then activate
    # it through UIA InvokePattern - a real click with zero keystroke ENTER
    # and zero coordinate clicks, so no window focus ever changes.
    for ($t = 0; $t -lt $tries; $t++) {
        $fe = Get-FocusEl
        if ($fe -and $fe.Current.ControlType.ProgrammaticName -match 'Button' -and $fe.Current.Name -match $namePattern) {
            try {
                $ip = $fe.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern)
                $ip.Invoke()
                return $true
            } catch { return $false }
        }
        Send-Keys '{TAB}'
        Start-Sleep -Milliseconds 300
    }
    return $false
}
function Click-FinishAttempt() {
    # Deterministic route to the sidebar 'Finish Attempt...' button: walk the
    # page focusables with TAB until the button is focused, then invoke it via
    # UIA (a real button click, no ENTER keystroke, no coordinate click). This
    # keeps the measured violation interval free of harness-induced blurs.
    for ($attempt = 0; $attempt -lt 4; $attempt++) {
        Focus-WebView   # click into the page centre (in-window, no blur)
        if (Invoke-FocusedButton 'Finish Attempt' 18) {
            if (Wait-Telemetry 'exam-ended-message: review' 8) { return $true }
        }
    }
    return $false
}
function Assert-KioskStillLocked([string]$context) {
    $hwnd = Get-MainHwnd
    $rect = Get-Rect $hwnd
    $fill = $false
    if ($rect) {
        $fill = ($rect.Right - $rect.Left) -ge ($screenW - 8) -and ($rect.Bottom - $rect.Top) -ge ($screenH - 8)
    }
    $fgInApp = Is-ForegroundInApp
    Assert "kiosk-still-locked-$context" ($fill -and $fgInApp -and -not (TelemetryContains 'kiosk-exited')) `
        "fill=$fill fgInApp=$fgInApp hwnd=$hwnd rect=$($rect.Left),$($rect.Top) $($rect.Right-$rect.Left)x$($rect.Bottom-$rect.Top) exited=$(TelemetryContains 'kiosk-exited')"
}
# UIA SetFocus on a named button inside the small dialog subtree (safe and
# fast, unlike Descendants searches over the WebView2 tree), then SPACE to
# activate it. Returns true when the focus was set.
function Click-DialogButton([IntPtr]$dlg, [string]$text) {
    try {
        $dlgEl = [System.Windows.Automation.AutomationElement]::FromHandle($dlg)
        $btnCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, $text)
        $btnEl = $dlgEl.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $btnCond)
        if ($btnEl) { $btnEl.SetFocus() | Out-Null; Start-Sleep -Milliseconds 300; Send-Keys ' '; return $true }
    } catch {}
    return $false
}

# ============================================================= hermetic prep
# (directive 10): the E2E runs only against the isolated dev runtime and only
# with a clean, dedicated WebView2 profile + student/exam records.
$apacheUp = Get-NetTCPConnection -LocalPort 8288 -State Listen -ErrorAction SilentlyContinue
$mariaUp  = Get-NetTCPConnection -LocalPort 3488 -State Listen -ErrorAction SilentlyContinue
if (-not $apacheUp -or -not $mariaUp) {
    Write-Host 'FATAL: staging runtime not running (8288/3488). Start it with: Axumera.Server.exe --runtime-root rehearsal\app --headless start' -ForegroundColor Red
    exit 99
}

# Identify (never blindly kill) any concurrently running Student instance.
$stale = @()
$stale += Get-CimInstance Win32_Process -Filter "Name='Axumera.Student.exe'" -ErrorAction SilentlyContinue
$stale += Get-CimInstance Win32_Process -Filter "Name='dotnet.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match 'Axumera.Student' }
if ($stale.Count -gt 0) {
    Write-Host "MANUAL ACTIVITY IS INTERFERING WITH THE ISOLATED E2E TEST." -ForegroundColor Yellow
    Write-Host "A Student client instance is already running (PID $($stale[0].ProcessId)). Nothing was killed." -ForegroundColor Yellow
    exit 98
}

# Fresh isolated WebView2 profile (cookies/session) so stale auth never leaks in.
if (Test-Path $WebViewProfile) { Remove-Item $WebViewProfile -Recurse -Force }

# Dedicated E2E records only: clear this student's attempt/shuffle/log rows.
# NOTE: MariaDB multi-table DELETE requires a default database, so the DB
# name is passed positionally and tables are unqualified.
& $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password eaes_exam -e "DELETE ea FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll; DELETE FROM activity_log WHERE actor_identifier = '$Roll'; DELETE FROM login_attempts WHERE username = '$Roll' OR ip_address = '127.0.0.1';" 2>&1 | Out-Null

New-Item -ItemType Directory -Path (Split-Path $Config) -Force | Out-Null
@{ ServerAddress = '127.0.0.1'; ApachePort = 8288 } | ConvertTo-Json | Set-Content -Path $Config -Encoding UTF8
Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue

$p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($StudentDll) -PassThru
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Milliseconds 500
    $p.Refresh()
    if ($p.MainWindowHandle -ne 0) { break }
    if ($p.HasExited) { break }
}
Assert 'window-appears' ($p.MainWindowHandle -ne 0)

$nav = Wait-Telemetry 'student-login-navigation-requested' 20
Assert 'login-navigation-requested' $nav
Start-Sleep -Seconds 4

$shell = New-Object -ComObject WScript.Shell
Ensure-AppForeground

# ---- 1. AXE 2.0 student login (roll + stream + password) ----
# Deterministic: every field is focused and its VALUE is verified through UI
# Automation (ValuePattern exposes WebView2 form values), so a dropped key or
# focus mis-step cannot silently produce a rejected login. On failure the
# whole login is retried once on a fresh app instance; if both attempts fail
# the run aborts (never driving a broken session forward).
$portalLoaded = $false
for ($loginAttempt = 1; $loginAttempt -le 2 -and -not $portalLoaded; $loginAttempt++) {
    if ($loginAttempt -gt 1) {
        if (-not $p.HasExited) { $p.Kill() | Out-Null; $p.WaitForExit(5000) }
        Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue
        $p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($StudentDll) -PassThru
        $winOk = $false
        for ($i = 0; $i -lt 40; $i++) {
            $p.Refresh()
            if ($p.MainWindowHandle -ne [IntPtr]::Zero) { $winOk = $true; break }
            Start-Sleep -Milliseconds 500
        }
        if (-not $winOk -or -not (Wait-Telemetry 'page-loaded' 30)) { break }
        Ensure-AppForeground
    }

    $rect = Get-Rect (Get-MainHwnd)
    if (-not $rect) { if ($loginAttempt -eq 2) { Assert 'login-window-available' $false }; break }
    $clickY = [int]($rect.Top + 80 + ($rect.Bottom - $rect.Top - 140) / 2)
    [Win32Kiosk]::ClickAt([int]($rect.Left + ($rect.Right - $rect.Left) / 2), $clickY)
    Start-Sleep -Milliseconds 600

    function Get-FocusName() {
        $f = [System.Windows.Automation.AutomationElement]::FocusedElement
        if ($f) { return $f.Current.Name } else { return '' }
    }

    $rollFe = Wait-FocusControl 'Roll' 'Spinner' 12
    if ($loginAttempt -eq 2) { Assert 'login-roll-focused' ($null -ne $rollFe) "focused='$(Get-FocusName)'" }
    if (-not $rollFe) { continue }
    $typedRoll = Set-FieldValue $Roll.ToString()
    if ($loginAttempt -eq 2) { Assert 'login-roll-typed' $typedRoll "v='$(Get-FocusValue)'" }
    if (-not $typedRoll) { continue }

    $selFe = Wait-FocusControl 'Stream' 'ComboBox' 5
    if ($loginAttempt -eq 2) { Assert 'login-stream-focused' ($null -ne $selFe) "focused='$(Get-FocusName)'" }
    if (-not $selFe) { continue }
    if ((Get-FocusValue) -ne 'Natural Science') { Send-Keys '{DOWN}'; Start-Sleep -Milliseconds 350 }
    $streamVal = Get-FocusValue
    if ($loginAttempt -eq 2) { Assert 'login-stream-selected' ($streamVal -eq 'Natural Science') "v='$streamVal'" }
    if ($streamVal -ne 'Natural Science') { continue }

    $pwdFe = Wait-FocusControl 'Password' 'Edit' 5
    if ($loginAttempt -eq 2) { Assert 'login-password-focused' ($null -ne $pwdFe) "focused='$(Get-FocusName)'" }
    if (-not $pwdFe) { continue }
    $typedPwd = Set-FieldValue 'Kiosk2026'
    if ($loginAttempt -eq 2) { Assert 'login-password-typed' $typedPwd }
    if (-not $typedPwd) { continue }

    Send-Keys '{ENTER}'        # submit
    $portalLoaded = Wait-Telemetry 'exam-portal-loaded' 30
}
Assert 'exam-portal-loaded' $portalLoaded
if (-not $portalLoaded) {
    if (-not $p.HasExited) { $p.Kill() | Out-Null; $p.WaitForExit(5000) }
    Write-Host ''
    Write-Host 'PHASE 8 E2E ABORTED: student login did not reach the exam portal (2 attempts).' -ForegroundColor Red
    foreach ($line in $results) { Write-Host "  $line" }
    exit ([Math]::Max($failures, 1))
}

# ---- 3. Integrity gate -> kiosk ----
Ensure-AppForeground
Focus-WebView
Send-Keys '{TAB}'
Send-Keys '{ENTER}'
$kioskEntered = Wait-Telemetry 'kiosk-entered' 10
Assert 'kiosk-entered-telemetry' $kioskEntered

$screenW = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds.Width
$screenH = [System.Windows.Forms.Screen]::PrimaryScreen.Bounds.Height
$kioskHwnd = Get-MainHwnd
$kioskRect = Get-Rect $kioskHwnd
$kioskOk = $false
if ($kioskRect) {
    $kioskOk = ($kioskRect.Right - $kioskRect.Left) -ge ($screenW - 8) -and ($kioskRect.Bottom - $kioskRect.Top) -ge ($screenH - 8) `
        -and ($kioskRect.Left -le 8) -and ($kioskRect.Top -le 8)
}
Assert 'kiosk-window-fills-display' $kioskOk "rect=$($kioskRect.Left),$($kioskRect.Top) $($kioskRect.Right-$kioskRect.Left)x$($kioskRect.Bottom-$kioskRect.Top) screen=${screenW}x${screenH}"
Assert 'kiosk-window-borderless' ([Win32Kiosk]::IsBorderless($kioskHwnd)) "hwnd=$kioskHwnd"

# Phase 8: the native EXIT EXAM control must be clearly visible while the
# student is inside the protected exam kiosk.
$exitBtnKiosk = [Win32Kiosk]::FindChildByText((Get-MainHwnd), 'EXIT EXAM')
Assert 'exit-exam-visible-in-kiosk' ($exitBtnKiosk -ne [IntPtr]::Zero) "hwnd=$exitBtnKiosk"

# ---- 3c. Layout: kiosk strip + AXE 2.0 student info bar fully visible ----
Start-Sleep -Milliseconds 1000
Add-Type -AssemblyName System.Drawing
$bmp = New-Object System.Drawing.Bitmap($screenW, $screenH)
$gfx = [System.Drawing.Graphics]::FromImage($bmp)
$gfx.CopyFromScreen(0, 0, 0, 0, $bmp.Size)
$gfx.Dispose()
$bmp.Save((Join-Path $repo 'native\build\student-kiosk-layout.png'), [System.Drawing.Imaging.ImageFormat]::Png)

function Test-RedPixel([int]$x, [int]$y) {
    $px = $bmp.GetPixel($x, $y)
    return ($px.R -ge 180 -and $px.G -le 110 -and $px.B -le 110 -and ($px.R - $px.G) -ge 80)  # #d9383a family
}
function Test-WhitePixel([int]$x, [int]$y) {
    $px = $bmp.GetPixel($x, $y)
    return ($px.R -ge 245 -and $px.G -ge 245 -and $px.B -ge 245)
}
$whiteHits = 0.0; $samples = 0.0
foreach ($y in 5, 10, 15, 20, 25, 30) {
    for ($x = 0; $x -lt $screenW; $x += 8) {
        if (Test-WhitePixel $x $y) { $whiteHits += 1.0 }
        $samples += 1.0
    }
}
$stripWhite = (($whiteHits / $samples) -ge 0.7)
Assert 'kiosk-strip-visible' $stripWhite "whiteRatio=$([math]::Round($whiteHits / $samples, 2))"

$firstRedRow = -1
for ($y = 0; $y -le 64; $y++) {
    $found = $false
    for ($x = 0; $x -lt $screenW; $x += 3) {
        if (Test-RedPixel $x $y) { $found = $true; break }
    }
    if ($found) { $firstRedRow = $y; break }
}
Assert 'student-info-bar-visible' ($firstRedRow -ge 34 -and $firstRedRow -le 60) "firstRedRow=$firstRedRow"
$bmp.Dispose()

# ---- 3d. Keyboard escape attempts stay blocked (native hook) ----
Ensure-AppForeground
[Win32Kiosk]::AltTab();   Start-Sleep -Milliseconds 400
[Win32Kiosk]::WinKey();   Start-Sleep -Milliseconds 400
[Win32Kiosk]::CtrlEsc();  Start-Sleep -Milliseconds 400
[Win32Kiosk]::F12();      Start-Sleep -Milliseconds 400
Assert-KioskStillLocked 'after-escape-keys'

# =====================================================================
# Phase 8 acceptance prep: ANSWER at least one question BEFORE the
# measured interval. Selection is driven via UI Automation on the WebView2
# accessibility tree (no window-focus changes -> no blur contamination),
# with a deterministic keyboard fallback. The answer is verified
# server-side once autosave flushes.
# =====================================================================
$ans1 = $null
try { if (Select-RadioViaTab 0) { $ans1 = Wait-AnswersJson 18 } } catch {
    Write-Host "    [diag] UIA radio select failed: $($_.Exception.Message)" -ForegroundColor DarkYellow
}
if (-not $ans1) {
    # Keyboard fallback: focus the first radio (timer 1, flag 2, clear 3,
    # radio-a 4) via TAB-until-radio, then SPACE.
    [Win32Kiosk]::ClickAt($screenW - 130, 120)
    Start-Sleep -Milliseconds 400
    $rfe = Wait-FocusControl '' 'RadioButton' 8
    if ($rfe) { Send-Keys ' ' }
    $ans1 = Wait-AnswersJson 18
}
Assert 'answer-selected-and-autosaved' ($ans1 -ne $null) "answers=$ans1"

# =====================================================================
# Phase 8 explicit regression: review.php -> Back To Exam is NORMAL
# navigation. The violation count is snapshotted AFTER the answer step,
# and the measured interval below contains ONLY in-app interactions
# (in-window clicks and page keys - NO AppActivate/window-activation
# calls), so any violation delta is caused by the navigation itself,
# never by the harness.
# =====================================================================
$viol0 = Get-ViolationCount
Assert 'violation-baseline-captured' ($viol0 -ge 0) "v0=$viol0"

# ---- 4. Finish Attempt -> review page must NOT release kiosk (directive 9) ----
$finishClicked = $false
for ($i = 0; $i -lt 3 -and -not $finishClicked; $i++) {
    Click-FinishAttempt
    $finishClicked = Wait-Telemetry 'exam-ended-message: review' 6
}
Assert 'finish-attempt-clicked' $finishClicked
Start-Sleep -Seconds 2   # review.php renders (attempt still in_progress)
Assert-KioskStillLocked 'on-review-page'
$viol1 = Get-ViolationCount
Assert 'finish-attempt-no-new-violation' ($viol1 -eq $viol0) "v0=$viol0 v1=$viol1"

# ---- 5. Back To Exam -> gate -> kiosk re-asserted ----
# Pure UIA: TAB-walk to the 'Back To Exam' button and invoke it (no ENTER
# keystroke, no coordinate click) - the least disruptive interaction, so no
# harness-induced blur enters the measured window.
$backInvoked = $false
for ($i = 0; $i -lt 4 -and -not $backInvoked; $i++) {
    Focus-WebView
    $backInvoked = Invoke-FocusedButton 'Back To Exam' 12
}
Assert 'back-to-exam-invoked' $backInvoked
$portal2 = Wait-Telemetry 'exam-portal-loaded' 20
Assert 'exam-portal-reloaded' $portal2
# Re-enter through the integrity gate with the same pure-UIA route.
$gateInvoked = $false
for ($i = 0; $i -lt 4 -and -not $gateInvoked; $i++) {
    Focus-WebView
    $gateInvoked = Invoke-FocusedButton 'Enter Fullscreen' 14
}
Assert 'gate-reentry-invoked' $gateInvoked
Start-Sleep -Seconds 2
Assert-KioskStillLocked 'after-back-to-exam'

# =====================================================================
# Phase 8 ACCEPTANCE: review -> Back To Exam must be normal navigation.
#   PASS means: no violation increase, no controlled_exit, kiosk stays
#   locked, EXIT EXAM still visible. This is the explicitly named test.
# =====================================================================
$viol2 = Get-ViolationCount
Assert 'review-back-to-exam-is-normal-navigation' ($viol2 -eq $viol0) "v0=$viol0 v1=$viol1 v2=$viol2"
$ceBeforeExit = Get-ControlledExitCount
Assert 'back-to-exam-no-controlled-exit' ($ceBeforeExit -eq 0) "count=$ceBeforeExit"
$exitBtnAfter = [Win32Kiosk]::FindChildByText((Get-MainHwnd), 'EXIT EXAM')
Assert 'exit-exam-visible-after-back-to-exam' ($exitBtnAfter -ne [IntPtr]::Zero) "hwnd=$exitBtnAfter"

# ---- 5a. Student can continue answering after Back To Exam ----
# The saved answer must survive the navigation (server-side) and a second
# selection must persist, proving the exam continues normally with autosave.
$ansPersisted = Get-AnswersJson
Assert 'answer-survived-back-to-exam' ($ansPersisted -ne $null -and $ansPersisted -eq $ans1) "before=$ans1 after=$ansPersisted"
$ans2 = $null
for ($r = 0; $r -lt 3 -and -not $ans2; $r++) {
    # The student answers a DIFFERENT question after returning: navigate to the
    # next question (pure UIA Invoke on the 'Next page' button - no keystroke,
    # no coordinate click), then select its first radio with the same
    # SelectionItemPattern route proven clean for the first answer. Q2 has no
    # saved answer, so all of its radios are TAB-reachable (with a saved answer
    # only the checked radio is - radio-group semantics would block a keyboard
    # re-selection on Q1). Server-side change is verified via autosave.
    Focus-WebView
    if (Invoke-FocusedButton 'Next page' 12) {
        Start-Sleep -Milliseconds 800
        if (Select-RadioViaTab 0) {
            $ans2 = Wait-AnswersChanged $ans1 20
        }
    }
}
Assert 'second-answer-selected-after-back-to-exam' ($ans2 -ne $null) "from=$ans1 to=$ans2"

# ---- 5b. Controlled-exit CANCELLATION (cancel stays in exam, records nothing) ----
$stripBtn0 = [Win32Kiosk]::FindChildByText((Get-MainHwnd), 'EXIT EXAM')
$cancelDialogOpened = $false
if ($stripBtn0 -ne [IntPtr]::Zero) {
    $null = [Win32Kiosk]::PostMessage($stripBtn0, [Win32Kiosk]::BM_CLICK, [IntPtr]::Zero, [IntPtr]::Zero)
    $dlg0Deadline = (Get-Date).AddSeconds(10)
    while ((Get-Date) -lt $dlg0Deadline) {
        if ([Win32Kiosk]::FindTopLevelByTitle('Exit Exam') -ne [IntPtr]::Zero) { $cancelDialogOpened = $true; break }
        Start-Sleep -Milliseconds 400
    }
}
Assert 'cancel-dialog-appears' $cancelDialogOpened
if ($cancelDialogOpened) {
    $dlg0 = [Win32Kiosk]::FindTopLevelByTitle('Exit Exam')
    $null = Click-DialogButton $dlg0 'Cancel'
    # Fallback: ENTER activates AcceptButton, which is Cancel.
    if (-not (Wait-Telemetry 'controlled-exit-cancelled' 5)) {
        $null = $shell.AppActivate($p.Id)
        Start-Sleep -Milliseconds 300
        Send-Keys '{ENTER}'
    }
}
$cancelled = Wait-Telemetry 'controlled-exit-cancelled' 8
Assert 'controlled-exit-cancelled' $cancelled
# The dialog must be gone before the real exit flow reuses the title search.
$dlg0Gone = (Get-Date).AddSeconds(6)
while ((Get-Date) -lt $dlg0Gone) {
    if ([Win32Kiosk]::FindTopLevelByTitle('Exit Exam') -eq [IntPtr]::Zero) { break }
    Start-Sleep -Milliseconds 300
}
Start-Sleep -Seconds 1
Assert-KioskStillLocked 'after-exit-cancel'

# ---- 6. Exit Exam (native button) -> confirm -> controlled exit ----
# Win32 PostMessage(BM_CLICK) on the strip button. UIA Descendants search
# wedges on the WebView2 subtree, and SendMessage(BM_CLICK) blocks the harness
# for the whole duration of the modal dialog (re-entrant into the UI thread),
# so the click is POSTED and the harness polls for the dialog.
$exitClicked = $false
$stripBtn = [Win32Kiosk]::FindChildByText((Get-MainHwnd), 'EXIT EXAM')
if ($stripBtn -ne [IntPtr]::Zero) {
    $null = [Win32Kiosk]::PostMessage($stripBtn, [Win32Kiosk]::BM_CLICK, [IntPtr]::Zero, [IntPtr]::Zero)
    $exitClicked = $true
}
Assert 'exit-exam-button-clicked' $exitClicked

$dlg = [IntPtr]::Zero
$deadline = (Get-Date).AddSeconds(15)
while ((Get-Date) -lt $deadline) {
    $dlg = [Win32Kiosk]::FindTopLevelByTitle('Exit Exam')
    if ($dlg -ne [IntPtr]::Zero) { break }
    Start-Sleep -Milliseconds 400
}
if ($dlg -eq [IntPtr]::Zero) {
    Write-Host "    [diag] top-level windows of PID $($p.Id): $(([Win32Kiosk]::TopLevelTitles([uint32]$p.Id)) -join ' | ')" -ForegroundColor DarkYellow
}
Assert 'confirm-dialog-appears' ($dlg -ne [IntPtr]::Zero) "dlg=$dlg"

$okBtn = [IntPtr]::Zero
if ($dlg -ne [IntPtr]::Zero) { $okBtn = [Win32Kiosk]::FindChildByText($dlg, 'Exit Exam') }
Assert 'confirm-exit-button-found' ($okBtn -ne [IntPtr]::Zero)

$dialogClosed = $false
if ($okBtn -ne [IntPtr]::Zero) {
    # Strategy 1: UIA SetFocus on the Exit Exam button (dialog subtree only,
    # tiny and fast), then SPACE. SPACE activates the focused button directly
    # (ENTER would hit AcceptButton=cancel).
    $null = Click-DialogButton $dlg 'Exit Exam'
    Start-Sleep -Milliseconds 900
    $dialogClosed = ([Win32Kiosk]::FindTopLevelByTitle('Exit Exam') -eq [IntPtr]::Zero)
}
# Strategy 2: cycle focus with TAB + SPACE, re-verifying the dialog is still
# open between keystrokes so nothing ever leaks to the web page (which would
# otherwise click review buttons or move the exam).
if (-not $dialogClosed) {
    $null = $shell.AppActivate($p.Id)
    Start-Sleep -Milliseconds 300
    $null = [Win32Kiosk]::SetForegroundWindow($dlg)
    Start-Sleep -Milliseconds 300
    for ($k = 0; $k -lt 4 -and -not $dialogClosed; $k++) {
        Send-Keys '{TAB}'
        Send-Keys ' '
        Start-Sleep -Milliseconds 900
        $dialogClosed = ([Win32Kiosk]::FindTopLevelByTitle('Exit Exam') -eq [IntPtr]::Zero)
    }
}
Assert 'confirm-dialog-closed' $dialogClosed

$ack = Wait-Telemetry 'exit-exam-ack' 15
Assert 'exit-exam-ack' $ack
$endedExit = Wait-Telemetry 'exam-ended-message: controlled-exit' 10
Assert 'exam-ended-controlled-exit-message' $endedExit
# The page navigated to the review flow; the attempt is still in_progress, so
# kiosk mode MUST remain locked (directive 8: no free window to browse).
Start-Sleep -Seconds 2
Assert-KioskStillLocked 'after-controlled-exit'

# ---- 7. Submit Final Exam -> kiosk releases only on server finalization ----
Ensure-AppForeground
Focus-WebView
Send-Keys '{TAB}'       # -> Back To Exam
Send-Keys '{TAB}'       # -> Submit Final Exam
Send-Keys '{ENTER}'     # opens the confirmation modal
Start-Sleep -Milliseconds 800
Send-Keys '{TAB}'       # -> Cancel
Send-Keys '{TAB}'       # -> Yes, Submit
Send-Keys '{ENTER}'     # finalize server-side

$submitted = Wait-Telemetry 'exam-submitted-message' 20
Assert 'exam-submitted-message' $submitted
$kioskExited = Wait-Telemetry 'kiosk-exited' 10
Assert 'kiosk-exited-after-submit' $kioskExited
Start-Sleep -Seconds 4   # review.php redirects to slogin.php after 2.5s
$wRect = Get-Rect (Get-MainHwnd)
$stillFull = $false
if ($wRect) { $stillFull = ($wRect.Right - $wRect.Left) -ge ($screenW - 8) }
Assert 'kiosk-released-window-normal' (-not $stillFull) "stillFull=$stillFull"

# ---- 8. Server-side verification (source of truth) ----
Start-Sleep -Seconds 2
$row = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT CONCAT(ea.violation_count, '|', ea.integrity_status, '|', ea.status, '|', IFNULL(ea.score, -1), '|', IFNULL(ea.total_questions, -1)) FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll;" 2>&1
Assert 'attempt-finalized' ($row -match '\|(submitted|auto_submitted)\|') "got: $row"
$parts = $row -split '\|'
Assert 'violation-counted' ($parts.Count -ge 3 -and ([int]$parts[0]) -ge 1) "got: $row"
$controlled = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT COUNT(*) FROM activity_log WHERE actor_identifier = '$Roll' AND action = 'integrity_violation' AND details LIKE '%controlled_exit%';" 2>&1
Assert 'exactly-one-controlled-exit' ($controlled.Trim() -eq '1') "count=$controlled"
$graded = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT COUNT(*) FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll AND ea.score IS NOT NULL AND ea.total_questions IS NOT NULL;" 2>&1
Assert 'server-side-grading-recorded' ($graded.Trim() -eq '1') "count=$graded"
# AXE 2.0 autosave pipeline: the page autosaves every 15s while in-progress
# and flushes on Finish Attempt; the attempt must carry a last_saved_at
# timestamp (the answers blob stays '{}' here because the E2E student answers
# nothing, which is legitimate).
$autosaved = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT IFNULL(DATE_FORMAT(ea.last_saved_at, '%Y-%m-%d %H:%i:%s'), 'NULL') FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll;" 2>&1
$autosaveOk = ($autosaved -match '^[0-9]{4}-[0-9]{2}-[0-9]{2} .*$')
Assert 'autosave-persisted' $autosaveOk "got: $autosaved"
# Phase 8: the answered questions must be persisted in the attempt. At least
# one answer (Q1 before review, and Q2 after Back To Exam) is expected.
$answerCount = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT IFNULL(JSON_LENGTH(ea.answers),0) FROM exam_attempts ea JOIN students s ON s.id = ea.student_id WHERE s.roll_number = $Roll;" 2>&1
$answersFinal = Get-AnswersJson
$answerOk = (($answerCount -as [string]).Trim() -match '^[1-9][0-9]*$') -and ($answersFinal -match '^\s*\{\s*("[0-9]+"\s*:\s*"[abcd]"\s*,?\s*)+\}\s*$')
Assert 'answer-recorded' $answerOk "jsonLength=$answerCount answers=$answersFinal"

# ---- 9. Clean close ----
# Post-exam the shell closes normally (no kiosk close-protection). If it does
# not, this is the harness's own instance: kill it so no stale Student blocks
# the next hermetic run, but still report the failure honestly.
if ($p.MainWindowHandle -ne 0) {
    $null = $p.CloseMainWindow()
    $closed = $p.WaitForExit(15000)
    if (-not ($closed -or $p.HasExited)) { $p.Kill() | Out-Null; $p.WaitForExit(5000) }
    Assert 'closes-cleanly' ($closed -or $p.HasExited)
} else { $p.Kill(); Assert 'closes-cleanly' $false }

Write-Host ''
Write-Host '=================================================='
Write-Host 'PHASE 4 KIOSK E2E RESULTS (AXE 2.0 fixture)'
Write-Host '=================================================='
foreach ($line in $results) { Write-Host "  $line" }
if ($failures -eq 0) { Write-Host 'ALL KIOSK E2E TESTS PASSED' -ForegroundColor Green }
else { Write-Host "$failures FAILURE(S)" -ForegroundColor Red }
exit $failures
