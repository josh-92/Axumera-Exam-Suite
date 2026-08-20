using System.Text.Json;

namespace Axumera.Core.Ipc;

/// <summary>
/// Typed message exchanged between the native shell and WebView2-hosted content.
/// <see cref="Type"/> is a simple command/event name; <see cref="Payload"/> is an
/// optional JSON fragment (nested objects posted by web content are captured as
/// their raw JSON); <see cref="RequestId"/> correlates request/response pairs.
/// </summary>
public sealed record WebMessage
{
    private static readonly JsonSerializerOptions WireOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
    };

    public string Type { get; init; } = string.Empty;
    public string? Payload { get; init; }
    public string? RequestId { get; init; }

    public static WebMessage Ping(string? requestId = null) => new() { Type = "ping", RequestId = requestId };
    public static WebMessage Pong(string? requestId = null) => new() { Type = "pong", RequestId = requestId };
    public static WebMessage Ready(string appName) => new() { Type = "ready", Payload = JsonSerializer.Serialize(new { app = appName }) };

    /// <summary>Serializes to camelCase JSON (the wire convention understood by web content).</summary>
    public string ToJson() => JsonSerializer.Serialize(this, WireOptions);

    /// <summary>
    /// Tolerant parse: accepts both lower- and upper-camel property names, and
    /// captures object/array payloads as raw JSON fragments (web content commonly
    /// posts nested objects rather than string-encoded payloads).
    /// </summary>
    public static WebMessage? FromJson(string json)
    {
        try
        {
            using var doc = JsonDocument.Parse(json);
            var root = doc.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                return null;
            }

            var type = ReadString(root, "type");
            if (string.IsNullOrWhiteSpace(type))
            {
                return null;
            }

            string? payload = null;
            if (TryRead(root, "payload", out var payloadElement))
            {
                payload = payloadElement.ValueKind switch
                {
                    JsonValueKind.String => payloadElement.GetString(),
                    JsonValueKind.Null or JsonValueKind.Undefined => null,
                    _ => payloadElement.GetRawText(),
                };
            }

            return new WebMessage
            {
                Type = type!,
                Payload = payload,
                RequestId = ReadString(root, "requestId"),
            };
        }
        catch (JsonException)
        {
            return null;
        }
    }

    private static string? ReadString(JsonElement root, string camelName)
    {
        if (!TryRead(root, camelName, out var element))
        {
            return null;
        }

        return element.ValueKind == JsonValueKind.String ? element.GetString() : null;
    }

    private static bool TryRead(JsonElement root, string camelName, out JsonElement value)
    {
        if (root.TryGetProperty(camelName, out value))
        {
            return true;
        }

        // Fallback: accept PascalCase keys (e.g. native-authored messages).
        var pascal = char.ToUpperInvariant(camelName[0]) + camelName.Substring(1);
        return root.TryGetProperty(pascal, out value);
    }
}

/// <summary>Handler contract for native-side processing of web messages.</summary>
public interface IWebMessageHandler
{
    /// <summary>Process a message from web content; may return a reply (null = no reply).</summary>
    Task<WebMessage?> HandleAsync(WebMessage message, CancellationToken cancellationToken);
}

/// <summary>
/// Pure, UI-free channel that routes incoming messages to registered handlers and
/// collects replies. Used by the WebView2 host; fully unit-testable.
/// </summary>
public sealed class WebMessageChannel
{
    private readonly List<IWebMessageHandler> _handlers = new();

    public void Register(IWebMessageHandler handler) => _handlers.Add(handler);

    public async Task<WebMessage?> DispatchAsync(string json, CancellationToken cancellationToken = default)
    {
        var message = WebMessage.FromJson(json);
        if (message is null)
        {
            return new WebMessage { Type = "error", Payload = "{\"reason\":\"malformed-message\"}" };
        }

        foreach (var handler in _handlers)
        {
            var reply = await handler.HandleAsync(message, cancellationToken).ConfigureAwait(false);
            if (reply is not null)
            {
                return reply;
            }
        }

        return null;
    }
}
