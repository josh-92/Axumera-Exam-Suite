using System.Drawing;
using System.Reflection;
using Axumera.Core.Branding;
using Axumera.Core.Diagnostics;
using Axumera.Core.Ipc;
using Axumera.Core.Paths;
using Axumera.Core.Versioning;

namespace Axumera.Ui;

/// <summary>
/// Branded shell shared by the WebView2-based applications:
/// gold-accented header (logo + title + subtitle), the reusable
/// WebView2 host filling the content area, a native status bar, and the
/// copyright footer. No browser chrome (no address bar).
/// </summary>
public abstract class WebShellForm : Form
{
    private readonly Label _statusLabel;
    private readonly WebMessageChannel _channel = new();
    private readonly string _applicationKey;
    private Panel? _header;
    private Panel? _statusBar;
    private Label? _footer;

    protected AxumeraWebView2Host WebHost { get; }
    protected StartupTelemetry Telemetry { get; }
    protected string ApplicationName { get; }

    /// <summary>Header subtitle; shells override with their application-appropriate text.</summary>
    protected virtual string ShellSubtitle => "Administration & Exam Management";

    /// <summary>
    /// Opts the shell into downloads of the approved Axumera report endpoints.
    /// Defaults to false: the Student shell and every other consumer keep
    /// blocking all downloads.
    /// </summary>
    protected virtual bool AllowTrustedDownloads => false;

    protected WebShellForm(string applicationKey, string applicationName, StartupTelemetry telemetry)
    {
        _applicationKey = applicationKey;
        ApplicationName = applicationName;
        Telemetry = telemetry;

        Text = AxumeraBrand.ProductDisplayName(applicationName);
        Icon = EmbeddedAssets.LoadIcon(GetType().Assembly, $"{GetType().Assembly.GetName().Name}.Resources.app.ico");
        Size = new Size(1120, 740);
        MinimumSize = new Size(820, 560);
        StartPosition = FormStartPosition.CenterScreen;
        Theme.Apply(this);

        _header = BuildHeader();
        Controls.Add(_header);

        WebHost = new AxumeraWebView2Host { Dock = DockStyle.Fill };
        Controls.Add(WebHost);
        WebHost.BringToFront();

        _statusBar = new Panel
        {
            Dock = DockStyle.Bottom,
            Height = 34,
            BackColor = Theme.LightGray,
        };
        _statusLabel = new Label
        {
            Dock = DockStyle.Fill,
            Text = "Starting…",
            Font = Theme.CaptionFont,
            ForeColor = Theme.DeepNavy,
            TextAlign = ContentAlignment.MiddleLeft,
            Padding = new Padding(12, 0, 0, 0),
        };
        _statusBar.Controls.Add(_statusLabel);
        Controls.Add(_statusBar);

        _footer = new Label
        {
            Dock = DockStyle.Bottom,
            Height = 26,
            Text = $"{AxumeraBrand.CopyrightLine}   ·   {Axumera.Core.Versioning.ProductVersion.FullLabel}",
            Font = Theme.CaptionFont,
            ForeColor = Theme.Muted,
            BackColor = Theme.White,
            TextAlign = ContentAlignment.MiddleCenter,
        };
        Controls.Add(_footer);

        // Wire host events.
        _channel.Register(new PongHandler());
        WebHost.LoadingChanged += (_, loading) => SetStatus(loading ? "Loading…" : "Ready");
        WebHost.NavigationBlocked += (_, uri) => SetStatus($"Blocked navigation: {uri}");
        WebHost.PageLoaded += (_, uri) => OnPageLoaded(uri);
        WebHost.WebMessageJsonReceived += OnWebMessageJson;
    }

