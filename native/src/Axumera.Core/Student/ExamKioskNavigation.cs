namespace Axumera.Core.Student;

/// <summary>
/// Pure classification of the AXE 2.0 student pages for the native kiosk
/// lifecycle. The security boundary is defined by the server-side attempt
/// state, not by the URL alone:
///
///  * an attempt is ACTIVE from the moment examportal.php renders (the server
///    only renders it for an <c>in_progress</c> attempt) until the server
///    finalizes it (submit_exam.php success) or redirects to
///    already_taken.php;
///  * the review page is part of the ACTIVE attempt — the attempt stays
///    <c>in_progress</c> until submission, so arriving there must NEVER release
///    kiosk mode;
///  * only a server-declared terminal page (already_taken.php), the
///    exam-submitted web message, or — as a last-resort fallback when that
///    message is lost during page teardown — a slogin.php landing while kiosk
///    mode is still active may release kiosk mode.
/// </summary>
public static class ExamKioskNavigation
{
    public const string StudentLoginPath = "/slogin.php";
    public const string WaitingPath = "/waite.php";
    public const string ExamPortalPath = "/examportal.php";
    public const string ReviewPath = "/review.php";
    public const string AlreadyTakenPath = "/already_taken.php";

    /// <summary>
    /// True when the page is part of an active attempt and the native kiosk
    /// must stay locked (exam questions, question navigation, review).
    /// </summary>
    public static bool IsActiveAttemptPath(string? path) =>
        path is ExamPortalPath or ReviewPath;

    /// <summary>
    /// True when the server has declared the attempt over: already_taken.php is
    /// only reachable after examportal/submit rejected the attempt because its
    /// status is no longer <c>in_progress</c>. Kiosk mode may release here.
    /// </summary>
    public static bool IsPostExamPath(string? path) => path == AlreadyTakenPath;

    /// <summary>
    /// Full release rule for a page navigation while kiosk mode is active:
    /// a server-declared terminal page (already_taken.php), or — as the
    /// last-resort fallback when the exam-submitted web message was lost during
    /// page teardown — the student login page, which is only reachable from the
    /// exam flow after the attempt ended (post-submit redirect). The login page
    /// alone (kiosk inactive) is never a release.
    /// </summary>
    public static bool IsKioskReleaseNavigation(string? path, bool kioskActive) =>
        IsPostExamPath(path) || (kioskActive && path == StudentLoginPath);
}
