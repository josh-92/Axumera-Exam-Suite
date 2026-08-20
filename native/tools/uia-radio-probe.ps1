<#
    uia-radio-probe.ps1 - launch the Student client against the rehearsal
    runtime, log in, enter the exam, answer one question, finish, back to exam,
    re-enter through the gate, then dump UIA focus state while TAB-walking
    toward the radios. Answers the Phase 8 question: why does the second radio
    selection miss after reload? Runtime must be on 8288/3488. App closed after.
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
public static class RadioProbeWin {
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT rect);
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
    [DllImport("user32.dll")] public static extern bool SetCursorPos(int x, int y);
    [DllImport("user32.dll")] public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    public const uint MOUSEEVENTF_LEFTDOWN = 0x0002;
    public const uint MOUSEEVENTF_LEFTUP = 0x0004;
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    public static void ClickAt(int x, int y) {
        SetCursorPos(x, y);
        System.Threading.Thread.Sleep(120);
        mouse_event(MOUSEEVENTF_LEFTDOWN, 0, 0, 0, UIntPtr.Zero);
        System.Threading.Thread.Sleep(60);
        mouse_event(MOUSEEVENTF_LEFTUP, 0, 0, 0, UIntPtr.Zero);
    }
    public static uint ProcessIdOf(IntPtr h) { uint pid; GetWindowThreadProcessId(h, out pid); return pid; }
}
"@

if (-not (Get-NetTCPConnection -LocalPort 8288 -State Listen -ErrorAction SilentlyContinue)) {
    Write-Host 'FATAL: rehearsal runtime not listening on 8288' -ForegroundColor Red
    exit 99
}
$Mysql = Join-Path $repo 'rehearsal\app\runtime\mariadb\bin\mysql.exe'
& $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password eaes_exam -e "DELETE ea FROM exam_attempts ea JOIN students s ON s.id=ea.student_id WHERE s.roll_number=108; DELETE FROM activity_log WHERE actor_identifier='108'; DELETE FROM login_attempts WHERE username='108' OR ip_address='127.0.0.1';" 2>&1 | Out-Null

if (Test-Path $WebViewProfile) { Remove-Item $WebViewProfile -Recurse -Force }
Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Path (Split-Path $Config) -Force | Out-Null
@{ ServerAddress = '127.0.0.1'; ApachePort = 8288 } | ConvertTo-Json | Set-Content -Path $Config -Encoding UTF8
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT
$p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($StudentDll) -PassThru
$shell = New-Object -ComObject WScript.Shell

function TelemetryContains([string]$marker) {
    $c = Get-Content $Telemetry -Raw -ErrorAction SilentlyContinue
    return $c -and $c -match [regex]::Escape($marker)
}
function Wait-Telemetry([string]$marker, [int]$timeoutSec) {
    $dl = (Get-Date).AddSeconds($timeoutSec)
    while ((Get-Date) -lt $dl) { if (TelemetryContains $marker) { return $true }; Start-Sleep -Milliseconds 500 }
    return $false
}
function Send-Keys([string]$keys) { [System.Windows.Forms.SendKeys]::SendWait($keys); Start-Sleep -Milliseconds 250 }
function Get-FocusEl() {
    try { return [System.Windows.Automation.AutomationElement]::FocusedElement } catch { return $null }
}
function Get-FocusValue() {
    $fe = Get-FocusEl
    if (-not $fe) { return $null }
    try { return $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).Current.Value } catch { return '<nv>' }
}
function Dump-Focus([string]$tag) {
    $fe = Get-FocusEl
    if (-not $fe) { Write-Host "$tag => NO FOCUS"; return }
    $name = $fe.Current.Name; $ct = $fe.Current.ControlType.ProgrammaticName
    $val = Get-FocusValue
    $fg = [RadioProbeWin]::GetForegroundWindow()
    $fgPid = [RadioProbeWin]::ProcessIdOf($fg)
    Write-Host "$tag => name='$name' ct=$ct val='$val' fgPid=$fgPid (app=$($p.Id))"
}

