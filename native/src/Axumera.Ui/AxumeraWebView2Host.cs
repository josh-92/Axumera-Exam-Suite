using System.ComponentModel;
using Axumera.Core.Diagnostics;
using Axumera.Core.Ipc;
using Axumera.Core.Security;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace Axumera.Ui;

/// <summary>
/// Reusable WebView2 host for the Axumera shell applications.
///
/// Scope:
///  - loads the embedded safe placeholder via a virtual host mapping, or a
///    single approved Axumera application origin
///  - enforces a strict origin allowlist (loopback http(s) + the placeholder
///    host + the one configured application origin)
///  - session isolation via a per-application user-data folder
///  - loading states, error handling, and native ↔ web messaging
///  - hardened settings: no devtools, no context menus, no zoom, no external
///    drops, no swipe navigation, no browser accelerator keys, no default
///    script dialogs (alert/confirm/prompt)
///  - blocks new windows (NewWindowRequested), arbitrary external navigation,
///    non-http(s) schemes (mailto:, file:, custom protocols), and all
///    downloads (DownloadStarting)
///
/// NOT a general-purpose browser. The caller decides the single application
/// origin; everything else is cancelled.
/// </summary>
public sealed class AxumeraWebView2Host : UserControl
{
    public const string PlaceholderHost = "axumera.dev";

    private readonly WebView2 _webView = new() { Dock = DockStyle.Fill };
    private readonly Panel _overlay = new() { Dock = DockStyle.Fill, BackColor = Theme.White };
    private readonly Label _overlayText = new()
    {
        Text = "Loading…",
        ForeColor = Theme.DeepNavy,
        Font = Theme.BodyFont,
        TextAlign = ContentAlignment.MiddleCenter,
        Dock = DockStyle.Fill,
    };

    private CoreWebView2? _core;
    private readonly List<string> _allowedOrigins = new();
    private string _webRootFolder = string.Empty;
    private ILogSink? _log;
    private WebMessageChannel? _channel;
    private string _lastNavigationUri = string.Empty;

    public event EventHandler<bool>? LoadingChanged;
    public event EventHandler<string>? PageLoaded;
    public event EventHandler<string>? NavigationBlocked;
    public event EventHandler<string>? WebMessageJsonReceived;

    /// <summary>
    /// Replaces the loading overlay with the concrete startup failure. The
    /// real reason is shown (never an unexplained Loading screen), and the
    /// overlay keeps masking the blank webview behind it.
    /// </summary>
    public void ShowInitializationError(string detail)
    {
        _overlayText.Text = detail;
        _overlayText.ForeColor = Color.FromArgb(180, 40, 40);
        _overlay.Visible = true;
        _overlay.BringToFront();
    }

    /// <summary>
    /// When true, downloads of the approved Axumera report endpoints
    /// (<see cref="TrustedDownloadPolicy.ApprovedPaths"/>) from an allowed
    /// origin are permitted. Everything else stays cancelled. The Student
    /// shell never sets this, so exam clients keep blocking all downloads.
    /// </summary>
    [Browsable(false)]
    [DesignerSerializationVisibility(DesignerSerializationVisibility.Hidden)]
    public bool AllowTrustedDownloads { get; set; }

    public AxumeraWebView2Host()
    {
        _overlay.Controls.Add(_overlayText);
        Controls.Add(_webView);
        Controls.Add(_overlay);
        _overlay.BringToFront();
        _overlay.Visible = true;

        // Strict allowlist: loopback http(s) for the future admin/exam UI and
        // the virtual placeholder host. Everything else is blocked.
        _allowedOrigins.Add("http://127.0.0.1");
        _allowedOrigins.Add("http://localhost");
        _allowedOrigins.Add("https://" + PlaceholderHost);
    }

