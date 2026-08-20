[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $RuntimeRoot,
    [int] $ApachePort = 8091,
    [int] $MariaPort = 3311
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path $RuntimeRoot).Path
$controller = Join-Path $root 'AxumeraServer.exe'
$adminPassword = [guid]::NewGuid().ToString('N') + 'Aa!'
$process = Start-Process -FilePath $controller -ArgumentList 'setup' -PassThru -WindowStyle Hidden

try {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $setupUrl = "http://127.0.0.1:$ApachePort/installer/install.php"
    $requirements = $null
    for ($attempt = 1; $attempt -le 45; $attempt++) {
        try {
            $requirements = Invoke-WebRequest -UseBasicParsing -WebSession $session $setupUrl
            break
        }
        catch {
            if ($attempt -eq 45) { throw }
            Start-Sleep -Seconds 1
        }
    }
    if ($requirements.StatusCode -ne 200 -or $requirements.Content -notmatch 'System Requirements') { throw 'Setup requirements page did not load.' }

    $database = Invoke-WebRequest -UseBasicParsing -WebSession $session -Uri "${setupUrl}?step=2" -Method Post -Body @{ app_name='Axumera Setup Validation'; app_url="http://127.0.0.1:$ApachePort" }
    if ($database.Content -notmatch 'Administrator Account') { throw 'Schema/database initialization did not reach administrator setup.' }

    $admin = Invoke-WebRequest -UseBasicParsing -WebSession $session -Uri "${setupUrl}?step=3" -Method Post -Body @{ username='setup_validation_owner'; password=$adminPassword; confirm=$adminPassword }
    if ($admin.Content -notmatch 'Installation complete') { throw 'Administrator creation did not complete.' }
    if (-not (Test-Path "$root\application\eaes_exam_system\storage\installed.lock")) { throw 'Installation lock was not created.' }

    try {
        $lock = Invoke-WebRequest -UseBasicParsing -WebSession $session $setupUrl
        $lockStatus = $lock.StatusCode
    }
    catch [System.Net.WebException] {
        $lockStatus = [int] $_.Exception.Response.StatusCode
    }
    if ($lockStatus -ne 403) { throw 'Setup endpoint was not locked after installation.' }

    $mysql = Join-Path $root 'runtime\mariadb\bin\mysql.exe'
    $verification = & $mysql --protocol=tcp -h 127.0.0.1 -P $MariaPort -u root eaes_exam -N -e "SHOW TABLES LIKE 'admin_users'; SELECT username, LEFT(password_hash, 4) FROM admin_users WHERE username='setup_validation_owner';"
    if (($verification -join "`n") -notmatch 'admin_users' -or ($verification -join "`n") -notmatch 'setup_validation_owner\s+\$2y\$') { throw 'Schema or hashed administrator verification failed.' }
    if ((Get-Content -Raw "$root\application\eaes_exam_system\.env") -match [regex]::Escape($adminPassword)) { throw 'Administrator password was written to .env.' }

    [pscustomobject]@{
        SetupPage = $requirements.StatusCode
        SchemaAndAdmin = 'PASS'
        SetupLock = $lockStatus
        PasswordStorage = 'bcrypt hash verified; plaintext absent from .env'
    } | Format-List
}
finally {
    & $controller stop | Out-Host
    Wait-Process -Id $process.Id -Timeout 15 -ErrorAction SilentlyContinue
}
