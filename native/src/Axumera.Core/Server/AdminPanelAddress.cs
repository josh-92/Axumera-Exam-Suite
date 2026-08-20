namespace Axumera.Core.Server;

/// <summary>
/// Builds the existing PHP administrator entry point from the native-managed
/// runtime configuration. No production port or route is embedded here.
/// </summary>
public static class AdminPanelAddress
{
    public const string LoginPath = "adminlogin.php";

    public static Uri Login(ServerConfiguration configuration) =>
        new($"http://127.0.0.1:{configuration.ApachePort}/{LoginPath}");
}
