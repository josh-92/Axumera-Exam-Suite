[CmdletBinding()]
param([switch]$NoWait)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Axumera.Runtime.psm1') -Force
$c = Get-AxumeraConfig; Write-AxumeraRuntimeConfig
$owned = Get-Process mysqld,httpd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$($c.Runtime)*" }
if ($owned) { throw 'An Axumera runtime process is already running. Use stop-axumera.ps1 first.' }
$db = Start-Process -FilePath "$($c.Runtime)\runtime\mariadb\bin\mysqld.exe" -ArgumentList "--defaults-file=$($c.Runtime)\config\axumera-my.ini" -PassThru
$admin = "$($c.Runtime)\runtime\mariadb\bin\mysqladmin.exe"
for ($i=0; $i -lt 30; $i++) { & $admin --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb ping --silent 2>$null; if ($LASTEXITCODE -eq 0) { break }; Start-Sleep -Seconds 1 }
if ($i -eq 30) { Stop-Process -Id $db.Id -Force; throw 'MariaDB did not become ready; see build/runtime/logs/mariadb-error.log.' }
$apache = Start-Process -FilePath "$($c.Runtime)\runtime\apache\bin\httpd.exe" -ArgumentList "-f `"$($c.Runtime)\runtime\apache\conf\axumera-httpd.conf`"" -PassThru
if (!$NoWait) { & (Join-Path $PSScriptRoot 'verify-runtime.ps1') }
Write-Host "Axumera server ready: http://127.0.0.1:$($c.Ports.apache)/"
