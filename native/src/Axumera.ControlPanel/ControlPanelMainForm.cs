using Axumera.Core.Common;
using Axumera.Core.Server;
using Axumera.Ui;
using System.Text.Json;

namespace Axumera.ControlPanel;

/// <summary>
/// Native Axumera Control Panel shell. WebView2 renders the existing PHP
/// administration application (adminlogin.php). Server lifecycle control
/// belongs exclusively to Axumera.Server.exe - this shell never starts,
/// stops, or restarts the server.
/// </summary>
public sealed class ControlPanelMainForm : WebShellForm
{
    private readonly ServerConfiguration _configuration;

    public ControlPanelMainForm(StartupTelemetry telemetry)
        : this(LoadRuntimeConfiguration(), telemetry)
    {
    }

    internal ControlPanelMainForm(ServerConfiguration configuration, StartupTelemetry telemetry)
        : base("ControlPanel", "Axumera Control Panel", telemetry)
    {
        _configuration = configuration;
    }

    /// <summary>
    /// The admin shell downloads its own report files (results/report CSV)
    /// from the loopback runtime. Only the two approved report endpoints on an
    /// allowlisted origin are permitted; the Student shell never opts in.
    /// </summary>
    protected override bool AllowTrustedDownloads => true;

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);
        try
        {
            await InitializeWebAsync();
            var url = AdminPanelAddress.Login(_configuration);
            WebHost.NavigateToApplication(url);
            Telemetry.Mark("admin-login-navigation-requested");
            Telemetry.Mark("AXUMERA_SENTINEL_FORM_8B2E");
            SetStatus("Loading Axumera administrator login…");
        }
        catch (Exception ex)
        {
            FailStartup(ex,
                "Install or repair the Microsoft Edge WebView2 Runtime, then restart the Control Panel.");
        }
    }

    private static ServerConfiguration LoadRuntimeConfiguration()
    {
        var root = Environment.GetEnvironmentVariable("AXUMERA_RUNTIME_ROOT");
        if (string.IsNullOrWhiteSpace(root))
        {
            var controllerConfig = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "Axumera 2.0", "config", "server-controller.json");
            if (File.Exists(controllerConfig))
            {
                using var document = JsonDocument.Parse(File.ReadAllText(controllerConfig));
                if (document.RootElement.TryGetProperty("runtimeRoot", out var value))
                {
                    root = value.GetString();
                }
            }
        }

        if (string.IsNullOrWhiteSpace(root))
        {
            throw new InvalidOperationException("No Axumera runtime root is configured. Start Axumera Server or configure the native controller first.");
        }

        var result = ServerConfiguration.Load(root);
        if (!result.Succeeded || result.Value is null)
        {
            throw new InvalidOperationException(result.ErrorMessage);
        }

        return result.Value;
    }
}
