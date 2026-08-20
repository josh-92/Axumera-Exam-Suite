using System.Diagnostics;
using System.Threading;
using Axumera.Core.Server;

namespace Axumera.Server.Control;

/// <summary>
/// Owns the child processes the controller starts (mysqld, httpd) and implements
/// real readiness checks — a process existing is never treated as ready.
/// </summary>
public sealed class ServiceProcessManager
{
    private readonly ServerConfiguration _config;
    private readonly Action<string> _log;

    private Process? _maria;
    private Process? _apache;

    public ServiceProcessManager(ServerConfiguration config, Action<string> log)
    {
        _config = config;
        _log = log;
    }

    public Process? MariaDbProcess => _maria;
    public Process? ApacheProcess => _apache;

    // ---------------------------------------------------------------- start

    public void StartMariaDb()
    {
        var exe = _config.MySqldExe;
        var args = "--defaults-file=\"" + _config.MariaIniFile + "\"";
        _maria = Launch(exe, args);
        _log($"MariaDB launched (pid {_maria.Id}).");
    }

    /// <summary>True only when MariaDB answers a real query on the configured port.</summary>
    public bool WaitForMariaDbReady(int attempts = 30, int delayMs = 1000, CancellationToken cancellationToken = default)
    {
        for (int i = 0; i < attempts; i++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (HealthProbe.MariaDbReady(_config.MySqlExe, _config.MariaDbPort))
            {
                return true;
            }

            Thread.Sleep(delayMs);
        }

        return false;
    }

    public void StartApache()
    {
        var exe = _config.ApacheExe;
        var args = "-f \"" + _config.ApacheConfigFile + "\"";
        var pidFile = Path.Combine(_config.LogsRoot, "apache.pid");

        // An unclean prior shutdown can leave an old PID here. Remove that
        // controller-generated artifact before launch so ProcessFromPidFile
        // cannot record a stale, unrelated PID in our state file.
        try
        {
            if (File.Exists(pidFile))
            {
                File.Delete(pidFile);
            }
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }

        _apache = Launch(exe, args);
        _apache = ProcessFromPidFile(pidFile, _apache);
        _log($"Apache launched (pid {_apache.Id}).");
    }

    /// <summary>True only when the configured Apache port is listening.</summary>
    public bool WaitForApachePort(int attempts = 30, int delayMs = 1000, CancellationToken cancellationToken = default)
    {
        for (int i = 0; i < attempts; i++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            if (PortProbe.IsListening(_config.ApachePort))
            {
                return true;
            }

            Thread.Sleep(delayMs);
        }

        return false;
    }

    // ---------------------------------------------------------------- stop

    /// <summary>Graceful httpd shutdown, then a bounded kill fallback.</summary>
    public void StopApache()
    {
        try
        {
            if (_apache is not null && !_apache.HasExited)
            {
                // Graceful shutdown via the config (mirrors production stop semantics).
                Launch(_config.ApacheExe, "-f \"" + _config.ApacheConfigFile + "\" -k shutdown")?.WaitForExit(5000);
            }
        }
        catch
        {
        }

        if (_apache is not null)
        {
            TryKill(_apache);
        }

        _apache = null;
    }

    /// <summary>mysqladmin shutdown (graceful), then a bounded kill fallback.</summary>
    public void StopMariaDb()
    {
        try
        {
            Launch(_config.MySqlAdminExe, $"--protocol=tcp -h 127.0.0.1 -P {_config.MariaDbPort} shutdown")?.WaitForExit(5000);
        }
        catch
        {
        }

        if (_maria is not null)
        {
            TryKill(_maria);
        }

        _maria = null;
    }

    /// <summary>True while either owned child is still alive.</summary>
    public bool HasLiveChildren()
    {
        bool mariaAlive = _maria is not null && !_maria.HasExited;
        bool apacheAlive = _apache is not null && !_apache.HasExited;
        return mariaAlive || apacheAlive;
    }

    // ---------------------------------------------------------------- helpers

    private Process? Launch(string file, string args)
    {
        return Process.Start(new ProcessStartInfo(file, args)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = _config.InstallRoot,
        });
    }

    private static Process? ProcessFromPidFile(string file, Process? fallback)
    {
        try
        {
            for (int i = 0; i < 10; i++)
            {
                if (File.Exists(file))
                {
                    if (int.TryParse(File.ReadAllText(file).Trim(), out int id))
                    {
                        return Process.GetProcessById(id);
                    }
                }

                Thread.Sleep(200);
            }
        }
        catch
        {
        }

        return fallback;
    }

    private static void TryKill(Process? process)
    {
        try
        {
            if (process is not null && !process.HasExited)
            {
                process.Kill();
                process.WaitForExit(3000);
            }
        }
        catch
        {
        }
    }
}
