using Axumera.Core.Ipc;

namespace Axumera.Ui;

/// <summary>Answers <c>ready</c>/<c>ping</c> messages with a <c>pong</c> reply.</summary>
public sealed class PongHandler : IWebMessageHandler
{
    public Task<WebMessage?> HandleAsync(WebMessage message, CancellationToken cancellationToken)
    {
        return Task.FromResult<WebMessage?>(message.Type is "ready" or "ping"
            ? WebMessage.Pong(message.RequestId)
            : null);
    }
}
