[CmdletBinding()]
param([switch]$KeepRunning)
$ErrorActionPreference = 'Stop'
& (Join-Path $PSScriptRoot 'start-axumera.ps1')
try {
    & (Join-Path $PSScriptRoot 'verify-runtime.ps1')
    Write-Host 'PASS: runtime smoke test. Authentication and examination workflows require a licensed, purpose-made test dataset and are not automated here.'
} finally { if (!$KeepRunning) { & (Join-Path $PSScriptRoot 'stop-axumera.ps1') } }
