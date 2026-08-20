namespace Axumera.Core.Server;

/// <summary>Lifecycle state of the Axumera runtime as owned by the native controller.</summary>
public enum ServerState
{
    /// <summary>Nothing started; ports free.</summary>
    Stopped = 0,

    /// <summary>A start sequence is in progress.</summary>
    Starting = 1,

    /// <summary>MariaDB + Apache are up and the health endpoint passes.</summary>
    Running = 2,

    /// <summary>A stop sequence is in progress.</summary>
    Stopping = 3,

    /// <summary>Startup or runtime monitoring failed; details in the health model.</summary>
    Failed = 4,

    /// <summary>State could not be determined.</summary>
    Unknown = 5,
}
