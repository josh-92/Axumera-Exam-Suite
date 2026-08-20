namespace Axumera.Core.Configuration;

/// <summary>
/// Reference record of the production service ports (Apache 8088, MariaDB 3308).
/// Informational only in Phase 1: the development shells never bind, probe, or
/// modify these ports, and this is not a credential/secret.
/// </summary>
public sealed record ServicePorts
{
    public const int ApacheDefault = 8088;
    public const int MariaDbDefault = 3308;

    public int Apache { get; init; } = ApacheDefault;
    public int MariaDb { get; init; } = MariaDbDefault;

    public static ServicePorts Defaults { get; } = new();
}
