namespace Axumera.Core.Student;

/// <summary>Lifecycle state of the native kiosk layer.</summary>
public enum KioskState
{
    /// <summary>Normal Axumera window (login, waiting room).</summary>
    Idle,

    /// <summary>The student entered the exam; the window is locked down.</summary>
    Entered,

    /// <summary>The exam ended or was exited; the window returned to normal.</summary>
    Exited,
}

/// <summary>Which layer owns reporting of an integrity-relevant event.</summary>
public enum IntegrityEventOwner
{
    /// <summary>The web layer (exam.js) already reports this event to the PHP
    /// server. The native layer must never duplicate it.</summary>
    Web,

    /// <summary>An OS-level event the web layer cannot see; the native layer
    /// reports it through the existing PHP endpoint (via the page).</summary>
    Native,
}

/// <summary>
/// Pure, UI-free policy for the Phase 4 kiosk layer:
///
///  * kiosk state transitions (Idle → Entered → Exited), with the rule that an
///    exam cannot be re-entered once it has ended in this session;
///  * ownership classification of integrity events so the same physical action
///    is never reported twice (web layer reports in-page events; the native
///    layer reports only OS-level events the page cannot see);
///  * throttling rules for native focus-loss observations so harmless Windows
///    events (system notifications, transient deactivation) never become
///    violations on their own.
/// </summary>
public sealed class ExamKioskPolicy
{
    /// <summary>After this many quiet milliseconds, a deactivation burst is
    /// treated as a single incident rather than N violations.</summary>
    public const long FocusLossCooldownMs = 4000;

    /// <summary>The only native-owned integrity event in Phase 4. It flows
    /// through the existing report_violation.php pipeline (the page performs
    /// the POST with its own CSRF token) so the server stays authoritative.</summary>
    public const string ControlledExitEvent = "controlled_exit";

    private KioskState _state = KioskState.Idle;
    private long _lastNativeFocusLossUtcMs;

    public KioskState State => _state;

    /// <summary>True while the native lockdown is active.</summary>
    public bool IsKioskActive => _state == KioskState.Entered;

    /// <summary>
    /// Transitions to <see cref="KioskState.Entered"/>. Returns false (state
    /// unchanged) if the exam already ended — the shell must not re-lock after
    /// the student submitted or exited.
    /// </summary>
    public bool EnterKiosk()
    {
        if (_state == KioskState.Exited)
        {
            return false;
        }

        _state = KioskState.Entered;
        return true;
    }

    /// <summary>Transitions to <see cref="KioskState.Exited"/> (once).</summary>
    public bool ExitKiosk()
    {
        if (_state == KioskState.Exited)
        {
            return false;
        }

        _state = KioskState.Exited;
        return true;
    }

    /// <summary>
    /// Ownership of an integrity-relevant event. In-page events
    /// (tab_hidden, window_blur, fullscreen_exit, copy/paste/context-menu,
    /// devtools shortcuts) are owned by exam.js and must NOT be re-reported by
    /// the native layer. Only OS-level events the page cannot detect are native
    /// owned (Phase 4: controlled exit only).
    /// </summary>
    public static IntegrityEventOwner OwnerOf(string eventType)
    {
        return eventType switch
        {
            "tab_hidden" or "window_blur" or "fullscreen_exit"
                or "copy_attempt" or "paste_attempt" or "context_menu_attempt"
                or "devtools_shortcut_attempt" => IntegrityEventOwner.Web,
            _ => IntegrityEventOwner.Native,
        };
    }

    /// <summary>
    /// True when a native focus-loss observation may be recorded. Transient
    /// deactivations (a system notification stealing focus for a moment) are
    /// coalesced within <see cref="FocusLossCooldownMs"/> so harmless Windows
    /// events do not each become a violation. Only used for local telemetry —
    /// violations themselves are always reported by the web layer's
    /// window_blur/tab_hidden handling, so nothing here reaches the server.
    /// </summary>
    public bool ShouldRecordNativeFocusLoss(DateTimeOffset nowUtc)
    {
        var nowMs = nowUtc.ToUnixTimeMilliseconds();
        if (nowMs - _lastNativeFocusLossUtcMs < FocusLossCooldownMs)
        {
            return false;
        }

        _lastNativeFocusLossUtcMs = nowMs;
        return true;
    }
}
