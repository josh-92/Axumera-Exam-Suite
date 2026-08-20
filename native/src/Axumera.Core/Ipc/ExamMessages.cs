namespace Axumera.Core.Ipc;

/// <summary>
/// Message contract between the native Student shell and the WebView2-hosted
/// exam page. Built on the existing <see cref="WebMessage"/> envelope
/// (type/payload/requestId) so the Phase 1/3 channel is reused unchanged.
///
/// Schema version: 1. Every message carries <c>schemaVersion</c> so either side
/// can refuse incompatible messages instead of guessing.
///
/// Direction  page → native:
///   exam-started        the integrity gate was accepted; the exam is live
///   exam-ended          the student left the exam for review/submission
///                        (payload reason: review | autosubmit | controlled-exit)
///   exam-submitted      the final submission completed server-side
///   integrity-event     informational copy of a web-owned violation
///                        (never re-reported; telemetry only)
///
/// Direction  native → page:
///   exit-exam           request: report a controlled-exit violation, then
///                        navigate to the existing review.php flow
///   exit-exam-ack       page confirmation after processing (native then acts)
/// </summary>
public static class ExamMessages
{
    public const int SchemaVersion = 1;

    // page → native
    public const string ExamStarted = "exam-started";
    public const string ExamEnded = "exam-ended";
    public const string ExamSubmitted = "exam-submitted";
    public const string IntegrityEvent = "integrity-event";

    // native → page
    public const string ExitExam = "exit-exam";
    public const string ExitExamAck = "exit-exam-ack";

    // exam-ended payload reasons
    public const string EndReasonReview = "review";
    public const string EndReasonAutosubmit = "autosubmit";
    public const string EndReasonControlledExit = "controlled-exit";

    public static WebMessage CreateExamStarted() =>
        Message(ExamStarted, $"{{ \"schemaVersion\": {SchemaVersion} }}");

    public static WebMessage CreateExamEnded(string reason) =>
        Message(ExamEnded, $"{{\"schemaVersion\":{SchemaVersion},\"reason\":\"{reason}\"}}");

    public static WebMessage CreateExamSubmitted(int? score = null, int? total = null) =>
        Message(ExamSubmitted, $"{{\"schemaVersion\":{SchemaVersion},\"score\":{(score?.ToString() ?? "null")},\"total\":{(total?.ToString() ?? "null")}}}");

    public static WebMessage CreateIntegrityEvent(string eventType, int violationCount, bool flagged) =>
        Message(IntegrityEvent, $"{{\"schemaVersion\":{SchemaVersion},\"event\":\"{eventType}\",\"violationCount\":{violationCount},\"flagged\":{(flagged ? "true" : "false")}}}");

    public static WebMessage CreateExitExamRequest() =>
        Message(ExitExam, $"{{ \"schemaVersion\": {SchemaVersion} }}");

    public static WebMessage CreateExitExamAck() =>
        Message(ExitExamAck, $"{{ \"schemaVersion\": {SchemaVersion} }}");

    private static WebMessage Message(string type, string payload) =>
        new() { Type = type, Payload = payload };

    /// <summary>True when the message is one of the native → page requests.</summary>
    public static bool IsNativeToPage(string type) =>
        type is ExitExam;

    /// <summary>True when the message is one of the page → native notifications.</summary>
    public static bool IsPageToNative(string type) =>
        type is ExamStarted or ExamEnded or ExamSubmitted or IntegrityEvent or ExitExamAck;
}
