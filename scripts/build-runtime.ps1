[CmdletBinding()]
param([switch]$Force)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Axumera.Runtime.psm1') -Force
$root = Get-AxumeraRoot
$destination = Join-Path $root 'build\runtime'
if ((Test-Path -LiteralPath $destination) -and !$Force) { throw "Build output exists: $destination. Re-run with -Force to replace generated output." }
if (Test-Path -LiteralPath $destination) { Remove-Item -LiteralPath $destination -Recurse -Force }
New-Item -ItemType Directory -Force -Path $destination, "$destination\runtime", "$destination\config", "$destination\data\tmp", "$destination\logs" | Out-Null

# Copy only executable runtime material; no XAMPP control panel, phpMyAdmin, PHP tests, PEAR, manuals, or MariaDB backup data.
Copy-Item -Recurse -Force (Join-Path $root 'runtime-source\apache\bin') "$destination\runtime\apache\bin"
Copy-Item -Recurse -Force (Join-Path $root 'runtime-source\apache\modules') "$destination\runtime\apache\modules"
New-Item -ItemType Directory -Force -Path "$destination\runtime\apache\conf" | Out-Null
Copy-Item -Force (Join-Path $root 'runtime-source\apache\conf\mime.types') "$destination\runtime\apache\conf\mime.types"
$php = Join-Path $root 'runtime-source\php'
New-Item -ItemType Directory -Force -Path "$destination\runtime\php", "$destination\runtime\php\ext" | Out-Null
Get-ChildItem $php -File | Where-Object { $_.Extension -in '.exe','.dll' } | Copy-Item -Destination "$destination\runtime\php" -Force
@('php_pdo_mysql.dll','php_mbstring.dll','php_openssl.dll','php_fileinfo.dll') | ForEach-Object { Copy-Item -Force (Join-Path $php "ext\$_") "$destination\runtime\php\ext\$_" }
New-Item -ItemType Directory -Force -Path "$destination\runtime\php\extras\openssl" | Out-Null
Copy-Item -Force (Join-Path $php 'extras\openssl\openssl.cnf') "$destination\runtime\php\extras\openssl\openssl.cnf"
$db = Join-Path $root 'runtime-source\mariadb'
Copy-Item -Recurse -Force "$db\bin" "$destination\runtime\mariadb\bin"
Copy-Item -Recurse -Force "$db\share" "$destination\runtime\mariadb\share"
Copy-Item -Force "$db\COPYING", "$db\THIRDPARTY" "$destination\runtime\mariadb"
Remove-Item -Force -ErrorAction SilentlyContinue "$destination\runtime\mariadb\bin\my.ini"

$sourceApp = Join-Path $root 'application\eaes_exam_system'
$targetApp = "$destination\application\eaes_exam_system"
Copy-Item -Recurse -Force $sourceApp $targetApp
Remove-Item -Force -ErrorAction SilentlyContinue "$targetApp\.env", "$targetApp\storage\license.lic", "$targetApp\storage\installed.lock"
Remove-Item -Force -ErrorAction SilentlyContinue "$targetApp\yakpro-po.cnf"
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue "$targetApp\docs", "$targetApp\tests", "$targetApp\storage\logs", "$targetApp\storage\cache", "$targetApp\storage\sessions"
New-Item -ItemType Directory -Force -Path "$targetApp\storage\logs", "$targetApp\storage\cache", "$targetApp\storage\sessions" | Out-Null
# Windows PowerShell 5.1 Set-Content -Encoding utf8 writes a UTF-8 BOM, which breaks the PHP
# installer's json_decode() (install.php detects the private runtime from this file).  Write it
# explicitly without a BOM.
$portsJson = @{ apache = 8088; mariadb = 3308 } | ConvertTo-Json
[System.IO.File]::WriteAllText((Join-Path $destination 'config\ports.json'), $portsJson, (New-Object System.Text.UTF8Encoding($false)))
Write-AxumeraRuntimeConfig
Write-Host "Runtime built at $destination"
