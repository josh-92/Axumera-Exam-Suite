using System.Drawing;
using System.Reflection;
using Axumera.Core.Branding;
using Axumera.Core.Common;
using Axumera.Core.Server;
using Axumera.Core.Versioning;
using Axumera.Ui;

namespace Axumera.Server;

/// <summary>
/// Axumera Server controller UI (Phase 2). Native WinForms — no WebView2 needed
/// for server control. The runtime keeps running when this window closes; the
/// administrator explicitly chooses STOP SERVER.
/// </summary>
public sealed class ServerMainForm : Form
{
    private static readonly Color StatusRunning = Color.FromArgb(0x1F, 0x7A, 0x3D);
    private static readonly Color StatusStarting = Theme.Gold;
    private static readonly Color StatusFailed = Color.FromArgb(0xB3, 0x26, 0x1E);
    private static readonly Color StatusStopped = Theme.Muted;

    private readonly IServerController _controller;
    private readonly StartupTelemetry _telemetry;
    private readonly System.Windows.Forms.Timer _refreshTimer;
    private readonly Label _statusDot = new() { Font = Theme.SectionFont, AutoSize = true };
    private readonly Label _statusText = new() { Font = Theme.TitleFont, AutoSize = true };
    private readonly Label _statusHint = new() { Font = Theme.CaptionFont, ForeColor = Theme.Muted, AutoSize = true };
    private readonly Button _startButton = CreateButton("START SERVER");
    private readonly Button _stopButton = CreateButton("STOP SERVER");
    private readonly Button _restartButton = CreateButton("RESTART");
    private readonly Button _diagnosticsButton = CreateButton("View Diagnostics");
    private readonly Dictionary<string, (Label Dot, Label Text)> _subsystemRows = new();
    private DiagnosticsForm? _diagnostics;
    private bool _closing;

    public ServerMainForm(IServerController controller, StartupTelemetry telemetry)
    {
        _controller = controller;
        _telemetry = telemetry;

        var config = controller.Configuration;

        Text = AxumeraBrand.ProductDisplayName("Server");
        Icon = EmbeddedAssets.LoadIcon(Assembly.GetExecutingAssembly(), "Axumera.Server.Resources.app.ico");
        Size = new Size(760, 720);
        MinimumSize = new Size(640, 600);
        StartPosition = FormStartPosition.CenterScreen;
        Theme.Apply(this);

        // Fill content panel FIRST (docked last => fills remaining space).
        var content = new Panel { Dock = DockStyle.Fill, BackColor = Theme.White, Padding = new Padding(28, 20, 28, 12) };
        Controls.Add(content);

        Controls.Add(new Panel { Dock = DockStyle.Top, Height = 4, BackColor = Theme.Gold });
        Controls.Add(BuildHeader());
        Controls.Add(BuildFooter());

        // ---- content: vertically flowing sections
        var flow = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            AutoScroll = true,
            BackColor = Theme.White,
        };
        content.Controls.Add(flow);

        // Status section.
        var statusRow = new FlowLayoutPanel { FlowDirection = FlowDirection.LeftToRight, WrapContents = false, AutoSize = true, Margin = new Padding(0, 0, 0, 4), BackColor = Theme.White };
        _statusDot.Text = "●";
        _statusDot.ForeColor = StatusStopped;
        statusRow.Controls.Add(_statusDot);
        _statusText.Text = "STOPPED";
        _statusText.ForeColor = Theme.DeepNavy;
        statusRow.Controls.Add(_statusText);
        flow.Controls.Add(statusRow);

        _statusHint.Text = "The server is stopped.";
        flow.Controls.Add(_statusHint);

        // Subsystem card.
        var subsystems = BuildCard("SERVER STATUS");
        foreach (var (key, label) in new[] { ("apache", "Apache"), ("maria", "MariaDB"), ("php", "PHP"), ("database", "Database"), ("lan", "LAN") })
        {
            var (dot, text) = AddSubsystemRow(subsystems, label);
            _subsystemRows[key] = (dot, text);
        }

        flow.Controls.Add(subsystems);

        // Info card.
        var info = BuildCard("CONFIGURATION");
        AddInfoRow(info, "HTTP Port", config.ApachePort.ToString());
        AddInfoRow(info, "Database Port", config.MariaDbPort.ToString());
        AddInfoRow(info, "Bind Address", config.BindAddress);
        AddInfoRow(info, "Server Address", HealthProbe.LanIpv4() ?? "127.0.0.1");
        AddInfoRow(info, "Runtime Root", config.InstallRoot);
        AddInfoRow(info, "Application", HealthProbe.ReadAppVersion(config.ApplicationRoot) is { } v ? "AXE " + v : "unknown");
        flow.Controls.Add(info);

        // Buttons.
        var buttons = new FlowLayoutPanel { FlowDirection = FlowDirection.LeftToRight, WrapContents = false, AutoSize = true, Margin = new Padding(0, 14, 0, 4), BackColor = Theme.White };
        buttons.Controls.Add(_startButton);
        buttons.Controls.Add(_stopButton);
        buttons.Controls.Add(_restartButton);
        buttons.Controls.Add(_diagnosticsButton);
        flow.Controls.Add(buttons);

