using System.Diagnostics;
using System.Threading;
using Axumera.Core.Common;
using Axumera.Core.Diagnostics;
using Axumera.Core.Server;

namespace Axumera.Server.Control;

/// <summary>
/// The native Axumera server controller. Owns the runtime lifecycle exactly as
/// the proven production controller does (validate → MariaDB ready → Apache
/// ready → health → state file → monitor), but behind a clean, testable,
/// event-driven architecture. It never starts a second runtime on top of a live
/// one, never kills unrelated processes, and never touches the production
/// installation (it is bound to the configured, validated runtime root).
/// </summary>
public sealed class ServerController : IServerController, IDisposable
{
    private readonly ServerConfiguration _config;
    private readonly FileLogSink _log;
    private readonly ServiceProcessManager _processes;
    private readonly object _gate = new();
    private CancellationTokenSource? _monitorCts;
    private DateTimeOffset? _startedUtc;
    private ServerState _state = ServerState.Stopped;
    private ServerHealth _lastHealth = ServerHealth.Unknown(0, 0);

    public event EventHandler<ServerState>? StateChanged;
    public event EventHandler<ServerHealth>? HealthUpdated;

    public ServerConfiguration Configuration => _config;

    public ServerController(ServerConfiguration config, string? logFile = null)
    {
        _config = config;
        Directory.CreateDirectory(config.LogsRoot);
        _log = new FileLogSink(logFile ?? config.ServerLogFile);
        AppLog.Sink = _log;
        _processes = new ServiceProcessManager(config, Log);
    }

    public ServerState State => _state;

    // ------------------------------------------------------------- public API

    public async Task<Result> StartAsync(CancellationToken cancellationToken = default)
    {
        lock (_gate)
        {
            if (_state is ServerState.Starting or ServerState.Stopping)
            {
                return Result.Fail("start.busy", $"A {_state.ToString().ToLowerInvariant()} operation is already in progress.");
            }

            if (IsServerAlreadyRunning())
            {
                _state = ServerState.Running;
                StateChanged?.Invoke(this, _state);
                return Result.Fail("start.already-running", "The Axumera runtime is already running. No duplicate instance was started.");
            }

            _state = ServerState.Starting;
        }

        StateChanged?.Invoke(this, _state);
        Log("Start requested.");

        try
        {
            // 1. Validate configuration + runtime files.
            var issues = RuntimeValidator.Validate(_config);
            var fatal = issues.Where(i => i.Fatal).ToList();
            if (fatal.Count > 0)
            {
                return FailStart("runtime validation", BuildValidationMessage(fatal));
            }

            Log("Runtime validation passed.");

            // 2. Port conflict check — refuse, never kill.
            var conflict = FindPortConflict();
            if (conflict is not null)
            {
                return FailStart("port conflict", conflict);
            }

            // 3. Generate the proven runtime configuration.
            RuntimeConfigWriter.Write(_config, Log);

            // 4. Ensure the MariaDB data directory exists (first run only).
            EnsureMariaDbData();

            // 5. MariaDB.
            Log("Starting MariaDB...");
            _processes.StartMariaDb();
            if (!_processes.WaitForMariaDbReady(cancellationToken: cancellationToken))
            {
                var tail = ReadTail(Path.Combine(_config.LogsRoot, "mariadb-error.log"), 5);
                return FailStart("MariaDB", "MariaDB did not become ready (mysqladmin ping failed)." + tail, rollback: true);
            }

            Log("MariaDB ready.");
            await PublishHealthAsync(cancellationToken).ConfigureAwait(false);

            // 6. Apache.
            Log("Starting Apache...");
            _processes.StartApache();
            if (!_processes.WaitForApachePort(cancellationToken: cancellationToken))
            {
                var tail = ReadTail(Path.Combine(_config.LogsRoot, "apache-error.log"), 5);
                return FailStart("Apache", "Apache did not start listening on port " + _config.ApachePort + "." + tail, rollback: true);
            }

            Log($"Apache ready (port {_config.ApachePort}).");

            // 7. Health endpoint (proves PHP + database through the app).
            var healthy = await HealthProbe.HttpHealthyAsync(_config.HealthUrl, TimeSpan.FromSeconds(15)).ConfigureAwait(false);
            if (!healthy)
            {
                return FailStart("health check", "The Axumera health endpoint did not return a healthy response.", rollback: true);
            }

            Log("Health check passed.");

            // 8. Persist state, mark running, start the crash monitor.
            StateFile.Write(
                _config.StateFile,
                _processes.MariaDbProcess?.Id ?? 0,
                _processes.ApacheProcess?.Id ?? 0);
            _startedUtc = DateTimeOffset.UtcNow;

            lock (_gate)
            {
                _state = ServerState.Running;
            }

            StateChanged?.Invoke(this, _state);
            Log("READY maria=" + (_processes.MariaDbProcess?.Id ?? 0) + " apache=" + (_processes.ApacheProcess?.Id ?? 0));
            await PublishHealthAsync(cancellationToken).ConfigureAwait(false);
            StartMonitor();
            return Result.Ok();
        }
        catch (OperationCanceledException)
        {
            return FailStart("cancelled", "Start was cancelled.", rollback: true);
        }
        catch (Exception ex)
        {
            return FailStart("startup", ex.Message, rollback: true);
        }
    }

