[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string]$AdminUsername,
    [Parameter(Mandatory)] [securestring]$AdminPassword,
    [string]$AppName = 'Axumera Exam Suite',
    [string]$DatabaseName = 'eaes_exam',
    [switch]$ApplyLegacyMigrations
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Axumera.Runtime.psm1') -Force
$c = Get-AxumeraConfig; Write-AxumeraRuntimeConfig
if ($DatabaseName -notmatch '^[A-Za-z0-9_]+$') { throw 'DatabaseName may contain only letters, digits, and underscores.' }

# ---------------------------------------------------------------------------
# Fresh-install state classification. A MariaDB datadir that exists before this
# run begins is either a leftover from a failed fresh-install attempt or
# pre-existing data. Only a leftover owned by this installer's own incomplete
# installation (payload present, never marked complete) may be removed so a
# retry starts clean. Completed installations and unknown data are never
# touched; if the state cannot be told apart safely, the install fails closed.
# ---------------------------------------------------------------------------
$runtime     = $c.Runtime
$envFile     = "$runtime\application\eaes_exam_system\.env"
$lockFile    = "$runtime\application\eaes_exam_system\storage\installed.lock"
$datadir     = "$runtime\data\mariadb"
$mysqlDir    = "$datadir\mysql"
$dbBin       = "$runtime\runtime\mariadb\bin"

$ownedLayout = (Test-Path -LiteralPath "$runtime\Axumera.Server.exe") -and
               (Test-Path -LiteralPath "$runtime\scripts\initialize-database.ps1") -and
               (Test-Path -LiteralPath "$runtime\config\ports.json")
$datadirHasContent = Test-Path -LiteralPath $mysqlDir
if (-not $datadirHasContent -and (Test-Path -LiteralPath $datadir)) {
    $datadirHasContent = @(Get-ChildItem -LiteralPath $datadir -Force -ErrorAction SilentlyContinue).Count -gt 0
}

$db = $null
$rollback = $true   # a fresh install that fails must clean up after itself

if ($datadirHasContent) {
    if (Test-Path -LiteralPath $lockFile) {
        throw 'This installation is already complete (installed.lock is present). Refusing to touch its existing database. Uninstall or move the installation before reinstalling.'
    }
    if (-not $ownedLayout) {
        throw 'A MariaDB data directory already exists in this folder, but the folder is not a known Axumera installation. Refusing to remove unknown data. If it is safe to discard, remove it manually and run the installer again.'
    }
    $active = Get-CimInstance Win32_Process -Filter "Name = 'mysqld.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -like "*$runtime*" }
    if ($active) {
        throw 'A MariaDB server is currently running against this data directory. Stop it before retrying the installation.'
    }
    Write-Warning "Removing the incomplete MariaDB data directory left by a previous failed fresh installation: $datadir"
    Remove-Item -LiteralPath $datadir -Recurse -Force
}

# MariaDB 10.4's Windows bootstrap utility does not support --defaults-file;
# it takes its data directory directly and starts mysqld itself during setup.
# It can leave its internal mysqld shutting down behind it, so the port must be
# free before we start our own instance below (otherwise our mysqld exits at
# bind and every later operation silently targets the leftover instance).
& "$dbBin\mysql_install_db.exe" --datadir="$datadir" --port=$($c.Ports.mariadb) --silent | Out-Null
if ($LASTEXITCODE -ne 0) { throw "MariaDB bootstrap failed (mysql_install_db exit $LASTEXITCODE)." }

# A port is only "free" when we can bind it ourselves: a connect-based probe
# cannot tell "no server" apart from a TIME_WAIT ghost (a just-exited mysqld
# keeps its port non-bindable for up to ~2 minutes on Windows). mysqld binds
# the port too, so a successful bind here is exactly the condition we need.
function Test-PortFree([int]$Port) {
    $listener = New-Object System.Net.Sockets.TcpListener([System.Net.IPAddress]::Loopback, $Port)
    try {
        $listener.Start()
        $listener.Stop()
        return $true
    } catch { return $false } finally { try { $listener.Stop() } catch { } }
}

# Returns the executable path of the process currently LISTENING on the port
# (null when nothing is listening, e.g. only a TIME_WAIT ghost remains).
# An unreadable path (e.g. an elevated process seen from a non-elevated
# installer) is reported as "UNKNOWN-LISTENER" so callers fail closed instead
# of assuming the port is free.
function Test-LiveListener([int]$Port) {
    $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $conn) { return $null }
    $proc = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
    if ($proc -and $proc.Path) { return $proc.Path }
    return 'UNKNOWN-LISTENER'
}

# A live foreign server on the port must never receive this installation's
# SQL: fail fast with a clear message. Unknown occupants (unreadable path)
# are treated as foreign.
$livePath = Test-LiveListener $c.Ports.mariadb
if ($livePath -and -not ($livePath -like "$runtime*")) {
    throw "MariaDB port $($c.Ports.mariadb) is in use by a process that is not part of this installation$($(if ($livePath -eq 'UNKNOWN-LISTENER') { ' (its owner could not be identified)' } else { " ($livePath)" })). Stop that process or choose different ports, then retry the installation."
}

