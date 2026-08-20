$ErrorActionPreference = 'Continue'
$env:DOTNET_ROOT = "$env:LOCALAPPDATA\Microsoft\dotnet"
$env:DOTNET_ROOT_X64 = $env:DOTNET_ROOT
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes

$repo = 'C:\Axumera-Enginnering'
$StudentDll = Join-Path $repo 'native\build\Axumera.Student\Axumera.Student.dll'
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

$webCond = New-Object System.Windows.Automation.PropertyCondition([System.Windows.Automation.AutomationElement]::NameProperty, '*Web content*')
$web = $win.FindFirst([System.Windows.Automation.TreeScope]::Descendants, $webCond)
Write-Host "web node: $($web.Current.ControlType.ProgrammaticName) name='$($web.Current.Name)'"
if ($web) {
    $kids = $web.FindAll([System.Windows.Automation.TreeScope]::Descendants, [System.Windows.Automation.Condition]::TrueCondition)
    Write-Host "descendants under web node: $($kids.Count)"
    for ($i = 0; $i -lt [Math]::Min($kids.Count, 60); $i++) {
        $n = $kids.Item($i).Current.Name
        if ($n) { Write-Host "  NAME: $n  type=$($kids.Item($i).Current.ControlType.ProgrammaticName)" }
    }
}
if ($p.MainWindowHandle -ne 0) { $null = $p.CloseMainWindow(); $p.WaitForExit(10000) | Out-Null }
Write-Host 'PROBE DONE'