$winOk = $false
for ($i = 0; $i -lt 40; $i++) { $p.Refresh(); if ($p.MainWindowHandle -ne [IntPtr]::Zero) { $winOk = $true; break }; Start-Sleep -Milliseconds 500 }
Write-Host "window: $winOk page-loaded: $(Wait-Telemetry 'page-loaded' 30)"
$null = $shell.AppActivate($p.Id); Start-Sleep -Milliseconds 500

# ---- login (UIA ValuePattern, same as E2E) ----
$rect = New-Object RadioProbeWin+RECT
[void][RadioProbeWin]::GetWindowRect([IntPtr]$p.MainWindowHandle, [ref]$rect)
$cx = [int](($rect.Left + $rect.Right) / 2)
$cy = [int]($rect.Top + 80 + ($rect.Bottom - $rect.Top - 140) / 2)
[RadioProbeWin]::ClickAt($cx, $cy); Start-Sleep -Milliseconds 700
function Wait-FocusControl([string]$namePattern, [string]$ctPattern, [int]$tries) {
    for ($i = 0; $i -lt $tries; $i++) {
        $fe = Get-FocusEl
        if ($fe) {
            $n = $fe.Current.Name; $ct = $fe.Current.ControlType.ProgrammaticName
            if (($namePattern -eq '' -or $n -match $namePattern) -and $ct -match $ctPattern) { return $fe }
        }
        Send-Keys '{TAB}'; Start-Sleep -Milliseconds 350
    }
    return $null
}
function Set-FieldValue([string]$value) {
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        $fe = Get-FocusEl; $setViaPattern = $false
        if ($fe) {
            try { $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).SetValue($value); $setViaPattern = $true } catch {}
        }
        if (-not $setViaPattern) { Send-Keys $value }
        Start-Sleep -Milliseconds 400
        $v = Get-FocusValue
        $masked = $v -and $v.Length -eq $value.Length -and ($v -replace '[A-Za-z0-9]', '') -eq $v
        if ($v -eq $value -or $masked) { return $true }
        if ($setViaPattern) { try { $fe.GetCurrentPattern([System.Windows.Automation.ValuePattern]::Pattern).SetValue('') } catch {} } else { Send-Keys '^a'; Send-Keys '{DELETE}' }
        Start-Sleep -Milliseconds 250
    }
    return $false
}
$rollFe = Wait-FocusControl 'Roll' 'Spinner' 12
Write-Host "roll focused: $($null -ne $rollFe)"
$null = Set-FieldValue '108'
$selFe = Wait-FocusControl 'Stream' 'ComboBox' 5
if ((Get-FocusValue) -ne 'Natural Science') { Send-Keys '{DOWN}'; Start-Sleep -Milliseconds 350 }
$pwdFe = Wait-FocusControl 'Password' 'Edit' 5
$null = Set-FieldValue 'Kiosk2026'
Send-Keys '{ENTER}'
Write-Host "portal: $(Wait-Telemetry 'exam-portal-loaded' 30)"

# ---- enter exam, answer question 1 ----
$null = $shell.AppActivate($p.Id); Start-Sleep -Milliseconds 500
Send-Keys '{TAB}'; Send-Keys '{ENTER}'
Write-Host "kiosk: $(Wait-Telemetry 'kiosk-entered' 20)"
Start-Sleep -Seconds 2
# answer first radio via UIA Select (proven clean)
$rfe = Wait-FocusControl '' 'RadioButton' 12
if ($rfe) {
    try {
        $rfe.GetCurrentPattern([System.Windows.Automation.SelectionItemPattern]::Pattern).Select()
        Write-Host "first radio selected: TRUE"
    } catch { Write-Host "first radio select FAILED: $($_.Exception.Message)" }
}
Start-Sleep -Seconds 18   # autosave tick