    public async Task<Result> StopAsync(CancellationToken cancellationToken = default)
    {
        lock (_gate)
        {
            if (_state is ServerState.Starting or ServerState.Stopping)
            {
                return Result.Fail("stop.busy", $"A {_state.ToString().ToLowerInvariant()} operation is already in progress.");
            }

            _state = ServerState.Stopping;
        }

        StateChanged?.Invoke(this, _state);
        Log("Stop requested.");

        StopMonitor();

        try
        {
            // Apache first (stop accepting traffic), then MariaDB. When this
            // controller instance did not start the runtime (started by an
            // earlier session), stop via the state file's PIDs — that state is
            // our own runtime, so terminating it is legitimate.
            if (_processes.HasLiveChildren())
            {
                _processes.StopApache();
                Log("Apache stopped (owned child).");
                _processes.StopMariaDb();
                Log("MariaDB stopped (owned child).");
            }
            else
            {
                StopFromStateFile();
            }

            // Wait for ports to actually free.
            for (int i = 0; i < 20 && (PortProbe.IsListening(_config.ApachePort) || PortProbe.IsListening(_config.MariaDbPort)); i++)
            {
                cancellationToken.ThrowIfCancellationRequested();
                Thread.Sleep(500);
            }

            if (PortProbe.IsListening(_config.ApachePort) || PortProbe.IsListening(_config.MariaDbPort))
            {
                return Result.Fail("stop.ports-busy", "Stop completed but a configured port is still listening.");
            }

            StateFile.Clear(_config.StateFile);
            _startedUtc = null;

            lock (_gate)
            {
                _state = ServerState.Stopped;
            }

            StateChanged?.Invoke(this, _state);
            Log("Stop completed; all ports free.");
            await PublishHealthAsync(cancellationToken).ConfigureAwait(false);
            return Result.Ok();
        }
        catch (OperationCanceledException)
        {
            lock (_gate)
            {
                _state = ServerState.Failed;
            }

            StateChanged?.Invoke(this, _state);
            return Result.Fail("stop.cancelled", "Stop was cancelled.");
        }
        catch (Exception ex)
        {
            lock (_gate)
            {
                _state = ServerState.Failed;
            }

            StateChanged?.Invoke(this, _state);
            Log("ERROR during stop: " + ex.Message);
            return Result.Fail("stop.error", ex.Message);
        }
    }

    public async Task<Result> RestartAsync(CancellationToken cancellationToken = default)
    {
        Log("Restart requested.");
        var stop = await StopAsync(cancellationToken).ConfigureAwait(false);
        if (!stop.Succeeded)
        {
            return stop;
        }

        return await StartAsync(cancellationToken).ConfigureAwait(false);
    }

    public Task<ServerStatus> GetStatusAsync(CancellationToken cancellationToken = default)
    {
        var health = ComputeHealth();
        bool externallyRunning = _state is ServerState.Stopped or ServerState.Unknown && IsServerAlreadyRunning();
        return Task.FromResult(new ServerStatus
        {
            State = externallyRunning ? ServerState.Running : _state,
            Health = health,
            StartedUtc = _startedUtc,
            AlreadyRunning = externallyRunning,
        });
    }

    public async Task<ServerHealth> GetHealthAsync(CancellationToken cancellationToken = default)
    {
        var health = ComputeHealth();
        HealthUpdated?.Invoke(this, health);
        return health;
    }

    public bool IsServerAlreadyRunning()
    {
        bool stateFileLive = StateFile.IsLive(
            _config.StateFile,
            pid => PidAlive(pid),
            () => PortProbe.IsListening(_config.ApachePort),
            () => PortProbe.IsListening(_config.MariaDbPort),
            _config.ApachePort,
            _config.MariaDbPort);

        // Strong attribution without state file: both services up AND the app
        // health endpoint answers — that combination is our stack, not a stray service.
        bool bothPortsAndHealth =
            PortProbe.IsListening(_config.ApachePort)
            && PortProbe.IsListening(_config.MariaDbPort)
            && HealthProbe.HttpHealthyAsync(_config.HealthUrl, TimeSpan.FromSeconds(3)).GetAwaiter().GetResult();

        return stateFileLive || bothPortsAndHealth;
    }

