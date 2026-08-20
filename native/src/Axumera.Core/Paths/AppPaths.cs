namespace Axumera.Core.Paths;

/// <summary>
/// Isolated per-user storage for the Axumera 2.0 native applications.
/// Everything lives under %LOCALAPPDATA%\Axumera 2.0 — deliberately disjoint
/// from the production installation (Program Files) and its ProgramData state.
/// </summary>
public static class AppPaths
{
    public const string RootFolderName = "Axumera 2.0";

    public static string BaseDataDirectory =>
        Path.Combine(System.Environment.GetFolderPath(System.Environment.SpecialFolder.LocalApplicationData), RootFolderName);

    public static string LogsDirectory => Path.Combine(BaseDataDirectory, "logs");

    public static string WebView2RootDirectory => Path.Combine(BaseDataDirectory, "WebView2");

    /// <summary>Isolated WebView2 user-data folder per application (session isolation).</summary>
    public static string WebView2UserDataDirectory(string applicationKey) =>
        Path.Combine(WebView2RootDirectory, applicationKey);

    public static void EnsureCreated()
    {
        Directory.CreateDirectory(BaseDataDirectory);
        Directory.CreateDirectory(LogsDirectory);
        Directory.CreateDirectory(WebView2RootDirectory);
    }
}
