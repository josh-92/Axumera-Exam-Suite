using Axumera.Core.Security;
using Xunit;

namespace Axumera.Core.Tests;

public class TrustedDownloadPolicyTests
{
    private static readonly string[] AllowedOrigins =
    {
        "http://127.0.0.1:8088",
        "https://axumera.dev",
    };

    [Theory]
    [InlineData("http://127.0.0.1:8088/download_results.php?exam_id=1")]
    [InlineData("http://127.0.0.1:8088/download_report.php?exam_id=1")]
    [InlineData("http://127.0.0.1:8088/download_results.php")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/download_results.php?exam_id=1")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/download_report.php?exam_id=1")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/download_results.php")]
    [InlineData("http://127.0.0.1:8088/EAES_EXAM_SYSTEM/Download_Results.php?exam_id=1")]
    public void Admin_report_endpoints_on_allowed_origin_are_approved(string uri)
    {
        Assert.True(TrustedDownloadPolicy.IsApprovedDownload(uri, AllowedOrigins, allowTrustedDownloads: true));
    }

    [Theory]
    [InlineData("http://127.0.0.1:8088/download_other.php")]
    [InlineData("http://127.0.0.1:8088/assets/js/admin.js")]
    [InlineData("http://127.0.0.1:8088/download_results.php/extra")]
    [InlineData("http://127.0.0.1:8088/adminpanel.php")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/evil.php")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/download_results_fake.php")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/download_report.php/extra")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/adminpanel.php")]
    [InlineData("http://127.0.0.1:8088/other_sensitive_file")]
    public void Non_report_paths_are_rejected_even_when_enabled(string uri)
    {
        Assert.False(TrustedDownloadPolicy.IsApprovedDownload(uri, AllowedOrigins, allowTrustedDownloads: true));
    }

    [Theory]
    [InlineData("http://127.0.0.1:8088/download_results.php?exam_id=1")]
    [InlineData("https://axumera.dev/download_results.php")]
    public void Downloads_are_rejected_when_the_shell_has_not_opted_in(string uri)
    {
        Assert.False(TrustedDownloadPolicy.IsApprovedDownload(uri, AllowedOrigins, allowTrustedDownloads: false));
    }

    [Theory]
    [InlineData("http://evil.example/eaes_exam_system/download_results.php")]
    [InlineData("http://127.0.0.1:9999/eaes_exam_system/download_results.php")]
    [InlineData("http://127.0.0.1:8088/eaes_exam_system/evil.php")]
    [InlineData("http://evil.example/download_results.php")]
    [InlineData("http://127.0.0.1:9999/download_results.php")]
    [InlineData("https://axumera.evil.dev/download_results.php")]
    [InlineData("file:///C:/download_results.php")]
    [InlineData("mailto:someone@example.com")]
    [InlineData("javascript:alert(1)")]
    [InlineData("")]
    public void Foreign_origins_and_non_http_schemes_are_always_rejected(string uri)
    {
        Assert.False(TrustedDownloadPolicy.IsApprovedDownload(uri, AllowedOrigins, allowTrustedDownloads: true));
    }

    [Fact]
    public void Placeholder_origin_is_only_approved_for_report_paths()
    {
        Assert.True(TrustedDownloadPolicy.IsApprovedDownload(
            "https://axumera.dev/download_results.php", AllowedOrigins, allowTrustedDownloads: true));
        Assert.False(TrustedDownloadPolicy.IsApprovedDownload(
            "https://axumera.dev/index.html", AllowedOrigins, allowTrustedDownloads: true));
    }

    [Fact]
    public void Paths_are_case_insensitive_like_the_loopback_server()
    {
        Assert.True(TrustedDownloadPolicy.IsApprovedDownload(
            "http://127.0.0.1:8088/Download_Results.php?exam_id=1", AllowedOrigins, allowTrustedDownloads: true));
        Assert.True(TrustedDownloadPolicy.IsApprovedDownload(
            "http://127.0.0.1:8088/eaes_exam_system/Download_Results.php?exam_id=1", AllowedOrigins, allowTrustedDownloads: true));
    }

    [Fact]
    public void Approved_path_list_is_exactly_the_report_endpoints_on_both_loopback_mounts()
    {
        Assert.Equal(
            new[]
            {
                "/eaes_exam_system/download_results.php",
                "/eaes_exam_system/download_report.php",
                "/download_results.php",
                "/download_report.php",
            },
            TrustedDownloadPolicy.ApprovedPaths);
    }
}