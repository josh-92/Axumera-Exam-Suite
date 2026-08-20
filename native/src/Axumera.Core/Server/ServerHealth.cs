namespace Axumera.Core.Server;

/// <summary>Health of a single subsystem with an optional human detail line.</summary>
public sealed record SubsystemHealth(SubsystemState State, string? Detail = null)
{
    public static SubsystemHealth Unknown() => new(SubsystemState.Unknown);
    public static SubsystemHealth Stopped() => new(SubsystemState.Stopped);
    public static SubsystemHealth Starting(string? detail = null) => new(SubsystemState.Starting, detail);
    public static SubsystemHealth Healthy(string? detail = null) => new(SubsystemState.Healthy, detail);
    public static SubsystemHealth Degraded(string? detail = null) => new(SubsystemState.Degraded, detail);
    public static SubsystemHealth Failed(string? detail = null) => new(SubsystemState.Failed, detail);
}

/// <summary>
/// Structured server health snapshot. Internal logic uses the enums; strings are
/// only ever display detail. <see cref="OverallStatus"/> is the aggregate state.
/// </summary>
public sealed record ServerHealth
{
    public ServerState OverallStatus { get; init; } = ServerState.Unknown;

    public SubsystemHealth Apache { get; init; } = SubsystemHealth.Unknown();
    public SubsystemHealth MariaDb { get; init; } = SubsystemHealth.Unknown();
    public SubsystemHealth Php { get; init; } = SubsystemHealth.Unknown();
    public SubsystemHealth Database { get; init; } = SubsystemHealth.Unknown();
    public SubsystemHealth Lan { get; init; } = SubsystemHealth.Unknown();

    public int ApachePort { get; init; }
    public int MariaDbPort { get; init; }
    public string? ServerIp { get; init; }
    public string? AppVersion { get; init; }

    public DateTimeOffset LastChecked { get; init; } = DateTimeOffset.UtcNow;

    public string? ErrorMessage { get; init; }

    public static ServerHealth Unknown(int apachePort, int mariaDbPort) => new()
    {
        OverallStatus = ServerState.Unknown,
        ApachePort = apachePort,
        MariaDbPort = mariaDbPort,
    };

    public bool IsRunning => OverallStatus == ServerState.Running;
}