    private Panel BuildHeader()
    {
        var header = new Panel
        {
            Dock = DockStyle.Top,
            Height = 76,
            BackColor = Theme.White,
        };

        var accent = new Panel { Dock = DockStyle.Top, Height = 4, BackColor = Theme.Gold };
        header.Controls.Add(accent);

        var logo = new PictureBox
        {
            SizeMode = PictureBoxSizeMode.Zoom,
            Location = new Point(18, 18),
            Size = new Size(250, 44),
            BackColor = Theme.White,
            Image = EmbeddedAssets.LoadImage(GetType().Assembly, $"{GetType().Assembly.GetName().Name}.Resources.logo.png"),
        };
        header.Controls.Add(logo);

        var title = new Label
        {
            Text = ApplicationName,
            Font = Theme.TitleFont,
            ForeColor = Theme.DeepNavy,
            BackColor = Theme.White,
            Location = new Point(286, 12),
            Size = new Size(420, 26),
        };
        header.Controls.Add(title);

        var subtitle = new Label
        {
            Text = ShellSubtitle,
            Font = Theme.SubtitleFont,
            ForeColor = Theme.Muted,
            BackColor = Theme.White,
            Location = new Point(288, 42),
            Size = new Size(520, 18),
        };
        header.Controls.Add(subtitle);

        return header;
    }

    /// <summary>Hook for the page-loaded notification (subclasses observe navigation).</summary>
    protected virtual void OnPageLoaded(string uri)
    {
        Telemetry.Mark("page-loaded");
        SetStatus("Ready");
    }

    protected void SetStatus(string text)
    {
        if (_statusLabel.IsHandleCreated && _statusLabel.InvokeRequired)
        {
            _statusLabel.BeginInvoke(() => _statusLabel.Text = text);
        }
        else
        {
            _statusLabel.Text = text;
        }
    }

    /// <summary>
    /// Shows or hides the branded shell chrome (header, status bar, footer).
    /// The Student client hides it while kiosk mode is active so the exam gets
    /// the full display; the kiosk strip itself is managed by the shell.
    /// </summary>
    protected void SetShellChromeVisible(bool visible)
    {
        if (_header is not null)
        {
            _header.Visible = visible;
        }

        if (_statusBar is not null)
        {
            _statusBar.Visible = visible;
        }

        if (_footer is not null)
        {
            _footer.Visible = visible;
        }
    }

    /// <summary>Posts a JSON message to the hosted page (single JSON value).</summary>
    protected void PostJsonToPage(string json) => WebHost.PostJson(json);

    /// <summary>
    /// Shared startup-failure handling: logs the full exception with its
    /// stack trace and replaces the loading overlay with the concrete reason,
    /// so a failed WebView2 initialization never leaves the user staring at
    /// an unexplained Loading screen.
    /// </summary>
    protected void FailStartup(Exception ex, string? guidance = null)
    {
        Telemetry.Mark($"startup-failed: {ex.GetType().Name}: {ex.Message}");
        Telemetry.Log.Write(LogLevel.Error, "Startup", ex.ToString());
        var body = guidance ?? "Restart the application after resolving the issue shown above.";
        WebHost.ShowInitializationError(
            "The application could not start.\n\n" + ex.Message + "\n\n" + body);
        SetStatus("Startup failed — see the log for details.");
    }

    protected async Task InitializeWebAsync()
    {
        Telemetry.Mark("webview-init-started");
        await WebHost.InitializeAsync(
            AppPaths.WebView2UserDataDirectory(_applicationKey),
            Path.Combine(AppPaths.BaseDataDirectory, "web", _applicationKey),
            Telemetry.Log,
            _channel);
        Telemetry.Mark("webview-init-complete");
        WebHost.AllowTrustedDownloads = AllowTrustedDownloads;
        WebHost.LoadPlaceholder(ApplicationName, _applicationKey);
    }

    protected virtual void OnWebMessageJson(object? sender, string json)
    {
        var message = WebMessage.FromJson(json);
        if (message is { Type: "roundtrip-ok" })
        {
            Telemetry.Mark("message-roundtrip-ok");
        }
    }
}
