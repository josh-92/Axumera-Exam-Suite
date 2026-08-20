[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Axumera.Runtime.psm1') -Force
$c = Get-AxumeraConfig
try { & "$($c.Runtime)\runtime\mariadb\bin\mysqladmin.exe" --protocol=tcp -h 127.0.0.1 -P $c.Ports.mariadb shutdown 2>$null } catch { }
Start-Sleep -Seconds 2
Get-Process mysqld -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$($c.Runtime)*" } | Stop-Process -Force -ErrorAction SilentlyContinue
# Apache is launched as a foreground process, not a Windows service; terminate
# only the process whose executable belongs to this generated runtime.
Get-Process httpd -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$($c.Runtime)*" } | Stop-Process -Force -ErrorAction SilentlyContinue
Write-Host 'Axumera stop requested.'
