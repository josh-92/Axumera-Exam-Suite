namespace Axumera.Core.Branding;

/// <summary>
/// Official Axumera branding constants.
/// Source of truth: <c>C:\Axumera-Enginnering\branding</c> (Phase 0 inventory).
/// Palette: Axumera Gold #D3A029 · Axumera Deep Navy #0C2036 · Background #FFFFFF.
/// </summary>
public static class AxumeraBrand
{
    public const string ProductFamilyName = "Axumera 2.0";
    public const string ProductShortName = "Axumera";

    public const string CopyrightLine = "© 2026 Axumera Technologies. All rights reserved.";

    // --- Official palette (ARGB ints, 0xAARRGGBB) ---
    public const int GoldArgb = unchecked((int)0xFFD3A029);
    public const int DeepNavyArgb = unchecked((int)0xFF0C2036);
    public const int WhiteArgb = unchecked((int)0xFFFFFFFF);
    public const int LightGrayArgb = unchecked((int)0xFFF2F4F6);
    public const int BorderGrayArgb = unchecked((int)0xFFD9DEE3);
    public const int MutedArgb = unchecked((int)0xFF6B7280);

    // --- Official palette (web hex) ---
    public const string GoldHex = "#D3A029";
    public const string DeepNavyHex = "#0C2036";
    public const string WhiteHex = "#FFFFFF";

    public static string ProductDisplayName(string applicationName)
    {
        var name = applicationName.StartsWith(ProductShortName, StringComparison.OrdinalIgnoreCase)
            ? applicationName
            : $"{ProductShortName} {applicationName}";
        return $"{name} — {ProductFamilyName}";
    }
}
