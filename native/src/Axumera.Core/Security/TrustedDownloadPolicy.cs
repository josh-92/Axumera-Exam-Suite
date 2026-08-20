namespace Axumera.Core.Security;

/// <summary>
/// Decision logic for the WebView2 download policy. Pure and unit-testable;
/// enforcement lives in Axumera.Ui.AxumeraWebView2Host.
///
/// Policy: downloads are allowed only when the shell explicitly opts in
/// (admin shell) AND the request targets one of the approved report endpoints
/// on an origin that is already in the navigation allowlist. Everything else
/// - any Student shell download, any non-http(s) scheme, any unknown path,
/// any origin outside the allowlist - is cancelled.
/// </summary>
public static class TrustedDownloadPolicy
{
    /// <summary>
    /// Report endpoints the admin shell may download. The AXUMERA application
    /// is served by the native-managed runtime at two equivalent loopback
    /// mounts - the Apache DocumentRoot ("/") and the "/eaes_exam_system"
    /// alias emitted by RuntimeConfigWriter - so both exact spellings of each
    /// endpoint are approved. Paths are matched exactly, never by prefix.
    /// </summary>
    public static readonly string[] ApprovedPaths =
    {
        "/eaes_exam_system/download_results.php",
        "/eaes_exam_system/download_report.php",
        "/download_results.php",
        "/download_report.php",
    };

    /// <summary>
    /// True only when <paramref name="allowTrustedDownloads"/> is set and the
    /// download URI is an http(s) URI whose origin is listed in
    /// <paramref name="allowedOrigins"/> and whose absolute path is one of
    /// <see cref="ApprovedPaths"/>.
    /// </summary>
    public static bool IsApprovedDownload(
        string uriString,
        IReadOnlyCollection<string> allowedOrigins,
        bool allowTrustedDownloads)
    {
        if (!allowTrustedDownloads || string.IsNullOrWhiteSpace(uriString))
        {
            return false;
        }

        if (!Uri.TryCreate(uriString, UriKind.Absolute, out var uri))
        {
            return false;
        }

        if (uri.Scheme is not ("http" or "https"))
        {
            return false;
        }

        if (!ApprovedPaths.Contains(uri.AbsolutePath, StringComparer.OrdinalIgnoreCase))
        {
            return false;
        }

        var origin = uri.GetLeftPart(UriPartial.Authority);
        return allowedOrigins.Any(o => string.Equals(o, origin, StringComparison.OrdinalIgnoreCase));
    }
}