using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Axumera.Core.Paths;
using Axumera.Core.Server;
using Axumera.Server.Control;
using Axumera.Ui;

namespace Axumera.Server;

internal static class Program
{
    private const string ControllerConfigFile = "server-controller.json";

    [STAThread]
    private static int Main(string[] args)
    {
        bool headless = args.Any(a => string.Equals(a, "--headless", StringComparison.OrdinalIgnoreCase));

        var runtimeRoot = ResolveRuntimeRoot(args);
        if (runtimeRoot is null)
        {
            if (headless)
            {
                Console.WriteLine("FATAL No runtime root could be resolved (set AXUMERA_RUNTIME_ROOT, pass --runtime-root=<path>, or configure the controller config).");
            }
            else
            {
                MessageBox.Show(
                    "No Axumera runtime root could be resolved.\n\nSet the AXUMERA_RUNTIME_ROOT environment variable, pass --runtime-root=<path>, or configure " + ControllerConfigFile + " under the Axumera 2.0 app-data config folder.",
                    "Axumera Server",
                    MessageBoxButtons.OK,
                    MessageBoxIcon.Error);
            }

            return 2;
        }

        var load = ServerConfiguration.Load(runtimeRoot);
        if (!load.Succeeded)
        {
            if (headless)
            {
                Console.WriteLine("FATAL " + load.ErrorMessage);
            }
            else
            {
                MessageBox.Show(load.ErrorMessage, "Axumera Server", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }

            return 2;
        }

        if (headless)
        {
            return RunHeadless(load.Value, args);
        }

        return RunGui(load.Value);
    }

    // ------------------------------------------------------------- headless

    private static int RunHeadless(ServerConfiguration config, string[] args)
    {
        var command = args.SkipWhile(a => !string.Equals(a, "--headless", StringComparison.OrdinalIgnoreCase)).Skip(1).FirstOrDefault() ?? "status";
        using var controller = new ServerController(config);
        try
        {
            switch (command.ToLowerInvariant())
            {
                case "start":
                {
                    var result = controller.StartAsync().GetAwaiter().GetResult();
                    Console.WriteLine(result.Succeeded ? "STARTED" : "FAILED " + result.ErrorCode + " " + result.ErrorMessage);
                    return result.Succeeded ? 0 : 1;
                }
                case "stop":
                {
                    var result = controller.StopAsync().GetAwaiter().GetResult();
                    Console.WriteLine(result.Succeeded ? "STOPPED" : "FAILED " + result.ErrorCode + " " + result.ErrorMessage);
                    return result.Succeeded ? 0 : 1;
                }
                case "restart":
                {
                    var result = controller.RestartAsync().GetAwaiter().GetResult();
                    Console.WriteLine(result.Succeeded ? "RESTARTED" : "FAILED " + result.ErrorCode + " " + result.ErrorMessage);
                    return result.Succeeded ? 0 : 1;
                }
                case "status":
                {
                    var status = controller.GetStatusAsync().GetAwaiter().GetResult();
                    Console.WriteLine($"STATE {status.State} already-running={status.AlreadyRunning.ToString().ToLowerInvariant()} apache={status.Health.ApachePort} mariadb={status.Health.MariaDbPort}");
                    return 0;
                }
                case "health":
                {
                    var health = controller.GetHealthAsync().GetAwaiter().GetResult();
                    Console.WriteLine(JsonSerializer.Serialize(new
                    {
                        overall = health.OverallStatus.ToString(),
                        apache = health.Apache.State.ToString(),
                        mariaDb = health.MariaDb.State.ToString(),
                        php = health.Php.State.ToString(),
                        database = health.Database.State.ToString(),
                        lan = health.Lan.State.ToString(),
                        apachePort = health.ApachePort,
                        mariaDbPort = health.MariaDbPort,
                        serverIp = health.ServerIp,
                        error = health.ErrorMessage,
                    }));
                    return 0;
                }
                default:
                    Console.WriteLine("FATAL Unknown headless command: " + command + " (expected start|stop|restart|status|health)");
                    return 2;
            }
        }
        catch (Exception ex)
        {
            Console.WriteLine("FATAL " + ex.Message);
            return 3;
        }
    }

    // ------------------------------------------------------------- GUI

    private static int RunGui(ServerConfiguration config)
    {
        // Single instance: one controller per runtime root. A second instance
        // reports the existing state instead of starting anything.
        var mutexName = "Local\\AxumeraServerNative-" + Sha1Hex(config.InstallRoot);
        bool created;
        using (var mutex = new Mutex(true, mutexName, out created))
        {
            if (!created)
            {
                bool acquired = false;
                try
                {
                    acquired = mutex.WaitOne(0);
                }
                catch (AbandonedMutexException)
                {
                    acquired = true; // previous instance crashed; we may take ownership
                }

                if (!acquired)
                {
                    MessageBox.Show(
                        "Axumera Server is already running. The existing controller owns the runtime; no second instance was started.",
                        "Axumera Server",
                        MessageBoxButtons.OK,
                        MessageBoxIcon.Information);
                    return 0;
                }
            }

            Application.SetHighDpiMode(HighDpiMode.SystemAware);
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            using var telemetry = StartupTelemetry.Create("Axumera.Server");

            var splash = new AxumeraSplashForm("Axumera Server", "Server Controller");
            splash.Show();
            Application.DoEvents();
            telemetry.Mark("splash-shown");
            Thread.Sleep(1000);

            using var controller = new ServerController(config);
            var main = new ServerMainForm(controller, telemetry);
            main.Shown += (_, _) => telemetry.Mark("main-form-shown");
            main.FormClosed += (_, _) => telemetry.Mark("form-closed");
            splash.Close();
            Application.Run(main);
            telemetry.Mark("process-exited");
            return 0;
        }
    }

    // ------------------------------------------------------------- root

    /// <summary>
    /// Resolves the runtime root the controller manages. Order:
    /// 1) AXUMERA_RUNTIME_ROOT env var, 2) --runtime-root argument (both
    /// --runtime-root=&lt;path&gt; and --runtime-root &lt;path&gt; forms),
    /// 3) %LOCALAPPDATA%\Axumera 2.0\config\server-controller.json,
    /// 4) the executable directory (production-style layout).
    /// An explicitly passed --runtime-root that is malformed or points at a
    /// missing directory FAILS resolution rather than falling through to the
    /// config file, so a mistyped argument can never target the wrong runtime.
    /// </summary>
    private static string? ResolveRuntimeRoot(string[] args)
    {
        var env = Environment.GetEnvironmentVariable("AXUMERA_RUNTIME_ROOT");
        if (IsValidRoot(env))
        {
            return Path.GetFullPath(env!);
        }

        var rootArg = ControllerArgs.TryGetRuntimeRoot(args, out var rootPresent);
        if (rootPresent)
        {
            // Explicit but unusable: stop instead of silently re-targeting.
            if (!IsValidRoot(rootArg))
            {
                return null;
            }

            return Path.GetFullPath(rootArg!);
        }

        var configFile = Path.Combine(AppPaths.BaseDataDirectory, "config", ControllerConfigFile);
        try
        {
            if (File.Exists(configFile))
            {
                using var doc = JsonDocument.Parse(File.ReadAllText(configFile));
                if (doc.RootElement.TryGetProperty("runtimeRoot", out var root) && IsValidRoot(root.GetString()))
                {
                    return Path.GetFullPath(root.GetString()!);
                }
            }
        }
        catch (Exception)
        {
        }

        var exeDir = AppContext.BaseDirectory;
        return IsValidRoot(exeDir) ? Path.GetFullPath(exeDir) : null;
    }

    private static bool IsValidRoot(string? root) =>
        !string.IsNullOrWhiteSpace(root) && Directory.Exists(root);

    private static string Sha1Hex(string input)
    {
        var hash = SHA1.HashData(Encoding.UTF8.GetBytes(input));
        return Convert.ToHexString(hash).ToLowerInvariant();
    }
}
