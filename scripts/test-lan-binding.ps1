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
    for ($i = 1; $i -le 30; $i++) { try { $health = Invoke-WebRequest -UseBasicParsing "http://127.0.0.1:$ApachePort/health.php"; break } catch { Start-Sleep -Seconds 1 } }
    if ($null -eq $health -or $health.StatusCode -ne 200) { throw 'Server health did not become ready.' }
    $lanAddress = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.PrefixOrigin -ne 'WellKnown' } | Select-Object -First 1 -ExpandProperty IPAddress
    $apacheConfig = Get-Content -Raw "$root\runtime\apache\conf\axumera-httpd.conf"
    $mariadbConfig = Get-Content -Raw "$root\config\axumera-my.ini"
    [pscustomobject]@{
        Health = $health.StatusCode
        LanAddress = $lanAddress
        LanPortReachable = Test-NetConnection -ComputerName $lanAddress -Port $ApachePort -InformationLevel Quiet
        ApacheListen = $apacheConfig -match "Listen $ApachePort"
        MariaDbLoopbackOnly = $mariadbConfig -match 'bind-address=127.0.0.1'
    } | Format-List
}
finally {
    & $controller stop | Out-Host
    Wait-Process -Id $process.Id -Timeout 15 -ErrorAction SilentlyContinue
}
