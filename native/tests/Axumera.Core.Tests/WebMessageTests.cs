using Axumera.Core.Ipc;
using Xunit;

namespace Axumera.Core.Tests;

public class WebMessageTests
{
    [Fact]
    public void Round_trip_json_preserves_type_and_request_id()
    {
        var message = WebMessage.Ping("abc");
        var back = WebMessage.FromJson(message.ToJson());

        Assert.NotNull(back);
        Assert.Equal("ping", back!.Type);
        Assert.Equal("abc", back.RequestId);
    }

    [Fact]
    public async Task Channel_dispatches_to_handler_and_returns_reply()
    {
        var channel = new WebMessageChannel();
        channel.Register(new PongHandlerStub());

        var reply = await channel.DispatchAsync(WebMessage.Ping("r1").ToJson());

        Assert.NotNull(reply);
        Assert.Equal("pong", reply!.Type);
        Assert.Equal("r1", reply.RequestId);
    }

    [Fact]
    public async Task Channel_reports_malformed_json()
    {
        var channel = new WebMessageChannel();
        var reply = await channel.DispatchAsync("{not valid json");

        Assert.NotNull(reply);
        Assert.Equal("error", reply!.Type);
        Assert.Contains("malformed", reply.Payload, StringComparison.Ordinal);
    }

    [Fact]
    public async Task Channel_returns_null_when_no_handler_replies()
    {
        var channel = new WebMessageChannel();
        var reply = await channel.DispatchAsync(WebMessage.Ready("Axumera").ToJson());

        Assert.Null(reply);
    }

    private sealed class PongHandlerStub : IWebMessageHandler
    {
        public Task<WebMessage?> HandleAsync(WebMessage message, CancellationToken cancellationToken) =>
            Task.FromResult<WebMessage?>(WebMessage.Pong(message.RequestId));
    }
}