    public async Task InitializeAsync(string userDataFolder, string webRootFolder, ILogSink? log = null, WebMessageChannel? channel = null)
    {
        _log = log;
        _channel = channel;
        _webRootFolder = webRootFolder;

        Directory.CreateDirectory(userDataFolder);
        Directory.CreateDirectory(webRootFolder);

        var environment = await CoreWebView2Environment.CreateAsync(null, userDataFolder);
        await _webView.EnsureCoreWebView2Async(environment);
        _core = _webView.CoreWebView2;

        _core.Settings.AreDevToolsEnabled = false;
        _core.Settings.AreDefaultContextMenusEnabled = false;
        _core.Settings.IsStatusBarEnabled = false;
        _core.Settings.IsZoomControlEnabled = false;
        _core.Settings.AreBrowserAcceleratorKeysEnabled = false;
        _core.Settings.AreHostObjectsAllowed = false;
        _core.Settings.IsSwipeNavigationEnabled = false;
        // The AXE 2.0 application never uses alert()/confirm()/prompt() (its
        // dialogs are in-page), so the default JS dialog boxes are suppressed:
        // a compromised page cannot spam modal dialogs or use one as a focus
        // escape.
        _core.Settings.AreDefaultScriptDialogsEnabled = false;
        _webView.AllowExternalDrop = false;

        _core.NavigationStarting += OnNavigationStarting;
        _core.NavigationCompleted += OnNavigationCompleted;
        _core.WebMessageReceived += OnWebMessageReceived;
        _core.NewWindowRequested += OnNewWindowRequested;
        _core.DownloadStarting += OnDownloadStarting;

        _log?.Write(LogLevel.Info, "WebView2Host", "Initialized.");
    }

    /// <summary>Loads the safe development placeholder (the only content in Phase 1).</summary>
    public void LoadPlaceholder(string applicationName, string applicationKey)
    {
        if (_core is null)
        {
            throw new InvalidOperationException("InitializeAsync must complete before loading content.");
        }

        var html = PlaceholderPage.Build(applicationName, applicationKey);
        File.WriteAllText(Path.Combine(_webRootFolder, "index.html"), html);
        _core.SetVirtualHostNameToFolderMapping(PlaceholderHost, _webRootFolder, CoreWebView2HostResourceAccessKind.DenyCors);
        _core.Navigate($"https://{PlaceholderHost}/index.html");
        _log?.Write(LogLevel.Info, "WebView2Host", $"Navigating to https://{PlaceholderHost}/index.html");
    }

    /// <summary>
    /// Navigates to the one approved Axumera application origin. This is not a
    /// general browser allowlist: the exact scheme, host, and port are added
    /// for the native-managed runtime only. The Control Panel uses the
    /// loopback runtime; the Student client may pass <paramref name="allowAnyHttpHost"/>
    /// so it can connect to the configured school server (a LAN address such as
    /// 192.168.1.11) — the origin is still added verbatim, never wildcarded.
    /// </summary>
    public void NavigateToApplication(Uri applicationUri, bool allowAnyHttpHost = false)
    {
        if (_core is null)
        {
            throw new InvalidOperationException("InitializeAsync must complete before loading content.");
        }

        if (!string.Equals(applicationUri.Scheme, Uri.UriSchemeHttp, StringComparison.OrdinalIgnoreCase)
            || (!allowAnyHttpHost && !string.Equals(applicationUri.Host, "127.0.0.1", StringComparison.Ordinal)))
        {
            throw new InvalidOperationException("The Axumera application URL must use a loopback HTTP origin (Control Panel) or an explicitly allowed HTTP origin (Student client).");
        }

        AddAllowedOrigin(applicationUri.GetLeftPart(UriPartial.Authority));
        _core.Navigate(applicationUri.AbsoluteUri);
        _log?.Write(LogLevel.Info, "WebView2Host", $"Navigating to approved Axumera application URL: {applicationUri}");
    }

    /// <summary>Adds an exact origin (scheme://host:port) to the navigation allowlist.</summary>
    public void AddAllowedOrigin(string origin)
    {
        if (!_allowedOrigins.Any(o => string.Equals(o, origin, StringComparison.OrdinalIgnoreCase)))
        {
            _allowedOrigins.Add(origin);
        }
    }

    /// <summary>Posts a JSON string to the web content (must be a single JSON value).</summary>
    public void PostJson(string json) => _core?.PostWebMessageAsJson(json);

    private void OnNavigationStarting(object? sender, CoreWebView2NavigationStartingEventArgs e)
    {
        if (!IsAllowed(e.Uri))
        {
            e.Cancel = true;
            _log?.Write(LogLevel.Warn, "WebView2Host", $"Blocked navigation to {e.Uri}");
            NavigationBlocked?.Invoke(this, e.Uri);
            return;
        }

        _overlay.Visible = true;
        _overlayText.Text = "Loading…";
        _lastNavigationUri = e.Uri;
        LoadingChanged?.Invoke(this, true);
    }

