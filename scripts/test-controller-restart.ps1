[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $RuntimeRoot,
    [int] $ApachePort = 8088
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path $RuntimeRoot).Path
$controller = Join-Path $root 'AxumeraServer.exe'
$process = Start-Process -FilePath $controller -ArgumentList 'start' -PassThru -WindowStyle Hidden

try {
    $health = $null
    for ($attempt = 1; $attempt -le 30; $attempt++) {
        try { $health = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$ApachePort/health.php"; break }
        catch { if ($attempt -eq 30) { throw }; Start-Sleep -Seconds 1 }
    }
    if ($health.StatusCode -ne 200) { throw 'Normal restart health check failed.' }
    $env = Get-Content -Raw "$root\application\eaes_exam_system\.env"
    if ($env -notmatch 'DB_USER=axumera_app' -or $env -match 'DB_USER=root') { throw 'Private runtime did not use its dedicated application database account.' }
    if ($process.HasExited) { throw 'Controller exited after normal startup.' }
    [pscustomobject]@{ Health = $health.StatusCode; DedicatedDbUser = 'axumera_app'; ControllerStillRunning = $true } | Format-List
}
finally {
    & $controller stop | Out-Host
    Wait-Process -Id $process.Id -Timeout 15 -ErrorAction SilentlyContinue
}
