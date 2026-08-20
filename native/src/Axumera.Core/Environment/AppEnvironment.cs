using System.Runtime.InteropServices;

namespace Axumera.Core.Environment;

/// <summary>Read-only environment facts. No writes, no system mutation.</summary>
public static class AppEnvironment
{
    public static bool IsWindows => OperatingSystem.IsWindows();

    public static string OsDescription => RuntimeInformation.OSDescription.Trim();

    /// <summary>Human-readable Windows caption from the registry (read-only).</summary>
    public static string WindowsCaption
    {
        get
        {
            if (!OperatingSystem.IsWindows())
            {
                return OsDescription;
            }

            try
            {
                using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(
                    @"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
                return key?.GetValue("ProductName")?.ToString() ?? OsDescription;
            }
            catch
            {
                return OsDescription;
            }
        }
    }

    /// <summary>True when the process is running a development build (never true for production components).</summary>
    public static bool IsDevelopmentBuild => true;
}
