[CmdletBinding()]
param([switch]$Force, [string]$HardwareId)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$source = Join-Path $root 'build\runtime'
$target = Join-Path $root 'build\e2e-runtime'
if (!(Test-Path "$source\AxumeraServer.exe") -or !(Test-Path "$source\application\eaes_exam_system\.env")) { throw 'Initialize and build the private test runtime before cloning it.' }
if (Test-Path $target) { if (!$Force) { throw "E2E environment already exists: $target" }; Remove-Item -Recurse -Force -LiteralPath $target }
Copy-Item -Recurse -Force -LiteralPath $source -Destination $target
# BOM-free JSON: install.php's json_decode() rejects a UTF-8 BOM.
$portsJson = @{ apache = 8090; mariadb = 3310 } | ConvertTo-Json
[System.IO.File]::WriteAllText((Join-Path $target 'config\ports.json'), $portsJson, (New-Object System.Text.UTF8Encoding($false)))
$envPath = "$target\application\eaes_exam_system\.env"
$envText = Get-Content -Raw -LiteralPath $envPath
$envText = $envText -replace '(?m)^APP_URL=.*$', 'APP_URL=http://127.0.0.1:8090'
$envText = $envText -replace '(?m)^DB_PORT=.*$', 'DB_PORT=3310'
Set-Content -NoNewline -Encoding ascii -LiteralPath $envPath -Value $envText

# Generate an ephemeral test-only key pair and a hardware-bound license inside
# the clone. The production source key, production license, and signing keys
# are never read or changed.
$php = "$target\runtime\php\php.exe"; $tmp = "$target\test-secrets"; New-Item -ItemType Directory -Force $tmp | Out-Null
# PHP's Windows OpenSSL key generator requires the bundled developer OpenSSL
# configuration while creating this disposable test key. It is not copied into
# the runtime and is not needed for production license verification.
$env:OPENSSL_CONF = Join-Path $target 'runtime\php\extras\openssl\openssl.cnf'
$uuid = if ($HardwareId) { $HardwareId -replace '[^A-Za-z0-9]','' } else { (Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID -replace '[^A-Za-z0-9]','' }
if (!$uuid) { throw 'A test hardware ID is required to issue the disposable hardware-bound test license.' }
$helper = Join-Path $PSScriptRoot 'e2e-sign-test-license.php'
& $php $helper "$tmp\private.pem" "$target\application\eaes_exam_system\app\Keys\public_key.pem" "$target\application\eaes_exam_system\storage\license.lic" $uuid
if ($LASTEXITCODE -ne 0 -or !(Test-Path "$target\application\eaes_exam_system\storage\license.lic") -or (Get-Item "$target\application\eaes_exam_system\storage\license.lic").Length -eq 0) { throw 'Disposable test-license generation failed.' }
Remove-Item -Recurse -Force -LiteralPath $tmp
Write-Host "Synthetic E2E clone ready: $target (http://127.0.0.1:8090 after startup)"
