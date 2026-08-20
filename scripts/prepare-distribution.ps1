[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

& (Join-Path $PSScriptRoot 'build-runtime.ps1') -Force | Out-Null
& (Join-Path $PSScriptRoot 'build-controller.ps1') | Out-Null
& (Join-Path $PSScriptRoot 'build-launchers.ps1') | Out-Null
& (Join-Path $PSScriptRoot 'build-updater.ps1') | Out-Null

$source = Join-Path $root 'build\runtime'
$staging = Join-Path $root 'distribution\staging\Axumera'
if (Test-Path $staging) { Remove-Item -Recurse -Force -LiteralPath $staging }
Copy-Item -Recurse -Force $source $staging | Out-Null
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Admin.exe') $staging | Out-Null
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Update.exe') $staging | Out-Null

$studentStaging = Join-Path $root 'distribution\staging\Axumera_Student'
if (Test-Path $studentStaging) { Remove-Item -Recurse -Force -LiteralPath $studentStaging }
New-Item -ItemType Directory -Force $studentStaging | Out-Null
Copy-Item -Force (Join-Path $root 'build\launchers\Axumera_Student.exe') $studentStaging | Out-Null

Remove-Item -Recurse -Force -ErrorAction SilentlyContinue "$staging\data\mariadb", "$staging\logs"
Remove-Item -Force -ErrorAction SilentlyContinue "$staging\application\eaes_exam_system\.env", "$staging\application\eaes_exam_system\storage\license.lic", "$staging\application\eaes_exam_system\storage\installed.lock"
New-Item -ItemType Directory -Force "$staging\data\mariadb", "$staging\logs", "$staging\application\eaes_exam_system\storage\logs", "$staging\application\eaes_exam_system\storage\cache", "$staging\application\eaes_exam_system\storage\sessions" | Out-Null

$auditScript = Join-Path $PSScriptRoot 'audit-package-security.ps1'
& $auditScript

Write-Host "Clean installer staging prepared at $staging"