    // ------------------------------------------------------------- internals

    private Result FailStart(string stage, string message, bool rollback = false)
    {
        Log($"ERROR [{stage}]: {message}");
        if (rollback)
        {
            Log("Rolling back started services.");
            _processes.StopApache();
            _processes.StopMariaDb();
            StateFile.Clear(_config.StateFile);
            _startedUtc = null;
        }

        lock (_gate)
        {
            _state = ServerState.Failed;
        }

        StateChanged?.Invoke(this, _state);
        return Result.Fail("start." + stage.Replace(" ", "-"), message);
    }

    private void StartMonitor()
    {
        lock (_gate)
        {
            StopMonitor();
            _monitorCts = new CancellationTokenSource();
        }

        var token = _monitorCts.Token;
        _ = Task.Run(async () =>
        {
            while (!token.IsCancellationRequested)
            {
                await Task.Delay(2000, token).ConfigureAwait(false);

                bool apacheUp = PortProbe.IsListening(_config.ApachePort);
                bool mariaUp = HealthProbe.MariaDbReady(_config.MySqlExe, _config.MariaDbPort);
                bool stillRunning;
                lock (_gate)
                {
                    stillRunning = _state == ServerState.Running;
                }

                if (!stillRunning)
                {
                    return;
                }

                if (!apacheUp || !mariaUp)
                {
                    Log($"Runtime child failure detected (apacheUp={apacheUp}, mariaUp={mariaUp}); shutting down cleanly.");
                    _processes.StopApache();
                    _processes.StopMariaDb();
                    StateFile.Clear(_config.StateFile);
                    _startedUtc = null;

                    lock (_gate)
                    {
                        _state = ServerState.Failed;
                    }

                    StateChanged?.Invoke(this, _state);
                    Log("Cleanup after unexpected child exit completed.");
                    return;
                }
            }
        }, token);
    }

    private void StopMonitor()
    {
        lock (_gate)
        {
            _monitorCts?.Cancel();
            _monitorCts?.Dispose();
            _monitorCts = null;
        }
    }

    private ServerHealth ComputeHealth()
    {
        bool apacheListening = PortProbe.IsListening(_config.ApachePort);
        bool mariaPing = HealthProbe.MariaDbReady(_config.MySqlExe, _config.MariaDbPort);
        bool healthOk = HealthProbe.HttpHealthyAsync(_config.HealthUrl, TimeSpan.FromSeconds(4)).GetAwaiter().GetResult();
        string? lanIp = HealthProbe.LanIpv4();

        var apache = apacheListening ? SubsystemHealth.Healthy($"listening on {_config.BindAddress}:{_config.ApachePort}") : SubsystemHealth.Stopped();
        var maria = mariaPing ? SubsystemHealth.Healthy($"accepting connections on port {_config.MariaDbPort}") : SubsystemHealth.Stopped();
        var php = healthOk ? SubsystemHealth.Healthy("PHP module responding via health endpoint") : apacheListening ? SubsystemHealth.Degraded("Apache up but the PHP health endpoint is not OK") : SubsystemHealth.Stopped();
        var database = mariaPing ? SubsystemHealth.Healthy("database ping OK") : SubsystemHealth.Failed("no database connection");
        var lan = lanIp is not null ? SubsystemHealth.Healthy(lanIp) : SubsystemHealth.Degraded("loopback only");

        // A fresh controller instance may be inspecting a runtime launched by
        // an earlier instance. In that case the observed, attributable runtime
        // state is authoritative even though this instance's local state begins
        // as Stopped.
        var runtimeRunning = _state == ServerState.Running || IsServerAlreadyRunning();
        var overall = runtimeRunning && healthOk && apacheListening && mariaPing
            ? ServerState.Running
            : _state;

        _lastHealth = new ServerHealth
        {
            OverallStatus = overall,
            Apache = apache,
            MariaDb = maria,
            Php = php,
            Database = database,
            Lan = lan,
            ApachePort = _config.ApachePort,
            MariaDbPort = _config.MariaDbPort,
            ServerIp = lanIp,
            AppVersion = HealthProbe.ReadAppVersion(_config.ApplicationRoot),
            LastChecked = DateTimeOffset.UtcNow,
            ErrorMessage = null,
        };

        return _lastHealth;
    }

