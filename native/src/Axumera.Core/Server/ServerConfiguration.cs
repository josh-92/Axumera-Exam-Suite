using System.Text.RegularExpressions;
using Axumera.Core.Common;

namespace Axumera.Core.Server;

/// <summary>
/// Strongly typed configuration for the runtime the controller manages.
///
/// Paths are absolute and always derived from a single validated
/// <see cref="InstallRoot"/> (the directory that contains <c>runtime/</c>,
/// <c>application/</c>, <c>config/</c>, <c>data/</c>, <c>logs/</c>). Ports come
/// from the authoritative <c>config/ports.json</c> — never hardcoded.
/// </summary>
public sealed record ServerConfiguration
{
    public const string PortsFileName = "ports.json";

    public string InstallRoot { get; init; } = string.Empty;

    public string RuntimeRoot => Path.Combine(InstallRoot, "runtime");
    public string ApplicationRoot => Path.Combine(InstallRoot, "application", "eaes_exam_system");
    public string DataRoot => Path.Combine(InstallRoot, "data");
    public string MariaDbDataRoot => Path.Combine(DataRoot, "mariadb");
    public string LogsRoot => Path.Combine(InstallRoot, "logs");
    public string ConfigRoot => Path.Combine(InstallRoot, "config");
    public string StateFile => Path.Combine(LogsRoot, "axumera-server.state");
    public string ServerLogFile => Path.Combine(LogsRoot, "axumera-server-native.log");

    public int ApachePort { get; init; } = 8088;
    public int MariaDbPort { get; init; } = 3308;

    /// <summary>Address Apache binds to. The dev runtime binds loopback only; LAN binding is a later-phase decision.</summary>
    public string BindAddress { get; init; } = "127.0.0.1";

    public string HealthUrl => $"http://127.0.0.1:{ApachePort}/health.php";

    public string ApacheExe => Path.Combine(RuntimeRoot, "apache", "bin", "httpd.exe");
    public string PhpExe => Path.Combine(RuntimeRoot, "php", "php.exe");
    public string MySqldExe => Path.Combine(RuntimeRoot, "mariadb", "bin", "mysqld.exe");
    public string MySqlAdminExe => Path.Combine(RuntimeRoot, "mariadb", "bin", "mysqladmin.exe");
    public string MySqlExe => Path.Combine(RuntimeRoot, "mariadb", "bin", "mysql.exe");
    public string MariaInitTool => Path.Combine(RuntimeRoot, "mariadb", "bin", "mysql_install_db.exe");

    public string ApacheConfigFile => Path.Combine(RuntimeRoot, "apache", "conf", "axumera-httpd.conf");
    public string PhpIniFile => Path.Combine(RuntimeRoot, "php", "php.ini");
    public string MariaIniFile => Path.Combine(ConfigRoot, "axumera-my.ini");
    public string PortsFile => Path.Combine(ConfigRoot, PortsFileName);

    /// <summary>
    /// Loads configuration for <paramref name="installRoot"/>. Validates the root
    /// exists and is a plausible runtime layout before returning; ports are read
    /// from the authoritative ports.json (fallbacks mirror the production values).
    /// </summary>
    public static Result<ServerConfiguration> Load(string installRoot, string? portsJsonOverride = null)
    {
        if (string.IsNullOrWhiteSpace(installRoot))
        {
            return Result<ServerConfiguration>.Fail("config.empty-root", "The runtime root is empty.");
        }

        var root = Path.GetFullPath(installRoot);
        if (!Directory.Exists(root))
        {
            return Result<ServerConfiguration>.Fail("config.missing-root", $"Runtime root does not exist: {root}");
        }

        var config = new ServerConfiguration { InstallRoot = root };

        string portsFile = portsJsonOverride ?? config.PortsFile;
        if (!File.Exists(portsFile))
        {
            return Result<ServerConfiguration>.Fail("config.missing-ports", $"Port configuration is missing: {portsFile}");
        }

        try
        {
            string json = File.ReadAllText(portsFile);
            config = config with
            {
                ApachePort = ParsePort(json, "apache", 8088),
                MariaDbPort = ParsePort(json, "mariadb", 3308),
                // The bind address stays configuration-driven like the ports: the
                // default is loopback-only (exam workstations), and a school server
                // can opt into LAN access with "bindAddress": "0.0.0.0". Never
                // hardcoded in the controller.
                BindAddress = ParseBindAddress(json, "127.0.0.1"),
            };
        }
        catch (IOException ex)
        {
            return Result<ServerConfiguration>.Fail("config.unreadable-ports", $"Could not read {portsFile}: {ex.Message}");
        }

        if (config.ApachePort <= 0 || config.ApachePort > 65535 || config.MariaDbPort <= 0 || config.MariaDbPort > 65535)
        {
            return Result<ServerConfiguration>.Fail("config.invalid-ports", "ports.json contains out-of-range port values.");
        }

        if (string.IsNullOrWhiteSpace(config.BindAddress))
        {
            return Result<ServerConfiguration>.Fail("config.invalid-bind-address", "ports.json contains an empty bind address.");
        }

        if (config.ApachePort == config.MariaDbPort)
        {
            return Result<ServerConfiguration>.Fail("config.port-collision", "Apache and MariaDB ports must differ.");
        }

        return Result<ServerConfiguration>.Ok(config);
    }

    private static int ParsePort(string json, string name, int fallback)
    {
        var match = Regex.Match(json, "\"" + name + "\"\\s*:\\s*(\\d+)");
        return match.Success && int.TryParse(match.Groups[1].Value, out var port) ? port : fallback;
    }

    private static string ParseBindAddress(string json, string fallback)
    {
        // Captures an empty value too ("bindAddress": "") so the caller's
        // non-empty validation can reject it; a missing key falls back.
        var match = Regex.Match(json, "\"bindAddress\"\\s*:\\s*\"([^\"]*)\"");
        return match.Success ? match.Groups[1].Value : fallback;
    }
}
