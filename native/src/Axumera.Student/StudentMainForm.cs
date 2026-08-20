using System.Text.Json;
using Axumera.Core.Ipc;
using Axumera.Core.Server;
using Axumera.Core.Student;
using Axumera.Ui;

namespace Axumera.Student;

/// <summary>
/// Axumera Student Exam Client (Phase 4).
///
/// Native shell around the existing PHP exam application:
///
///   branded server-connection dialog → health check → WebView2 → slogin.php
///   → waite.php → examportal.php → KIOSK MODE at exam start → exam → review
///   → kiosk exits only when the server finalizes the attempt (exam-submitted
///   or already_taken.php) → normal window.
///
/// SECURITY BOUNDARY (Phase 4): the review page is still part of the active
/// attempt — the AXE 2.0 attempt stays 'in_progress' until submit_exam.php
/// succeeds. Kiosk mode therefore stays locked through question navigation,
/// autosave, the review page, and the controlled-exit review flow, and is
/// released ONLY on a server-declared end: the exam-submitted web message
/// (posted by review.php after submit_exam.php finalizes the attempt) or
/// navigation to already_taken.php (only reachable once the attempt is no
/// longer in_progress). See ExamKioskNavigation for the pure classification.
///
/// The PHP application is untouched in behavior: this form only supplies the
/// native shell, the connection/health flow, the kiosk lockdown, and the
/// controlled exit path. All integrity state lives server-side (the existing
/// report_violation.php → AttemptRepository pipeline); the native layer never
/// touches the database.
/// </summary>
public sealed class StudentMainForm : WebShellForm
{
    private readonly ExamKioskPolicy _kiosk = new();
    private readonly LowLevelKeyboardHook _keyboardHook = new();

    private StudentClientConfiguration _config = StudentClientConfiguration.Load();
    private Panel? _errorPanel;
    private Button? _retryButton;
    private Button? _changeServerButton;
    private Panel? _kioskStrip;
    private Button? _exitExamButton;
    private System.Windows.Forms.Timer? _kioskFallbackTimer;

    private bool _examActive;
    private bool _kioskActive;
    private bool _exitRequested;
    private bool _exitAckReceived;
    private bool _closeConfirmed;
    private bool _dialogOpen;
    private bool _closing;
    private string? _currentPath;

    private Rectangle _preKioskBounds;
    private bool _preKioskTopMost;
    private FormBorderStyle _preKioskBorderStyle;

    protected override string ShellSubtitle => "Secure Student Exam Client";

    public StudentMainForm(StartupTelemetry telemetry)
        : base("Student", "Axumera Student", telemetry)
    {
        BuildKioskStrip();
        BuildErrorPanel();
        WebHost.NavigationBlocked += (_, uri) => Telemetry.Mark("navigation-blocked: " + uri);
    }

