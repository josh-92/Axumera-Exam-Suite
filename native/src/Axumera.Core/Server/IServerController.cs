using Axumera.Core.Common;

namespace Axumera.Core.Server;

/// <summary>
/// The single abstraction through which server control happens.
///
/// This is the boundary the future Control Panel will call (over a LOCAL-only
/// transport, decided later — named pipe or loopback HTTP with a per-install
/// token). Nothing outside this interface may touch Apache/MariaDB processes.
/// No server-control endpoint is ever exposed to the LAN.
/// </summary>
public interface IServerController
{
    ServerConfiguration Configuration { get; }

    /// <summary>Raised when the aggregate lifecycle state changes.</summary>
    event EventHandler<ServerState>? StateChanged;

    /// <summary>Raised whenever a health snapshot is produced.</summary>
    event EventHandler<ServerHealth>? HealthUpdated;

    /// <summary>Starts the runtime: validate → MariaDB ready → Apache ready → health → Running.</summary>
    Task<Result> StartAsync(CancellationToken cancellationToken = default);

    /// <summary>Stops the runtime cleanly (Apache first, then MariaDB), waits for ports to free.</summary>
    Task<Result> StopAsync(CancellationToken cancellationToken = default);

    /// <summary>Clean stop, verified, then a clean start.</summary>
    Task<Result> RestartAsync(CancellationToken cancellationToken = default);

    /// <summary>Current lifecycle state plus the latest health snapshot.</summary>
    Task<ServerStatus> GetStatusAsync(CancellationToken cancellationToken = default);

    /// <summary>Fresh health probe of every subsystem.</summary>
    Task<ServerHealth> GetHealthAsync(CancellationToken cancellationToken = default);

    /// <summary>
    /// True when a live runtime is already detected (state file cross-checked with
    /// real processes/ports) that this controller instance did not start.
    /// </summary>
    bool IsServerAlreadyRunning();
}
