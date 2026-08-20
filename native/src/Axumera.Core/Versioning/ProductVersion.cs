namespace Axumera.Core.Versioning;

/// <summary>
/// Version identity for the Axumera 2.0 native applications.
/// </summary>
public static class ProductVersion
{
    /// <summary>Version of the Axumera 2.0 native applications.</summary>
    public const string Version = "2.0.0";

    /// <summary>Label shown in UI status bars and footers.</summary>
    public static string FullLabel => $"Axumera 2.0 · Version {Version}";
}