    protected override void OnPageLoaded(string uri)
    {
        base.OnPageLoaded(uri);
        OnNavigated(uri);
    }

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);
        try
        {
            await InitializeWebAsync();
            await RunConnectFlowAsync();
        }
        catch (Exception ex)
        {
            FailStartup(ex);
        }
    }

    // ============================================================= connect flow

    private async Task RunConnectFlowAsync()
    {
        Telemetry.Mark("connect-flow-started");

        if (!_config.IsValid || string.IsNullOrWhiteSpace(_config.ServerAddress))
        {
            if (!PromptForServer())
            {
                _closeConfirmed = true;
                Close();
                return;
            }
        }

        await CheckServerAndNavigateAsync();
    }

    private bool PromptForServer()
    {
        _dialogOpen = true;
        try
        {
            using var dialog = new ServerEntryDialog(_config.ServerAddress, _config.ApachePort);
            if (dialog.ShowDialog(this) != DialogResult.OK)
            {
                return false;
            }

            _config = new StudentClientConfiguration
            {
                ServerAddress = dialog.ServerAddress,
                ApachePort = dialog.ApachePort,
            };
            _config.Save();
            Telemetry.Mark("server-address-configured");
            return true;
        }
        finally
        {
            _dialogOpen = false;
        }
    }

    private async Task CheckServerAndNavigateAsync()
    {
        SetStatus($"Checking Axumera server at {_config.ServerAddress}:{_config.ApachePort}…");
        HideErrorPanel();
        Telemetry.Mark("server-health-check");

        var healthy = await HealthProbe.HttpHealthyAsync(_config.HealthUrl.AbsoluteUri, TimeSpan.FromSeconds(8));
        if (IsDisposed || _closing)
        {
            return;
        }

        if (!healthy)
        {
            Telemetry.Mark("server-health-failed");
            SetStatus("Axumera Server is unavailable.");
            ShowErrorPanel();
            return;
        }

        Telemetry.Mark("server-health-ok");
        SetStatus("Loading Axumera student login…");
        WebHost.NavigateToApplication(_config.StudentLoginUrl, allowAnyHttpHost: true);
        Telemetry.Mark("student-login-navigation-requested");
    }

    private void ShowErrorPanel()
    {
        if (_errorPanel is null)
        {
            return;
        }

        _errorPanel.Visible = true;
        _errorPanel.BringToFront();
    }

    private void HideErrorPanel()
    {
        if (_errorPanel is not null)
        {
            _errorPanel.Visible = false;
        }

        WebHost.BringToFront();
    }

    private void BuildErrorPanel()
    {
        _errorPanel = new Panel { Dock = DockStyle.Fill, BackColor = Theme.White, Visible = false };

        var title = new Label
        {
            Text = "Axumera Server is unavailable.",
            Font = Theme.TitleFont,
            ForeColor = Theme.DeepNavy,
            TextAlign = ContentAlignment.MiddleCenter,
            AutoSize = false,
            Dock = DockStyle.None,
        };
        var body = new Label
        {
            Text = "Check that the Axumera Server is running and that this computer is connected to the school network.",
            Font = Theme.BodyFont,
            ForeColor = Theme.Muted,
            TextAlign = ContentAlignment.MiddleCenter,
            AutoSize = false,
            Dock = DockStyle.None,
        };
        _retryButton = new Button
        {
            Text = "RETRY",
            Font = Theme.CaptionFont,
            BackColor = Theme.Gold,
            ForeColor = Theme.White,
            FlatStyle = FlatStyle.Flat,
            Size = new Size(130, 34),
        };
        _changeServerButton = new Button
        {
            Text = "CHANGE SERVER",
            Font = Theme.CaptionFont,
            BackColor = Theme.LightGray,
            ForeColor = Theme.DeepNavy,
            FlatStyle = FlatStyle.Flat,
            Size = new Size(150, 34),
        };
        _retryButton.Click += async (_, _) => await CheckServerAndNavigateAsync();
        _changeServerButton.Click += (_, _) =>
        {
            if (PromptForServer())
            {
                _ = CheckServerAndNavigateAsync();
            }
        };

        _errorPanel.Resize += (_, _) =>
        {
            int cx = _errorPanel.ClientSize.Width;
            int cy = _errorPanel.ClientSize.Height;
            title.SetBounds(40, cy / 2 - 110, cx - 80, 34);
            body.SetBounds(60, cy / 2 - 66, cx - 120, 60);
            _retryButton.Location = new Point(cx / 2 - 150, cy / 2 + 10);
            _changeServerButton.Location = new Point(cx / 2 + 10, cy / 2 + 10);
        };

        _errorPanel.Controls.Add(_retryButton);
        _errorPanel.Controls.Add(_changeServerButton);
        _errorPanel.Controls.Add(body);
        _errorPanel.Controls.Add(title);
        Controls.Add(_errorPanel);
    }

    // ============================================================= kiosk strip

    private void BuildKioskStrip()
    {
        _kioskStrip = new Panel
        {
            Dock = DockStyle.Top,
            Height = 36,
            BackColor = Theme.White,
            Visible = false,
        };
        _kioskStrip.Controls.Add(new Panel { Dock = DockStyle.Bottom, Height = 1, BackColor = Theme.BorderGray });

        var label = new Label
        {
            Text = "Axumera Exam",
            Font = Theme.CaptionFont,
            ForeColor = Theme.DeepNavy,
            AutoSize = false,
            TextAlign = ContentAlignment.MiddleLeft,
            Dock = DockStyle.Fill,
            Padding = new Padding(14, 0, 0, 0),
        };
        _kioskStrip.Controls.Add(label);

        _exitExamButton = new Button
        {
            Text = "EXIT EXAM",
            Font = Theme.CaptionFont,
            BackColor = Theme.Gold,
            ForeColor = Theme.White,
            FlatStyle = FlatStyle.Flat,
            Size = new Size(120, 26),
            Anchor = AnchorStyles.Top | AnchorStyles.Right,
            Location = new Point(10, 5),
        };
        _exitExamButton.Click += async (_, _) => await OnExitExamButtonAsync();
        _kioskStrip.Controls.Add(_exitExamButton);
        _kioskStrip.Resize += (_, _) =>
        {
            _exitExamButton.Left = _kioskStrip.ClientSize.Width - _exitExamButton.Width - 12;
            _exitExamButton.Top = (_kioskStrip.ClientSize.Height - _exitExamButton.Height) / 2;
        };

        Controls.Add(_kioskStrip);
    }

    private void ShowKioskStrip(bool show)
    {
        if (_kioskStrip is not null)
        {
            _kioskStrip.Visible = show;

            // IMPORTANT: do NOT bring the strip to the front. WinForms docks
            // controls back-to-front, so a front-most Dock.Top control is
            // positioned AFTER the Dock.Fill WebView2 host and overlaps its top
            // strip — hiding the exam page's student information bar. Keeping
            // the strip behind the host lets it dock first (36 px) and the
            // WebView2 fill the remaining viewport below it, so nothing is
            // covered. The strip is still fully clickable because the two
            // controls do not overlap geometrically.
            WebHost.BringToFront();
        }
    }

    // ============================================================= kiosk mode

    private void EnterKioskMode()
    {
        if (_kioskActive || !_kiosk.EnterKiosk())
        {
            return;
        }

        _kioskActive = true;
        _preKioskBounds = Bounds;
        _preKioskBorderStyle = FormBorderStyle;
        _preKioskTopMost = TopMost;

        var screen = Screen.FromControl(this);
        FormBorderStyle = FormBorderStyle.None;
        Bounds = screen.Bounds;
        TopMost = true;

        SetShellChromeVisible(false);
        ShowKioskStrip(true);
        _keyboardHook.Install();
        Telemetry.Mark("kiosk-entered");
    }

    private void ExitKioskMode()
    {
        if (!_kioskActive)
        {
            return;
        }

        _kiosk.ExitKiosk();
        _kioskActive = false;
        _keyboardHook.Uninstall();
        ShowKioskStrip(false);
        SetShellChromeVisible(true);

        TopMost = _preKioskTopMost;
        FormBorderStyle = _preKioskBorderStyle;
        Bounds = _preKioskBounds;
        Telemetry.Mark("kiosk-exited");
    }

    protected override void OnDeactivate(EventArgs e)
    {
        base.OnDeactivate(e);

        // During an exam, transient focus loss (a system notification) must not
        // become a violation on its own and must not let the student wander.
        // We re-assert focus and record the incident locally (throttled). The
        // server-side violation, if any, is the web layer's window_blur event —
        // never duplicated here.
        if (!_kioskActive || _dialogOpen || _closing || _closeConfirmed)
        {
            return;
        }

        if (_kiosk.ShouldRecordNativeFocusLoss(DateTimeOffset.UtcNow))
        {
            Telemetry.Mark("native-focus-loss-observed");
        }

        BeginInvoke(() =>
        {
            if (_kioskActive && !_dialogOpen && !_closing && !_closeConfirmed && Visible)
            {
                Activate();
                BringToFront();
            }
        });
    }

    // ============================================================= exam lifecycle

    private void OnExamStartedMessage()
    {
        Telemetry.Mark("exam-started-message");
        _examActive = true;
        StopKioskFallbackTimer();
        EnterKioskMode();
    }

    private void OnExamEndedMessage(string reason)
    {
        // Informational only. 'review' and 'controlled-exit' both land on the
        // review page while the attempt is still in_progress server-side, and
        // 'autosubmit' finalizes the attempt at submit time — so none of them
        // may release kiosk mode. Release is driven exclusively by
        // exam-submitted / already_taken.php (see ExamKioskNavigation).
        Telemetry.Mark("exam-ended-message: " + reason);
        StopKioskFallbackTimer();
    }

    private void OnExamSubmittedMessage()
    {
        // The server finalized the attempt (submit_exam.php succeeded); only
        // now may the student leave the locked environment.
        Telemetry.Mark("exam-submitted-message");
        _examActive = false;
        StopKioskFallbackTimer();
        ExitKioskMode();
    }

    private void OnNavigated(string uriString)
    {
        if (!Uri.TryCreate(uriString, UriKind.Absolute, out var uri))
        {
            return;
        }

        if (!string.Equals(uri.Host, _config.ServerAddress, StringComparison.OrdinalIgnoreCase))
        {
            return;
        }

        _currentPath = uri.AbsolutePath;
        if (ExamKioskNavigation.IsKioskReleaseNavigation(_currentPath, _kioskActive))
        {
            // Terminal state reached while kiosk mode is (still) active:
            // already_taken.php is server-declared terminal state, and
            // slogin.php is only reachable from the exam flow after the
            // attempt ended (post-submit redirect) — the fallback that
            // releases a submitted student even if the exam-submitted
            // message was lost during page teardown. Kiosk mode re-engages
            // automatically on the next exam entry.
            Telemetry.Mark(_currentPath == ExamKioskNavigation.AlreadyTakenPath ? "already-taken-loaded" : "kiosk-release-fallback-slogin");
            _examActive = false;
            StopKioskFallbackTimer();
            ExitKioskMode();
            return;
        }

        if (_currentPath == ExamKioskNavigation.ExamPortalPath)
        {
            Telemetry.Mark("exam-portal-loaded");
            // Kiosk engages when the integrity gate is accepted
            // (exam-started message) — with a defensive fallback so a
            // messaging failure never leaves the exam unprotected.
            StartKioskFallbackTimer();
        }

        // NOTE: /review.php deliberately does NOT release kiosk mode here. The
        // review page is part of the active attempt until submission, and
        // releasing on review.php would hand the student a free window to
        // browse elsewhere mid-exam.
    }

    private void StartKioskFallbackTimer()
    {
        StopKioskFallbackTimer();
        _kioskFallbackTimer = new System.Windows.Forms.Timer { Interval = 5000 };
        _kioskFallbackTimer.Tick += (_, _) =>
        {
            StopKioskFallbackTimer();
            if (ExamKioskNavigation.IsActiveAttemptPath(_currentPath))
            {
                Telemetry.Mark("kiosk-enter-fallback");
                _examActive = true;
                EnterKioskMode();
            }
        };
        _kioskFallbackTimer.Start();
    }

    private void StopKioskFallbackTimer()
    {
        _kioskFallbackTimer?.Stop();
        _kioskFallbackTimer?.Dispose();
        _kioskFallbackTimer = null;
    }

    // ============================================================= controlled exit

    private async Task OnExitExamButtonAsync()
    {
        if (!_kioskActive || _exitRequested)
        {
            return;
        }

        _dialogOpen = true;
        try
        {
            using var dialog = new ExamExitConfirmDialog(
                "Exit Exam",
                "Leaving the exam will be recorded as an integrity violation.\n\nThe exam may be locked or submitted according to the exam rules.\n\nAre you sure you want to exit?");
            if (dialog.ShowDialog(this) != DialogResult.OK)
            {
                Telemetry.Mark("controlled-exit-cancelled");
                return; // remain in the exam; nothing is recorded
            }
        }
        finally
        {
            _dialogOpen = false;
        }

        Telemetry.Mark("controlled-exit-confirmed");
        await RequestControlledExitAsync(closeAfter: false);
    }

    private async Task RequestControlledExitAsync(bool closeAfter)
    {
        if (_exitRequested)
        {
            return;
        }

        _exitRequested = true;

        // 1. Ask the page to report the controlled-exit violation through the
        //    existing report_violation.php pipeline (the page owns the CSRF
        //    token and the single server-side event) and to flush answers.
        PostJsonToPage(ExamMessages.CreateExitExamRequest().ToJson());

        // 2. Wait for the page acknowledgement (violation recorded server-side
        //    and final autosave complete — the page acks only after both).
        var deadline = DateTime.UtcNow.AddSeconds(6);
        while (!_exitAckReceived && DateTime.UtcNow < deadline)
        {
            await Task.Delay(100);
        }

        // 3. Fallback: if the page never answered, take the student to the
        //    existing review flow ourselves (best effort — the violation POST
        //    may not have reached the server in this edge case).
        if (!_exitAckReceived)
        {
            WebHost.NavigateToApplication(_config.ReviewUrl, allowAnyHttpHost: true);
        }

        // 4. Kiosk mode stays LOCKED: the attempt remains in_progress until
        //    the server finalizes it (exam-submitted / already_taken.php), so
        //    the student cannot wander into another application from the
        //    review page. Only when the student chose to close the app do we
        //    shut the window down after the violation was recorded.
        if (closeAfter)
        {
            // Give the page a moment to finish processing the ack it posted.
            await Task.Delay(1500);
            _closeConfirmed = true;
            BeginInvoke(Close);
        }
    }

    // ============================================================= close handling

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        _closing = true;
        StopKioskFallbackTimer();

        if (_closeConfirmed || !_kioskActive || !_examActive)
        {
            _keyboardHook.Uninstall();
            base.OnFormClosing(e);
            return;
        }

        // Exam in progress: never terminate silently.
        e.Cancel = true;
        _ = ConfirmCloseDuringExamAsync();
    }

    private async Task ConfirmCloseDuringExamAsync()
    {
        if (_exitRequested)
        {
            return;
        }

        _dialogOpen = true;
        try
        {
            using var dialog = new ExamExitConfirmDialog(
                "Exam in Progress",
                "Your exam is currently in progress.\n\nClosing Axumera will be recorded as an integrity event and may end your attempt.\n\nAre you sure you want to exit?");
            if (dialog.ShowDialog(this) != DialogResult.OK)
            {
                Telemetry.Mark("close-during-exam-cancelled");
                _closing = false;
                return; // return to the exam
            }
        }
        finally
        {
            _dialogOpen = false;
        }

        Telemetry.Mark("close-during-exam-confirmed");
        await RequestControlledExitAsync(closeAfter: true);
    }

    // ============================================================= web messages

    protected override void OnWebMessageJson(object? sender, string json)
    {
        base.OnWebMessageJson(sender, json);

        var message = WebMessage.FromJson(json);
        if (message is null)
        {
            return;
        }

        switch (message.Type)
        {
            case ExamMessages.ExamStarted:
                OnExamStartedMessage();
                break;
            case ExamMessages.ExamEnded:
                OnExamEndedMessage(ReadStringPayload(message.Payload, "reason") ?? ExamMessages.EndReasonReview);
                break;
            case ExamMessages.ExamSubmitted:
                OnExamSubmittedMessage();
                break;
            case ExamMessages.ExitExamAck:
                Telemetry.Mark("exit-exam-ack");
                _exitAckReceived = true;
                break;
            case ExamMessages.IntegrityEvent:
                // Informational copy of a web-owned event. Logged only — the
                // server already recorded it; native never re-reports.
                Telemetry.Mark($"integrity-event: {message.Payload}");
                break;
        }
    }

    private static string? ReadStringPayload(string? payloadJson, string property)
    {
        if (string.IsNullOrWhiteSpace(payloadJson))
        {
            return null;
        }

        try
        {
            using var doc = JsonDocument.Parse(payloadJson);
            if (doc.RootElement.TryGetProperty(property, out var value) && value.ValueKind == JsonValueKind.String)
            {
                return value.GetString();
            }
        }
        catch (JsonException)
        {
        }

        return null;
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            StopKioskFallbackTimer();
            _keyboardHook.Dispose();
        }

        base.Dispose(disposing);
    }
}
