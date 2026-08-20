[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Axumera.Runtime.psm1') -Force
$c = Get-AxumeraConfig
$php = "$($c.Runtime)\runtime\php\php.exe"
& $php -m | Select-String -Quiet '^pdo_mysql$' | Out-Null
foreach ($extension in 'pdo_mysql','mbstring','openssl','fileinfo') { if (!((& $php -m) -contains $extension)) { throw "Required PHP extension missing: $extension" } }
& "$($c.Runtime)\runtime\apache\bin\httpd.exe" -t -f "$($c.Runtime)\runtime\apache\conf\axumera-httpd.conf"
& "$($c.Runtime)\runtime\mariadb\bin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb ping --silent | Out-Null
$response = Invoke-WebRequest -UseBasicParsing -Uri "http://127.0.0.1:$($c.Ports.apache)/health.php" -TimeoutSec 15
if ($response.StatusCode -ne 200 -or $response.Content -notmatch '"status"\s*:\s*"ok"') { throw 'Application health check failed.' }
Write-Host 'PASS: Apache, PHP, MariaDB, PDO connection, and application bootstrap are healthy.'
