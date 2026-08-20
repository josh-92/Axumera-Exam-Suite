$ErrorActionPreference = 'Continue'
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes

$repo = 'C:\Axumera-Enginnering'
$StudentDll = Join-Path $repo 'native\build\Axumera.Student\Axumera.Student.dll'
$Telemetry = Join-Path $env:LOCALAPPDATA 'Axumera 2.0\logs\Axumera.Student.log'
Remove-Item $Telemetry -Force -ErrorAction SilentlyContinue

$p = Start-Process -FilePath (Join-Path $env:DOTNET_ROOT 'dotnet.exe') -ArgumentList @($StudentDll) -PassThru
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Milliseconds 500
    $p.Refresh()
    if ($p.MainWindowHandle -ne 0) { break }
    if ($p.HasExited) { break }
}
Start-Sleep -Seconds 8

$root = [System.Windows.Automation.AutomationElement]::RootElement
$cond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::ProcessIdProperty, $p.Id)
$win = $root.FindFirst([System.Windows.Automation.TreeScope]::Children, $cond)
if (-not $win) { Write-Host 'NO WINDOW FOUND'; exit 1 }

$all = $win.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
Write-Host "TOTAL ELEMENTS UNDER WINDOW: $($all.Count)"
$interesting = @()
for ($i = 0; $i -lt $all.Count; $i++) {
    $name = $all.Item($i).Current.Name
    if ($name) { $interesting += $name }
}
$interesting | Sort-Object -Unique | Select-Object -First 80 | ForEach-Object { Write-Host "  NAME: $_" }

if ($p.MainWindowHandle -ne 0) { $null = $p.CloseMainWindow(); $p.WaitForExit(10000) | Out-Null }
Write-Host 'PROBE DONE'
