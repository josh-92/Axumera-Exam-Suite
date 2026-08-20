using System.Net;
using System.Net.Sockets;
using Axumera.Core.Server;
using Xunit;

namespace Axumera.Server.Tests;

public class PortProbeTests : IDisposable
{
    private readonly TcpListener _listener;
    private readonly int _port;

    public PortProbeTests()
    {
        _listener = new TcpListener(IPAddress.Loopback, 0);
        _listener.Start();
        _port = ((IPEndPoint)_listener.LocalEndpoint).Port;
    }

    public void Dispose()
    {
        _listener.Stop();
        _listener.Server.Dispose();
    }

    [Fact]
    public void IsListening_detects_an_occupied_port()
    {
        Assert.True(PortProbe.IsListening(_port));
    }

    [Fact]
    public void IsListening_returns_false_for_a_free_port()
    {
        Assert.False(PortProbe.IsListening(GetFreePort()));
    }

    [Fact]
    public void Owning_pid_identifies_our_own_listener()
    {
        var pid = PortProbe.GetOwningProcessId(_port);

        Assert.NotNull(pid);
        Assert.Equal(Environment.ProcessId, pid.Value);
    }

    [Fact]
    public void Owning_pid_is_null_for_a_free_port()
    {
        Assert.Null(PortProbe.GetOwningProcessId(GetFreePort()));
    }

    private static int GetFreePort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        int port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        listener.Server.Dispose();
        return port;
    }
}
