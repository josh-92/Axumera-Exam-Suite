using Axumera.Core.Diagnostics;
using Axumera.Core.Paths;

namespace Axumera.Ui;

/// <summary>
/// Logs lifecycle milestones (splash, webview init, page load, close) to the
/// isolated per-user log. Launch-verification tails these files to confirm the
/// applications start and close cleanly.
/// </summary>
public sealed class StartupTelemetry : IDisposable
{
    private readonly FileLogSink _sink;

    public string ApplicationKey { get; }

    public ILogSink Log => _sink;

    private StartupTelemetry(string applicationKey)
    {
        ApplicationKey = applicationKey;
        AppPaths.EnsureCreated();
        _sink = new FileLogSink(Path.Combine(AppPaths.LogsDirectory, $"{applicationKey}.log"));
        AppLog.Sink = _sink;
    }

    public static StartupTelemetry Create(string applicationKey)
    {
        var telemetry = new StartupTelemetry(applicationKey);
        telemetry.Mark("process-started");
        return telemetry;
    }

    public void Mark(string milestone) => _sink.Write(LogLevel.Info, "startup", milestone);

    public void Dispose() => _sink.Dispose();
}