# A previous instance (bootstrap's own or an earlier attempt's) may still hold
# the port while shutting down; its TIME_WAIT ghost can outlive it by ~2
# minutes. Wait (bounded) for the port to become bindable again.
$portFree = $false
for ($i = 0; $i -lt 360; $i++) { if (Test-PortFree $c.Ports.mariadb) { $portFree = $true; break }; Start-Sleep -Milliseconds 500 }
if (-not $portFree) {
    $livePath = Test-LiveListener $c.Ports.mariadb
    if ($livePath -and -not ($livePath -like "$runtime*")) {
        throw "MariaDB port $($c.Ports.mariadb) is in use by a process that is not part of this installation$($(if ($livePath -eq 'UNKNOWN-LISTENER') { ' (its owner could not be identified)' } else { " ($livePath)" })). Stop that process or choose different ports, then retry the installation."
    }
    throw "MariaDB port $($c.Ports.mariadb) did not become free within 3 minutes. A previous MariaDB may still be shutting down; wait a moment and retry the installation."
}

$db = Start-Process -FilePath "$dbBin\mysqld.exe" -ArgumentList "--defaults-file=`"$runtime\config\axumera-my.ini`"" -PassThru

# Readiness probe with a hard per-attempt bound. A plain synchronous
# 'mysqladmin ping' can block forever (e.g. when another process holds the
# MariaDB port without accepting), which would hang the installer with no
# error and no rollback. Each attempt is killed after 2 seconds.
# A real query is used instead of ping: ping succeeds while InnoDB recovery is
# still running, and the very first real statement would then fail.
function Test-MariaDbReady([int]$Port) {
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = "$dbBin\mysql.exe"
    $psi.Arguments = "--protocol=tcp -h 127.0.0.1 -P $Port -u root --connect-timeout=5 --batch --skip-column-names -e `"SELECT 1`""
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    try {
        $proc = [System.Diagnostics.Process]::Start($psi)
        if (-not $proc.WaitForExit(2000)) {
            try { $proc.Kill() } catch { }
            $proc.WaitForExit()
            return $false
        }
        return ($proc.ExitCode -eq 0)
    } catch { return $false }
}

