using System.Text.Json;

namespace Axumera.Core.Student;

/// <summary>
/// Persisted configuration for the Axumera Student client (LAN model).
///
/// The student enters the school server's address (IP or host name); the
/// Apache port comes from the configured runtime default, never hardcoded to a
/// production value. The file holds only the address and port — never
/// passwords or session material.
///
/// Storage: %LOCALAPPDATA%\Axumera 2.0\config\student-server.json (same
/// per-user area as the other native applications, deliberately disjoint from
/// the production installation).
/// </summary>
public sealed record StudentClientConfiguration
{
    /// <summary>Default Apache port for the development build (dev runtime 8090).</summary>
    public const int DevApachePort = 8090;

    /// <summary>Name of the persisted configuration file.</summary>
    public const string ConfigFileName = "student-server.json";

    /// <summary>Normalized server address (host name or IP, no scheme, no path).</summary>
    public string ServerAddress { get; init; } = string.Empty;

    /// <summary>Apache port the school server listens on.</summary>
    public int ApachePort { get; init; } = DevApachePort;

    // ------------------------------------------------------------- URL builders

    public Uri HealthUrl => Build("health.php");
    public Uri StudentLoginUrl => Build("slogin.php");
    public Uri ReviewUrl => Build("review.php");

    private Uri Build(string path) =>
        new($"http://{ServerAddress}:{ApachePort}/{path}");

    // ------------------------------------------------------------- validation

    public bool IsValid => IsValidAddress(ServerAddress) && ApachePort is > 0 and <= 65535;

    /// <summary>
    /// Normalizes free-form input into a usable server address: strips scheme
    /// and trailing path (same policy as the proven legacy student launcher),
    /// then lower-cases host names. Returns an empty string when nothing valid
    /// remains.
    /// </summary>
    public static string NormalizeAddress(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return string.Empty;
        }

        var trimmed = value.Trim();
        if (trimmed.StartsWith("http://", StringComparison.OrdinalIgnoreCase))
        {
            trimmed = trimmed.Substring("http://".Length);
        }
        else if (trimmed.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
        {
            trimmed = trimmed.Substring("https://".Length);
        }

        int slash = trimmed.IndexOfAny(['/', '\\']);
        if (slash >= 0)
        {
            trimmed = trimmed.Substring(0, slash);
        }

        trimmed = trimmed.Trim().TrimEnd('.');

        if (trimmed.Length == 0)
        {
            return string.Empty;
        }

        // Reject obviously invalid input (spaces, URI characters) so the URL
        // builder can never emit a malformed origin.
        foreach (char c in trimmed)
        {
            if (char.IsWhiteSpace(c) || c is '?' or '#' or ':')
            {
                return string.Empty;
            }
        }

        return trimmed;
    }

    private static bool IsValidAddress(string? address)
    {
        if (string.IsNullOrWhiteSpace(address))
        {
            return false;
        }

        foreach (char c in address)
        {
            if (char.IsWhiteSpace(c) || c is '?' or '#' or ':')
            {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------- persistence

    /// <summary>Full path of the per-user configuration file.</summary>
    public static string ConfigFilePath =>
        Path.Combine(
            Paths.AppPaths.BaseDataDirectory,
            "config",
            ConfigFileName);

    /// <summary>Loads the persisted configuration, or a default when absent/corrupt.</summary>
    public static StudentClientConfiguration Load() => LoadFrom(ConfigFilePath);

    /// <summary>Loads from an explicit path (tests use a temp file).</summary>
    public static StudentClientConfiguration LoadFrom(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                var loaded = JsonSerializer.Deserialize<StudentClientConfiguration>(File.ReadAllText(path));
                if (loaded is not null && loaded.IsValid)
                {
                    return loaded;
                }
            }
        }
        catch (Exception)
        {
            // Corrupt or unreadable config falls back to the default; the
            // student is asked again at startup.
        }

        return new StudentClientConfiguration();
    }

    /// <summary>Persists the configuration (address + port only; never secrets).</summary>
    public void Save() => SaveTo(ConfigFilePath);

    /// <summary>Persists to an explicit path (tests use a temp file).</summary>
    public void SaveTo(string path)
    {
        var directory = Path.GetDirectoryName(path);
        if (directory is not null)
        {
            Directory.CreateDirectory(directory);
        }

        File.WriteAllText(
            path,
            JsonSerializer.Serialize(
                new StudentClientConfiguration
                {
                    ServerAddress = ServerAddress,
                    ApachePort = ApachePort,
                },
                new JsonSerializerOptions { WriteIndented = true }));
    }
}
