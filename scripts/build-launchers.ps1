[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$csc = 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (!(Test-Path -LiteralPath $csc)) { throw 'Microsoft .NET Framework C# compiler was not found.' }
$output = Join-Path $root 'build\launchers'
New-Item -ItemType Directory -Force -Path $output | Out-Null
foreach ($launcher in @(
    [pscustomobject]@{ Source = 'AxumeraAdmin.cs'; Output = 'Axumera_Admin.exe' },
    [pscustomobject]@{ Source = 'AxumeraStudent.cs'; Output = 'Axumera_Student.exe' }
)) {
    $launcherOutput = Join-Path $output $launcher.Output
    $launcherSource = Join-Path $root "launchers\$($launcher.Source)"
    & $csc /nologo /target:winexe /r:System.Windows.Forms.dll /out:$launcherOutput $launcherSource
    if ($LASTEXITCODE -ne 0) { throw "Launcher compilation failed: $($launcher.Source)" }
}
Write-Host "Built launchers at $output"
