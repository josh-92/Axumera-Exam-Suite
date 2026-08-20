using System.Diagnostics;
using System.Net;
using System.Net.Sockets;

namespace Axumera.Core.Server;

/// <summary>Read-only operational probes. No state is written here.</summary>
public static class HealthProbe
{
    /// <summary>True when mysqld answers a TCP ping on the configured port.</summary>
    public static bool MariaDbPing(string mysqladminExe, int port, int timeoutMs = 3000)
    {
        try
        {
            using var process = Process.Start(new ProcessStartInfo(
                mysqladminExe,
                $"--protocol=tcp -h 127.0.0.1 -P {port} ping --silent")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
            });
            if (process is null)
            {
                return false;
            }

            process.WaitForExit(timeoutMs);
            return process.HasExited && process.ExitCode == 0;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// True only when the server answers a real query on the configured port.
    /// mysqladmin ping succeeds earlier than full query readiness (InnoDB
    /// recovery / system-table work can still be running), so startup and
    /// installer flows use this stricter probe.
    /// </summary>
    public static bool MariaDbReady(string mysqlExe, int port, int timeoutMs = 5000)
    {
        try
        {
            using var process = Process.Start(new ProcessStartInfo(
                mysqlExe,
                $"--protocol=tcp -h 127.0.0.1 -P {port} -u root --connect-timeout=5 --batch --skip-column-names -e \"SELECT 1\"")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
            });
            if (process is null)
            {
                return false;
            }

            process.WaitForExit(timeoutMs);
            return process.HasExited && process.ExitCode == 0;
        }
        catch
        {
            return false;
        }
    }

    /// <summary>True when the health endpoint returns HTTP 200 with an "ok" body (proves PHP + DB).</summary>
    public static async Task<bool> HttpHealthyAsync(string url, TimeSpan timeout)
    {
        try
        {
            using var handler = new HttpClientHandler();
            using var client = new HttpClient(handler) { Timeout = timeout };
            using var response = await client.GetAsync(url).ConfigureAwait(false);
            if (response.StatusCode != HttpStatusCode.OK)
            {
                return false;
            }

            var body = await response.Content.ReadAsStringAsync().ConfigureAwait(false);
            return body.Contains("\"ok\"", StringComparison.OrdinalIgnoreCase);
        }
        catch
        {
            return false;
        }
    }

    /// <summary>First non-loopback IPv4 address, or null when only loopback exists.</summary>
    public static string? LanIpv4()
    {
        try
        {
            foreach (var address in Dns.GetHostAddresses(Dns.GetHostName()))
            {
                if (address.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(address))
                {
                    return address.ToString();
                }
            }
        }
        catch
        {
        }

        return null;
    }

    public static string? ReadAppVersion(string applicationRoot)
    {
        try
        {
            var version = File.ReadAllText(Path.Combine(applicationRoot, "VERSION")).Trim();
            return version.Length > 0 ? version : null;
        }
        catch
        {
            return null;
        }
    }
}