    private async Task PublishHealthAsync(CancellationToken cancellationToken)
    {
        var health = await Task.Run(() => ComputeHealth(), cancellationToken).ConfigureAwait(false);
        HealthUpdated?.Invoke(this, health);
    }

    private string? FindPortConflict()
    {
        foreach (var (label, port) in new[] { ("Apache", _config.ApachePort), ("MariaDB", _config.MariaDbPort) })
        {
            if (PortProbe.IsListening(port))
            {
                var pid = PortProbe.GetOwningProcessId(port);
                string owner = pid is null ? "unknown process" : DescribeProcess(pid.Value);
                return $"{label} port {port} is already in use by {owner}. The controller refused to start and did not terminate the owner.";
            }
        }

        return null;
    }

    private static string DescribeProcess(int pid)
    {
        try
        {
            using var process = Process.GetProcessById(pid);
            return $"PID {pid} ({process.ProcessName})";
        }
        catch
        {
            return $"PID {pid}";
        }
    }

    private void EnsureMariaDbData()
    {
        if (Directory.Exists(_config.MariaDbDataRoot) && Directory.GetFileSystemEntries(_config.MariaDbDataRoot).Length != 0)
        {
            Log("MariaDB data directory reused (non-empty); no initialization needed.");
            return;
        }

        // First run: initialize like the production controller's setup mode.
        var tool = File.Exists(_config.MariaInitTool)
            ? _config.MariaInitTool
            : Path.Combine(_config.RuntimeRoot, "mariadb", "bin", "mariadb-install-db.exe");

        if (!File.Exists(tool))
        {
            throw new InvalidOperationException("The MariaDB data directory is empty and no initializer (mysql_install_db.exe / mariadb-install-db.exe) is available.");
        }

        Directory.CreateDirectory(_config.MariaDbDataRoot);
        Log("Initializing MariaDB data directory...");
        using var process = Process.Start(new ProcessStartInfo(tool, $"--datadir=\"{_config.MariaDbDataRoot}\" --port={_config.MariaDbPort} --silent")
        {
            UseShellExecute = false,
            CreateNoWindow = true,
        });
        process!.WaitForExit();
        if (process.ExitCode != 0)
        {
            throw new InvalidOperationException($"MariaDB data initialization failed (exit code {process.ExitCode}).");
        }

        Log("MariaDB data directory initialized.");
    }

    /// <summary>Stops a runtime started by an earlier controller session (graceful, then PID fallback).</summary>
    private void StopFromStateFile()
    {
        var contents = StateFile.TryRead(_config.StateFile);

        try
        {
            Process.Start(new ProcessStartInfo(_config.ApacheExe, "-f \"" + _config.ApacheConfigFile + "\" -k shutdown")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                WorkingDirectory = _config.InstallRoot,
            })?.WaitForExit(5000);
        }
        catch
        {
        }

        try
        {
            Process.Start(new ProcessStartInfo(_config.MySqlAdminExe, $"--protocol=tcp -h 127.0.0.1 -P {_config.MariaDbPort} shutdown")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                WorkingDirectory = _config.InstallRoot,
            })?.WaitForExit(5000);
        }
        catch
        {
        }

        if (contents is not null)
        {
            TryKillPid(contents.ApachePid);
            TryKillPid(contents.MariaDbPid);
        }

        Log("Stopped runtime started by a previous session (state-file PIDs).");
    }

    private static void TryKillPid(int pid)
    {
        if (pid <= 0)
        {
            return;
        }

        try
        {
            using var process = Process.GetProcessById(pid);
            if (!process.HasExited)
            {
                process.Kill();
                process.WaitForExit(3000);
            }
        }
        catch
        {
        }
    }

    private static bool PidAlive(int pid)
    {
        try
        {
            using var process = Process.GetProcessById(pid);
            return !process.HasExited;
        }
        catch
        {
            return false;
        }
    }

    private static string BuildValidationMessage(IEnumerable<ValidationIssue> issues) =>
        "Runtime validation failed: " + string.Join("; ", issues.Select(i => $"{i.Label} missing at {i.Path}"));

    private static string ReadTail(string file, int lines)
    {
        try
        {
            if (!File.Exists(file))
            {
                return " (no error log yet)";
            }

            var tail = File.ReadLines(file).TakeLast(lines).ToList();
            return tail.Count > 0 ? " Last log lines: " + string.Join(" | ", tail) : " (empty error log)";
        }
        catch
        {
            return string.Empty;
        }
    }

    private void Log(string message) => _log.Write(LogLevel.Info, "ServerController", message);

    public void Dispose()
    {
        StopMonitor();
        _log.Dispose();
    }
}
