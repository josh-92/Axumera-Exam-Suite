using Axumera.Core.Diagnostics;

namespace Axumera.Core.Configuration;

/// <summary>
/// Configuration model for the native applications (Phase 1: development only).
/// Never populated from, and never written to, production configuration.
/// </summary>
public sealed record AppSettings
{
    public LogLevel LogLevel { get; init; } = LogLevel.Info;

    /// <summary>Optional override of the per-user data directory (used only by tests / diagnostics).</summary>
    public string? DataDirectoryOverride { get; init; }
}