try {
    for ($i=0; $i -lt 45; $i++) {
        # SQL is only ever run while OUR mysqld is alive and bound to the
        # port; if it exited (e.g. a port conflict), fail instead of probing
        # whatever else might answer on the port.
        if ($db.HasExited) { throw 'MariaDB exited during startup instead of binding the port. Another process may be holding it; stop it or choose different ports, then retry.' }
        if (Test-MariaDbReady $c.Ports.mariadb) { break }
        Start-Sleep -Seconds 1
    }
    if ($i -eq 45) { throw 'MariaDB initialization did not become ready.' }
    $bytes = New-Object byte[] 32; [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $dbPassword = [Convert]::ToBase64String($bytes).Replace('+','A').Replace('/','B').Replace('=','')
    $escapedPassword = $dbPassword.Replace("'", "''")
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root -e "CREATE DATABASE IF NOT EXISTS ``$DatabaseName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to create the application database.' }
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root -e "CREATE USER IF NOT EXISTS 'axumera_app'@'127.0.0.1' IDENTIFIED BY '$escapedPassword'"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to create the application database account.' }
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root -e "ALTER USER 'axumera_app'@'127.0.0.1' IDENTIFIED BY '$escapedPassword'"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to configure the application database account.' }
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root -e "GRANT ALL PRIVILEGES ON ``$DatabaseName``.* TO 'axumera_app'@'127.0.0.1'"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to grant database access to the application account.' }
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root -e 'FLUSH PRIVILEGES'
    if ($LASTEXITCODE -ne 0) { throw 'Unable to flush database privileges.' }
    Get-Content -Raw "$($c.Runtime)\application\eaes_exam_system\database\schema.sql" | & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root $DatabaseName
    if ($ApplyLegacyMigrations) {
        Write-Warning 'Applying legacy migrations was explicitly requested. Review their prerequisites before using this on a clean install.'
        Get-ChildItem "$($c.Runtime)\application\eaes_exam_system\database\migrations" -Filter '*.sql' | Sort-Object Name | ForEach-Object { Get-Content -Raw $_.FullName | & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root $DatabaseName }
    } else {
        Write-Warning 'Legacy migrations were not applied: the supplied migration set targets incompatible historical tables (admins/users), while schema.sql is the clean-install baseline. See AXUMERA_RUNTIME_AUDIT.md.'
    }
    $plainAdmin = [System.Net.NetworkCredential]::new('', $AdminPassword).Password
    if ($plainAdmin.Length -lt 8) { throw 'AdminPassword must be at least eight characters.' }
    $php = "$($c.Runtime)\runtime\php\php.exe"
    $hash = & $php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' $plainAdmin
    $adminSql = "INSERT INTO ``admin_users`` (username,password_hash,full_name,role,created_at) VALUES ('$($AdminUsername.Replace("'","''"))','$($hash.Replace("'","''"))','Administrator','owner',NOW());"
    & "$dbBin\mysql.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb -u root $DatabaseName -e $adminSql
    $appUrl = "http://127.0.0.1:$($c.Ports.apache)"
    $appKey = (& $php -r 'echo bin2hex(random_bytes(32));')
    @"
APP_NAME="$AppName"
APP_ENV=production
APP_DEBUG=false
APP_URL=$appUrl
APP_TIMEZONE=Africa/Addis_Ababa
APP_KEY=$appKey
DB_HOST=127.0.0.1
DB_PORT=$($c.Ports.mariadb)
DB_NAME=$DatabaseName
DB_USER=axumera_app
DB_PASS=$dbPassword
DB_CHARSET=utf8mb4
SESSION_LIFETIME_MINUTES=180
ADMIN_MAX_LOGIN_ATTEMPTS=5
ADMIN_LOCKOUT_MINUTES=15
FORCE_HTTPS=false
AUTOSAVE_INTERVAL_SECONDS=15
GRACE_PERIOD_SECONDS=10
"@ | Set-Content -NoNewline -Encoding ascii "$($c.Runtime)\application\eaes_exam_system\.env"
    Set-Content -NoNewline -Encoding ascii "$($c.Runtime)\application\eaes_exam_system\storage\installed.lock" ((Get-Date).ToUniversalTime().ToString('o'))
    $rollback = $false
    Write-Host 'Database initialized. Credentials were written only to the runtime .env and were not printed.'
} finally {
    # Stop the mysqld this script started. The graceful shutdown is bounded
    # like the readiness probe: with a conflicting process on the MariaDB
    # port, 'mysqladmin shutdown' can otherwise block forever.
    $wasStarted = $db -and -not $db.HasExited
    if ($wasStarted) {
        $shutdownPsi = New-Object System.Diagnostics.ProcessStartInfo
        $shutdownPsi.FileName = "$dbBin\mysqladmin.exe"
        $shutdownPsi.Arguments = "--protocol=tcp -h 127.0.0.1 -P $($c.Ports.mariadb) shutdown"
        $shutdownPsi.UseShellExecute = $false
        $shutdownPsi.CreateNoWindow = $true
        $shutdownPsi.RedirectStandardOutput = $true
        $shutdownPsi.RedirectStandardError = $true
        try {
            $sh = [System.Diagnostics.Process]::Start($shutdownPsi)
            if (-not $sh.WaitForExit(5000)) {
                try { $sh.Kill() } catch { }
                $sh.WaitForExit()
            }
        } catch { }
        # Give InnoDB enough time to flush cleanly before any force-kill so
        # the data directory is not left dirty for the next start.
        $null = $db.WaitForExit(30000)
        if (-not $db.HasExited) {
            $null = $db.WaitForExit(5000)
            if (-not $db.HasExited) { Stop-Process -Id $db.Id -Force -ErrorAction SilentlyContinue }
        }
    } elseif ($db -and (Test-PortFree $c.Ports.mariadb) -eq $false) {
        # Our mysqld exited without ever binding. If the port is still held by
        # a mysqld running against OUR data directory (the bootstrap utility's
        # own instance), shut it down so no stray MariaDB keeps running. A
        # foreign server on the port is left untouched (fail-closed install).
        $occupant = Get-CimInstance Win32_Process -Filter "Name = 'mysqld.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -and $_.CommandLine -like "*$runtime*" }
        if ($occupant) {
        $leftoverPsi = New-Object System.Diagnostics.ProcessStartInfo
        $leftoverPsi.FileName = "$dbBin\mysqladmin.exe"
        $leftoverPsi.Arguments = "--protocol=tcp -h 127.0.0.1 -P $($c.Ports.mariadb) shutdown"
        $leftoverPsi.UseShellExecute = $false
        $leftoverPsi.CreateNoWindow = $true
        $leftoverPsi.RedirectStandardOutput = $true
        $leftoverPsi.RedirectStandardError = $true
        try {
            $leftover = [System.Diagnostics.Process]::Start($leftoverPsi)
            if (-not $leftover.WaitForExit(5000)) {
                try { $leftover.Kill() } catch { }
                $leftover.WaitForExit()
            }
        } catch { }
        }
    }
    # Wait for the port to actually stop accepting so the next step (the
    # migration ledger run) never races a dying instance.
    for ($i = 0; $i -lt 30; $i++) { if (Test-PortFree $c.Ports.mariadb) { break }; Start-Sleep -Milliseconds 500 }
    # Transactional rollback: a failed fresh installation removes only the
    # datadir it created, so the installer can be run again immediately.
    if ($rollback -and (Test-Path -LiteralPath $datadir)) {
        Write-Warning "Database initialization did not complete; removing the incomplete data directory created by this attempt so the installation can be retried: $datadir"
        Remove-Item -LiteralPath $datadir -Recurse -Force -ErrorAction SilentlyContinue
    }
}

