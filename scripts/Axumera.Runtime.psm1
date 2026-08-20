Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-AxumeraRoot { Split-Path -Parent $PSScriptRoot }
function Convert-AxumeraPath([string]$Path) { (Resolve-Path -LiteralPath $Path).Path.Replace('\', '/') }

function Get-AxumeraConfig {
    $root = Get-AxumeraRoot
    $runtime = if (Test-Path -LiteralPath (Join-Path $root 'runtime')) { $root } else { Join-Path $root 'build\runtime' }
    $configPath = Join-Path $runtime 'config\ports.json'
    if (!(Test-Path -LiteralPath $configPath)) { throw "Runtime is not built or installed. Run scripts/build-runtime.ps1 first." }
    [pscustomobject]@{ Root=$root; Runtime=$runtime; Ports=(Get-Content -Raw -LiteralPath $configPath | ConvertFrom-Json) }
}

function Write-AxumeraRuntimeConfig {
    $c = Get-AxumeraConfig
    $r = Convert-AxumeraPath $c.Runtime
    $app = "$r/application/eaes_exam_system"
    $logs = "$r/logs"
    New-Item -ItemType Directory -Force -Path "$($c.Runtime)\logs", "$($c.Runtime)\runtime\apache\logs", "$($c.Runtime)\data\mariadb", "$($c.Runtime)\data\tmp", "$($c.Runtime)\application\eaes_exam_system\storage\sessions" | Out-Null
    @"
[client]
host=127.0.0.1
port=$($c.Ports.mariadb)

[mysqld]
basedir="$r/runtime/mariadb"
datadir="$r/data/mariadb"
tmpdir="$r/data/tmp"
port=$($c.Ports.mariadb)
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_general_ci
default-storage-engine=InnoDB
innodb_flush_log_at_trx_commit=1
max_allowed_packet=16M
log_error="$logs/mariadb-error.log"
pid-file="$r/data/mariadb/axumera-mariadb.pid"
"@ | Set-Content -NoNewline -Encoding ascii -LiteralPath "$($c.Runtime)\config\axumera-my.ini"
    @"
[PHP]
extension_dir="$r/runtime/php/ext"
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=fileinfo
date.timezone=Africa/Addis_Ababa
display_errors=Off
log_errors=On
error_log="$logs/php-runtime-error.log"
session.save_path="$r/application/eaes_exam_system/storage/sessions"
session.use_strict_mode=1
session.cookie_httponly=1
session.cookie_samesite=Lax
upload_tmp_dir="$r/data/tmp"
upload_max_filesize=40M
post_max_size=40M
max_execution_time=120
expose_php=Off
"@ | Set-Content -NoNewline -Encoding ascii -LiteralPath "$($c.Runtime)\runtime\php\php.ini"
    @"
ServerRoot "$r/runtime/apache"
Listen 127.0.0.1:$($c.Ports.apache)
LoadModule authn_core_module modules/mod_authn_core.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule mime_module modules/mod_mime.so
LoadModule dir_module modules/mod_dir.so
LoadModule alias_module modules/mod_alias.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule log_config_module modules/mod_log_config.so
LoadFile "$r/runtime/php/php8ts.dll"
LoadFile "$r/runtime/php/libpq.dll"
LoadFile "$r/runtime/php/libsqlite3.dll"
LoadModule php_module "$r/runtime/php/php8apache2_4.dll"
PHPIniDir "$r/runtime/php"
ServerName 127.0.0.1:$($c.Ports.apache)
PidFile "$logs/apache.pid"
DocumentRoot "$app"
<Directory "$app">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Directory "$app/app">
    Require all denied
</Directory>
<Directory "$app/database">
    Require all denied
</Directory>
<Directory "$app/storage">
    Require all denied
</Directory>
<FilesMatch "^\\.env">
    Require all denied
</FilesMatch>
<FilesMatch "\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
DirectoryIndex index.php index.html
ErrorLog "$logs/apache-error.log"
CustomLog "$logs/apache-access.log" combined
"@ | Set-Content -NoNewline -Encoding ascii -LiteralPath "$($c.Runtime)\runtime\apache\conf\axumera-httpd.conf"
}

Export-ModuleMember -Function Get-AxumeraRoot,Convert-AxumeraPath,Get-AxumeraConfig,Write-AxumeraRuntimeConfig
