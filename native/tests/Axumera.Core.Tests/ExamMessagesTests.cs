using Axumera.Core.Ipc;
using Xunit;

namespace Axumera.Core.Tests;

public class ExamMessagesTests
{
    [Fact]
    public void Exam_started_message_round_trips_through_the_envelope()
    {
        var json = ExamMessages.CreateExamStarted().ToJson();
        var message = WebMessage.FromJson(json);

        Assert.NotNull(message);
        Assert.Equal(ExamMessages.ExamStarted, message!.Type);
    }

    [Fact]
    public void Exam_ended_message_carries_the_reason()
    {
        var message = WebMessage.FromJson(ExamMessages.CreateExamEnded(ExamMessages.EndReasonAutosubmit).ToJson());

        Assert.NotNull(message);
        Assert.Equal(ExamMessages.ExamEnded, message!.Type);
        Assert.Contains("autosubmit", message.Payload, StringComparison.Ordinal);
    }

    [Fact]
    public void Exit_exam_request_is_directed_native_to_page()
    {
        var message = WebMessage.FromJson(ExamMessages.CreateExitExamRequest().ToJson());

        Assert.NotNull(message);
        Assert.Equal(ExamMessages.ExitExam, message!.Type);
        Assert.True(ExamMessages.IsNativeToPage(message.Type));
        Assert.False(ExamMessages.IsPageToNative(message.Type));
    }

    [Fact]
    public void Lifecycle_notifications_are_directed_page_to_native()
    {
        Assert.True(ExamMessages.IsPageToNative(ExamMessages.ExamStarted));
        Assert.True(ExamMessages.IsPageToNative(ExamMessages.ExamEnded));
        Assert.True(ExamMessages.IsPageToNative(ExamMessages.ExamSubmitted));
        Assert.True(ExamMessages.IsPageToNative(ExamMessages.IntegrityEvent));
        Assert.True(ExamMessages.IsPageToNative(ExamMessages.ExitExamAck));
    }

    [Fact]
    public void Every_message_carries_a_schema_version()
    {
        foreach (var json in new[]
                 {
                     ExamMessages.CreateExamStarted().ToJson(),
                     ExamMessages.CreateExamEnded(ExamMessages.EndReasonReview).ToJson(),
                     ExamMessages.CreateExamSubmitted(7, 10).ToJson(),
                     ExamMessages.CreateExitExamRequest().ToJson(),
                     ExamMessages.CreateExitExamAck().ToJson(),
                     ExamMessages.CreateIntegrityEvent("window_blur", 1, false).ToJson(),
                 })
        {
            var message = WebMessage.FromJson(json);
            Assert.NotNull(message);
            Assert.Contains(ExamMessages.SchemaVersion.ToString(), message!.Payload, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void Web_style_nested_payload_object_is_captured_as_json_fragment()
    {
        // The page posts plain objects; the envelope captures nested payloads
        // as raw JSON fragments so the native side can parse them.
        const string webJson = "{\"type\":\"exam-ended\",\"payload\":{\"schemaVersion\":1,\"reason\":\"review\"}}";
        var message = WebMessage.FromJson(webJson);

        Assert.NotNull(message);
        Assert.Equal(ExamMessages.ExamEnded, message!.Type);
        Assert.Contains("\"reason\":\"review\"", message.Payload, StringComparison.Ordinal);
    }
}