# ---- finish attempt (UIA invoke) ----
$null = $shell.AppActivate($p.Id); Start-Sleep -Milliseconds 500
[RadioProbeWin]::ClickAt($cx, $cy); Start-Sleep -Milliseconds 500
$done = $false
for ($t = 0; $t -lt 20; $t++) {
    $fe = Get-FocusEl
    if ($fe -and $fe.Current.ControlType.ProgrammaticName -match 'Button' -and $fe.Current.Name -match 'Finish Attempt') {
        try { $fe.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke(); $done = $true; break } catch {}
    }
    Send-Keys '{TAB}'; Start-Sleep -Milliseconds 300
}
Write-Host "finish invoked: $done  review: $(Wait-Telemetry 'exam-ended-message: review' 10)"
Start-Sleep -Seconds 2
Dump-Focus 'on-review'

# ---- back to exam (UIA invoke) ----
$null = $shell.AppActivate($p.Id); Start-Sleep -Milliseconds 500
[RadioProbeWin]::ClickAt($cx, $cy); Start-Sleep -Milliseconds 500
$backDone = $false
for ($t = 0; $t -lt 14; $t++) {
    $fe = Get-FocusEl
    if ($fe -and $fe.Current.ControlType.ProgrammaticName -match 'Button' -and $fe.Current.Name -match 'Back To Exam') {
        try { $fe.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke(); $backDone = $true; break } catch {}
    }
    Send-Keys '{TAB}'; Start-Sleep -Milliseconds 300
}
Write-Host "back invoked: $backDone  portal: $(Wait-Telemetry 'exam-portal-loaded' 20)"
# gate re-entry
$null = $shell.AppActivate($p.Id); Start-Sleep -Milliseconds 500
[RadioProbeWin]::ClickAt($cx, $cy); Start-Sleep -Milliseconds 500
$gateDone = $false
for ($t = 0; $t -lt 16; $t++) {
    $fe = Get-FocusEl
    if ($fe -and $fe.Current.ControlType.ProgrammaticName -match 'Button' -and $fe.Current.Name -match 'Enter Fullscreen') {
        try { $fe.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern).Invoke(); $gateDone = $true; break } catch {}
    }
    Send-Keys '{TAB}'; Start-Sleep -Milliseconds 300
}
Write-Host "gate re-entry invoked: $gateDone"
Start-Sleep -Seconds 3
Dump-Focus 'after-reentry'
Write-Host '--- TAB walk after re-entry ---'
for ($i = 0; $i -lt 14; $i++) {
    Send-Keys '{TAB}'; Start-Sleep -Milliseconds 350
    Dump-Focus "reentry-tab$i"
}

# ---- Second answer via radio-group arrow navigation ----
# After re-entry the saved answer makes radio A the ONLY radio in the TAB
# order (standard radio-group semantics: unchecked group members are reached
# with arrow keys, not TAB). So: TAB to radio A, then {DOWN} selects B.
$radioFocus = $null
for ($t = 0; $t -lt 12; $t++) {
    $fe = Get-FocusEl
    if ($fe -and $fe.Current.ControlType.ProgrammaticName -match 'RadioButton') { $radioFocus = $fe; break }
    Send-Keys '{TAB}'; Start-Sleep -Milliseconds 300
}
if ($radioFocus) {
    Write-Host "radio A focused: '$($radioFocus.Current.Name)' - pressing DOWN to select B"
    Send-Keys '{DOWN}'
    Start-Sleep -Milliseconds 600
    $fe2 = Get-FocusEl
    if ($fe2) { Write-Host "focus after DOWN: name='$($fe2.Current.Name)' ct=$($fe2.Current.ControlType.ProgrammaticName)" }
    Start-Sleep -Seconds 18   # autosave tick
    $a = & $Mysql --protocol=tcp -h 127.0.0.1 -P 3488 -u root --skip-password -N eaes_exam -e "SELECT ea.answers FROM exam_attempts ea JOIN students s ON s.id=ea.student_id WHERE s.roll_number=108 ORDER BY ea.id DESC LIMIT 1;" 2>&1
    Write-Host "answers after DOWN: $a"
} else {
    Write-Host 'NO radio found' -ForegroundColor Yellow
}

$p.CloseMainWindow() | Out-Null
if (-not $p.WaitForExit(8000)) { $p.Kill() | Out-Null }
