namespace Axumera.Core.Server;

/// <summary>Status snapshot returned by the controller.</summary>
public sealed record ServerStatus
{
    public ServerState State { get; init; } = ServerState.Unknown;

    public ServerHealth Health { get; init; } = ServerHealth.Unknown(0, 0);

    public DateTimeOffset? StartedUtc { get; init; }

    /// <summary>True when a runtime is already live that this controller did not start in this session.</summary>
    public bool AlreadyRunning { get; init; }
}
