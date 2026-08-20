[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$csc = 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (!(Test-Path -LiteralPath $csc)) { throw 'Microsoft .NET Framework C# compiler was not found.' }
$output = Join-Path $root 'build\runtime\AxumeraServer.exe'
if (!(Test-Path (Split-Path -Parent $output))) { throw 'Build the private runtime before building its controller.' }
& $csc /nologo /target:exe /out:$output (Join-Path $root 'controller\AxumeraServer.cs')
if ($LASTEXITCODE -ne 0) { throw 'AxumeraServer compilation failed.' }
Write-Host "Built $output"
