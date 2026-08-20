[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$csc = 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (!(Test-Path -LiteralPath $csc)) { throw 'Microsoft .NET Framework C# compiler was not found.' }

$outputDir = Join-Path $root 'build\launchers'
New-Item -ItemType Directory -Force -Path $outputDir | Out-Null

$output = Join-Path $outputDir 'Axumera_Update.exe'
$source = Join-Path $root 'controller\AxumeraUpdate.cs'

& $csc /nologo /target:exe /out:$output $source
if ($LASTEXITCODE -ne 0) { throw 'AxumeraUpdate compilation failed.' }

# Also copy to build/runtime if runtime directory exists
$runtimeDir = Join-Path $root 'build\runtime'
if (Test-Path -LiteralPath $runtimeDir) {
    Copy-Item -Force $output (Join-Path $runtimeDir 'Axumera_Update.exe')
}

Write-Host "Built $output"
