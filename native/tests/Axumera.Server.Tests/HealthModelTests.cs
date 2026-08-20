using Axumera.Core.Server;
using Xunit;

namespace Axumera.Server.Tests;

public class HealthModelTests
{
    [Fact]
    public void Unknown_health_has_reference_ports_and_unknown_state()
    {
        var health = ServerHealth.Unknown(8090, 3310);

        Assert.Equal(ServerState.Unknown, health.OverallStatus);
        Assert.Equal(8090, health.ApachePort);
        Assert.Equal(3310, health.MariaDbPort);
        Assert.Equal(SubsystemState.Unknown, health.Apache.State);
    }

    [Fact]
    public void IsRunning_matches_running_state_only()
    {
        Assert.True(new ServerHealth { OverallStatus = ServerState.Running }.IsRunning);
        Assert.False(new ServerHealth { OverallStatus = ServerState.Stopped }.IsRunning);
        Assert.False(new ServerHealth { OverallStatus = ServerState.Failed }.IsRunning);
    }

    [Fact]
    public void Subsystem_health_factories_carry_state_and_detail()
    {
        Assert.Equal(SubsystemState.Healthy, SubsystemHealth.Healthy("ok").State);
        Assert.Equal("ok", SubsystemHealth.Healthy("ok").Detail);
        Assert.Equal(SubsystemState.Failed, SubsystemHealth.Failed("bad").State);
        Assert.Equal(SubsystemState.Degraded, SubsystemHealth.Degraded().State);
    }

    [Fact]
    public void Server_status_carries_state_and_provenance()
    {
        var status = new ServerStatus
        {
            State = ServerState.Running,
            Health = ServerHealth.Unknown(8090, 3310),
            AlreadyRunning = true,
            StartedUtc = DateTimeOffset.UtcNow,
        };

        Assert.Equal(ServerState.Running, status.State);
        Assert.True(status.AlreadyRunning);
        Assert.NotNull(status.StartedUtc);
    }
}
