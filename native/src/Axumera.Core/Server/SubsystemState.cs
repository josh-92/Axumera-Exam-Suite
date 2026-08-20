namespace Axumera.Core.Server;

/// <summary>Health state of a single subsystem (Apache, MariaDB, PHP, database, LAN).</summary>
public enum SubsystemState
{
    Unknown = 0,
    Stopped = 1,
    Starting = 2,
    Healthy = 3,
    Degraded = 4,
    Failed = 5,
}
