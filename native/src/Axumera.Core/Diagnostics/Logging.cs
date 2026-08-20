using System.Text;

namespace Axumera.Core.Diagnostics;

public enum LogLevel
{
    Debug = 0,
    Info = 1,
    Warn = 2,
    Error = 3,
}

/// <summary>Minimal logging contract shared by all native applications.</summary>
public interface ILogSink
{
    void Write(LogLevel level, string source, string message);
}

/// <summary>
/// Thread-safe file sink writing timestamped lines to a log file.
/// Writes are flushed per line so launch-verification can tail the file live.
/// </summary>
public sealed class FileLogSink : ILogSink, IDisposable
{
    private readonly StreamWriter _writer;
    private readonly object _gate = new();

    public string FilePath { get; }

    public FileLogSink(string filePath)
    {
        FilePath = filePath;
        Directory.CreateDirectory(Path.GetDirectoryName(filePath)!);
        _writer = new StreamWriter(new FileStream(filePath, FileMode.Append, FileAccess.Write, FileShare.ReadWrite))
        {
            AutoFlush = true,
        };
    }

    public void Write(LogLevel level, string source, string message)
    {
        var line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} [{level,-5}] [{source}] {message}";
        lock (_gate)
        {
            _writer.WriteLine(line);
        }
    }

    public void Dispose() => _writer.Dispose();
}

/// <summary>No-op sink (used by tests and when logging is disabled).</summary>
public sealed class NullLogSink : ILogSink
{
    public static NullLogSink Instance { get; } = new();

    private NullLogSink() { }

    public void Write(LogLevel level, string source, string message) { }
}

/// <summary>Static facade so non-injectable code (e.g. WinForms forms) can log.</summary>
public static class AppLog
{
    private static ILogSink _sink = NullLogSink.Instance;
    private static readonly object Gate = new();

    public static ILogSink Sink
    {
        get { lock (Gate) return _sink; }
        set { lock (Gate) _sink = value ?? NullLogSink.Instance; }
    }

    public static void Debug(string source, string message) => Sink.Write(LogLevel.Debug, source, message);
    public static void Info(string source, string message) => Sink.Write(LogLevel.Info, source, message);
    public static void Warn(string source, string message) => Sink.Write(LogLevel.Warn, source, message);
    public static void Error(string source, string message) => Sink.Write(LogLevel.Error, source, message);
}