    private bool IsAllowed(string uriString)
    {
        if (!Uri.TryCreate(uriString, UriKind.Absolute, out var uri))
        {
            return false;
        }

        // http(s) only — mailto:, file:, javascript:, and arbitrary custom
        // schemes are never reachable from inside the exam shell.
        if (uri.Scheme is not ("http" or "https"))
        {
            return false;
        }

        var origin = uri.GetLeftPart(UriPartial.Authority);
        return _allowedOrigins.Any(o => string.Equals(o, origin, StringComparison.OrdinalIgnoreCase));
    }

    private void OnNewWindowRequested(object? sender, CoreWebView2NewWindowRequestedEventArgs e)
    {
        // window.open / target=_blank never opens a second browser window.
        e.Handled = true;
        _log?.Write(LogLevel.Warn, "WebView2Host", $"Blocked new-window request for {e.Uri}");
        NavigationBlocked?.Invoke(this, e.Uri);
    }

    private void OnDownloadStarting(object? sender, CoreWebView2DownloadStartingEventArgs e)
    {
        // Default: the exam shell never writes downloaded files to disk - a
        // compromised page cannot exfiltrate content via a <a download> or
        // Content-Disposition attachment. Cancelling here also suppresses the
        // default download UI.
        var uri = e.DownloadOperation?.Uri ?? string.Empty;
        if (TrustedDownloadPolicy.IsApprovedDownload(uri, _allowedOrigins, AllowTrustedDownloads))
        {
            // The admin shell's report downloads (results/report CSV). The
            // navigation that produced this download is aborted by WebView2
            // (NavigationCompleted fires first with ConnectionAborted, then
            // this event), so the completion handler suppresses the page-load
            // error overlay for approved download endpoints.
            _log?.Write(LogLevel.Info, "WebView2Host", $"Allowing trusted download: {uri}");
            return;
        }

        e.Cancel = true;
        _log?.Write(LogLevel.Warn, "WebView2Host", $"Blocked download: {uri}");
        NavigationBlocked?.Invoke(this, uri);
    }

    private void OnNavigationCompleted(object? sender, CoreWebView2NavigationCompletedEventArgs e)
    {
        _overlay.Visible = false;
        LoadingChanged?.Invoke(this, false);

        if (e.IsSuccess)
        {
            PageLoaded?.Invoke(this, _core?.Source ?? string.Empty);
            _log?.Write(LogLevel.Info, "WebView2Host", "Page loaded.");
        }
        else if (e.WebErrorStatus == CoreWebView2WebErrorStatus.ConnectionAborted
                 && TrustedDownloadPolicy.IsApprovedDownload(_lastNavigationUri, _allowedOrigins, AllowTrustedDownloads))
        {
            // The page navigated to a report endpoint whose attachment
            // response was converted into an allowed download; the navigation
            // is aborted by WebView2 by design (completion fires before
            // DownloadStarting). Not an error: keep the overlay hidden.
            _log?.Write(LogLevel.Info, "WebView2Host", $"Navigation aborted by trusted download: {_lastNavigationUri}");
        }
        else
        {
            _overlayText.Text = "The page could not be loaded.\nCheck that the Axumera server is running, then try again.";
            _overlay.Visible = true;
            _log?.Write(LogLevel.Error, "WebView2Host", $"Navigation failed: {e.WebErrorStatus}");
        }
    }

    private async void OnWebMessageReceived(object? sender, CoreWebView2WebMessageReceivedEventArgs e)
    {
        var json = e.WebMessageAsJson;
        _log?.Write(LogLevel.Debug, "WebView2Host", $"Web message received: {json}");
        WebMessageJsonReceived?.Invoke(this, json);

        if (_channel is null)
        {
            return;
        }

        try
        {
            var reply = await _channel.DispatchAsync(json);
            if (reply is not null)
            {
                PostJson(reply.ToJson());
                _log?.Write(LogLevel.Debug, "WebView2Host", $"Reply posted: {reply.ToJson()}");
            }
        }
        catch (Exception ex)
        {
            _log?.Write(LogLevel.Error, "WebView2Host", $"Message dispatch failed: {ex.Message}");
        }
    }
}