        _startButton.Click += async (_, _) => await RunOperationAsync("Start", _controller.StartAsync);
        _stopButton.Click += async (_, _) => await RunOperationAsync("Stop", _controller.StopAsync);
        _restartButton.Click += async (_, _) => await RunOperationAsync("Restart", _controller.RestartAsync);
        _diagnosticsButton.Click += (_, _) => OpenDiagnostics();

        // Controller events -> UI thread.
        _controller.StateChanged += (_, state) => SafeInvoke(() => ApplyState(state));
        _controller.HealthUpdated += (_, health) => SafeInvoke(() => ApplyHealth(health));

        // Periodic refresh.
        _refreshTimer = new System.Windows.Forms.Timer { Interval = 2500 };
        _refreshTimer.Tick += async (_, _) => await RefreshAsync();
        _refreshTimer.Start();
    }

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);
        var already = _controller.IsServerAlreadyRunning();
        if (already)
        {
            _telemetry.Mark("detected-running-server");
            _statusHint.Text = "A running Axumera runtime was detected (started by a previous session).";
        }

        await RefreshAsync();
        ApplyState(already ? ServerState.Running : ServerState.Stopped);
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        _closing = true;
        // Deliberate design: closing the UI does NOT stop the server. The
        // administrator explicitly chooses STOP SERVER.
        _telemetry.Mark("ui-closing-server-keeps-running");
        _refreshTimer.Stop();
        base.OnFormClosing(e);
    }

    // ------------------------------------------------------------- operations

    private async Task RunOperationAsync(string name, Func<CancellationToken, Task<Result>> operation)
    {
        SetBusy(true);
        try
        {
            var result = await Task.Run(() => operation(CancellationToken.None));
            if (!result.Succeeded)
            {
                MessageBox.Show(result.ErrorMessage, $"{name} failed", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, $"{name} failed", MessageBoxButtons.OK, MessageBoxIcon.Error);
        }
        finally
        {
            SetBusy(false);
            await RefreshAsync();
        }
    }

    private async Task RefreshAsync()
    {
        try
        {
            var status = await Task.Run(() => _controller.GetStatusAsync(CancellationToken.None));
            SafeInvoke(() =>
            {
                ApplyState(status.State, status.AlreadyRunning);
                ApplyHealth(status.Health);
            });
        }
        catch (Exception ex)
        {
            _telemetry.Mark("refresh-failed: " + ex.Message);
        }
    }

    private void ApplyState(ServerState state, bool alreadyRunning = false)
    {
        (string text, Color color) = state switch
        {
            ServerState.Running => ("RUNNING", StatusRunning),
            ServerState.Starting => ("STARTING…", StatusStarting),
            ServerState.Stopping => ("STOPPING…", StatusStarting),
            ServerState.Failed => ("FAILED", StatusFailed),
            ServerState.Unknown => ("UNKNOWN", StatusStopped),
            _ => ("STOPPED", StatusStopped),
        };

        _statusDot.ForeColor = color;
        _statusText.Text = text + (state == ServerState.Running && alreadyRunning ? "  (detected)" : "");
        _statusHint.Text = state switch
        {
            ServerState.Running => "The server is ready for administrator and student connections.",
            ServerState.Starting => "Starting the Axumera runtime…",
            ServerState.Stopping => "Stopping the Axumera runtime…",
            ServerState.Failed => "The runtime is not healthy. Use View Diagnostics for details.",
            _ => "The server is stopped.",
        };

        bool busy = state is ServerState.Starting or ServerState.Stopping;
        _startButton.Enabled = !busy && state is ServerState.Stopped or ServerState.Failed or ServerState.Unknown;
        _stopButton.Enabled = !busy && state is ServerState.Running or ServerState.Failed;
        _restartButton.Enabled = !busy && state is ServerState.Running;
    }

    private void ApplyHealth(ServerHealth health)
    {
        SetSubsystem("apache", health.Apache);
        SetSubsystem("maria", health.MariaDb);
        SetSubsystem("php", health.Php);
        SetSubsystem("database", health.Database);
        SetSubsystem("lan", health.Lan);
    }

    private void SetSubsystem(string key, SubsystemHealth health)
    {
        if (!_subsystemRows.TryGetValue(key, out var row))
        {
            return;
        }

        row.Dot.ForeColor = health.State switch
        {
            SubsystemState.Healthy => StatusRunning,
            SubsystemState.Degraded => StatusStarting,
            SubsystemState.Failed => StatusFailed,
            SubsystemState.Starting => StatusStarting,
            _ => StatusStopped,
        };
        row.Text.Text = $"{health.State}  {health.Detail}";
    }

    private void SetBusy(bool busy)
    {
        _startButton.Enabled = !busy;
        _stopButton.Enabled = !busy;
        _restartButton.Enabled = !busy;
    }

    private void OpenDiagnostics()
    {
        if (_diagnostics is null || _diagnostics.IsDisposed)
        {
            _diagnostics = new DiagnosticsForm(_controller.Configuration.ServerLogFile);
        }

        _diagnostics.Show();
        _diagnostics.BringToFront();
    }

    private void SafeInvoke(Action action)
    {
        if (_closing || IsDisposed)
        {
            return;
        }

        if (IsHandleCreated && InvokeRequired)
        {
            BeginInvoke(action);
        }
        else
        {
            action();
        }
    }

    // ------------------------------------------------------------- layout

    private Panel BuildHeader()
    {
        var header = new Panel { Dock = DockStyle.Top, Height = 76, BackColor = Theme.White };
        var logo = new PictureBox
        {
            SizeMode = PictureBoxSizeMode.Zoom,
            Location = new Point(18, 16),
            Size = new Size(250, 44),
            BackColor = Theme.White,
            Image = EmbeddedAssets.LoadImage(Assembly.GetExecutingAssembly(), "Axumera.Server.Resources.logo.png"),
        };
        header.Controls.Add(logo);
        var title = new Label
        {
            Text = "Axumera Server",
            Font = Theme.TitleFont,
            ForeColor = Theme.DeepNavy,
            Location = new Point(286, 12),
            Size = new Size(320, 26),
        };
        header.Controls.Add(title);
        var subtitle = new Label
        {
            Text = "Server Controller",
            Font = Theme.SubtitleFont,
            ForeColor = Theme.Muted,
            Location = new Point(288, 42),
            Size = new Size(360, 18),
        };
        header.Controls.Add(subtitle);
        return header;
    }

    private Label BuildFooter()
    {
        var footer = new Label
        {
            Dock = DockStyle.Bottom,
            Height = 26,
            Text = $"{AxumeraBrand.CopyrightLine}   ·   {Axumera.Core.Versioning.ProductVersion.FullLabel}",
            Font = Theme.CaptionFont,
            ForeColor = Theme.Muted,
            TextAlign = ContentAlignment.MiddleCenter,
        };
        return footer;
    }

    private static Panel BuildCard(string title)
    {
        var card = new Panel
        {
            AutoSize = true,
            BackColor = Theme.LightGray,
            Margin = new Padding(0, 10, 0, 0),
            Padding = new Padding(16, 8, 16, 10),
            MinimumSize = new Size(560, 0),
        };
        var heading = new Label
        {
            Text = title,
            Font = Theme.CaptionFont,
            ForeColor = Theme.Muted,
            AutoSize = true,
            Margin = new Padding(0, 0, 0, 6),
        };
        var flow = new FlowLayoutPanel
        {
            FlowDirection = FlowDirection.TopDown,
            WrapContents = false,
            AutoSize = true,
            BackColor = Theme.LightGray,
        };
        flow.Controls.Add(heading);
        card.Controls.Add(flow);
        return card;
    }

    private static (Label Dot, Label Text) AddSubsystemRow(Panel card, string name)
    {
        var flow = (FlowLayoutPanel)card.Controls[0];
        var row = new FlowLayoutPanel { FlowDirection = FlowDirection.LeftToRight, WrapContents = false, AutoSize = true, BackColor = Theme.LightGray };
        var dot = new Label { Text = "●", ForeColor = StatusStopped, AutoSize = true, Margin = new Padding(0, 3, 6, 0), Font = Theme.BodyFont };
        var nameLabel = new Label { Text = name, Font = Theme.BodyFont, ForeColor = Theme.DeepNavy, AutoSize = true, Width = 90 };
        var text = new Label { Text = "Unknown", Font = Theme.BodyFont, ForeColor = Theme.Muted, AutoSize = true, MinimumSize = new Size(300, 0) };
        row.Controls.Add(dot);
        row.Controls.Add(nameLabel);
        row.Controls.Add(text);
        flow.Controls.Add(row);
        return (dot, text);
    }

    private static void AddInfoRow(Panel card, string name, string value)
    {
        var flow = (FlowLayoutPanel)card.Controls[0];
        var row = new FlowLayoutPanel { FlowDirection = FlowDirection.LeftToRight, WrapContents = false, AutoSize = true, BackColor = Theme.LightGray };
        var nameLabel = new Label { Text = name, Font = Theme.BodyFont, ForeColor = Theme.DeepNavy, AutoSize = true, Width = 130 };
        var valueLabel = new Label { Text = value, Font = Theme.BodyFont, ForeColor = Theme.Muted, AutoSize = true, MinimumSize = new Size(300, 0) };
        row.Controls.Add(nameLabel);
        row.Controls.Add(valueLabel);
        flow.Controls.Add(row);
    }

    private static Button CreateButton(string text) => new()
    {
        Text = text,
        Width = 128,
        Height = 34,
        FlatStyle = FlatStyle.Flat,
        BackColor = Theme.LightGray,
        ForeColor = Theme.DeepNavy,
        Margin = new Padding(0, 0, 10, 0),
    };
}
