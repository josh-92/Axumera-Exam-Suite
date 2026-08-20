# port-holder.ps1 <port> — binds the given TCP port on 127.0.0.1 and holds it
# until the process is terminated. Used by the integration tests to simulate a
# port conflict with a completely unrelated process.
param([int]$Port = 8090)

$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Port)
try {
    $listener.Start()
    Write-Output "HOLDING $Port (pid $PID)"
    while ($true) { Start-Sleep -Seconds 1 }
}
finally {
    $listener.Stop()
    $listener.Server.Dispose()
}
