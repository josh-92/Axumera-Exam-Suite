using Axumera.Core.Student;
using Xunit;

namespace Axumera.Core.Tests;

public class StudentClientConfigurationTests
{
    [Theory]
    [InlineData("192.168.1.11", "192.168.1.11")]
    [InlineData("http://192.168.1.11", "192.168.1.11")]
    [InlineData("https://192.168.1.11", "192.168.1.11")]
    [InlineData("http://192.168.1.11/slogin.php", "192.168.1.11")]
    [InlineData("  SchoolServer  ", "SchoolServer")]
    [InlineData("http://server.example.com/", "server.example.com")]
    public void NormalizeAddress_strips_scheme_and_path(string input, string expected)
    {
        Assert.Equal(expected, StudentClientConfiguration.NormalizeAddress(input));
    }

    [Theory]
    [InlineData("")]
    [InlineData("   ")]
    [InlineData("http://")]
    [InlineData("bad address with spaces")]
    [InlineData("host:8080")]
    public void NormalizeAddress_rejects_invalid_input(string input)
    {
        Assert.Equal(string.Empty, StudentClientConfiguration.NormalizeAddress(input));
    }

    [Fact]
    public void IsValid_requires_address_and_usable_port()
    {
        var valid = new StudentClientConfiguration { ServerAddress = "192.168.1.11", ApachePort = 8090 };
        Assert.True(valid.IsValid);

        var noAddress = new StudentClientConfiguration { ServerAddress = string.Empty, ApachePort = 8090 };
        Assert.False(noAddress.IsValid);

        var badPort = new StudentClientConfiguration { ServerAddress = "192.168.1.11", ApachePort = 0 };
        Assert.False(badPort.IsValid);

        var hugePort = new StudentClientConfiguration { ServerAddress = "192.168.1.11", ApachePort = 70000 };
        Assert.False(hugePort.IsValid);
    }

    [Fact]
    public void Url_builders_use_configured_address_and_port()
    {
        var config = new StudentClientConfiguration { ServerAddress = "192.168.1.11", ApachePort = 8090 };

        Assert.Equal("http://192.168.1.11:8090/slogin.php", config.StudentLoginUrl.AbsoluteUri);
        Assert.Equal("http://192.168.1.11:8090/health.php", config.HealthUrl.AbsoluteUri);
        Assert.Equal("http://192.168.1.11:8090/review.php", config.ReviewUrl.AbsoluteUri);
    }

    [Fact]
    public void Save_and_load_round_trip_preserves_values()
    {
        var path = Path.Combine(Path.GetTempPath(), "axumera-test-" + Guid.NewGuid().ToString("N") + ".json");
        try
        {
            var config = new StudentClientConfiguration { ServerAddress = "192.168.1.55", ApachePort = 8090 };
            config.SaveTo(path);

            var loaded = StudentClientConfiguration.LoadFrom(path);
            Assert.Equal("192.168.1.55", loaded.ServerAddress);
            Assert.Equal(8090, loaded.ApachePort);
            Assert.True(loaded.IsValid);
        }
        finally
        {
            File.Delete(path);
        }
    }

    [Fact]
    public void Load_returns_default_when_file_is_missing_or_corrupt()
    {
        var missing = Path.Combine(Path.GetTempPath(), "axumera-test-missing-" + Guid.NewGuid().ToString("N") + ".json");
        var corrupt = Path.Combine(Path.GetTempPath(), "axumera-test-corrupt-" + Guid.NewGuid().ToString("N") + ".json");
        try
        {
            File.WriteAllText(corrupt, "{not valid json");
            Assert.False(StudentClientConfiguration.LoadFrom(missing).IsValid);
            Assert.False(StudentClientConfiguration.LoadFrom(corrupt).IsValid);
        }
        finally
        {
            File.Delete(corrupt);
        }
    }

    [Fact]
    public void Persisted_file_never_contains_passwords_or_session_material()
    {
        var path = Path.Combine(Path.GetTempPath(), "axumera-test-" + Guid.NewGuid().ToString("N") + ".json");
        try
        {
            new StudentClientConfiguration { ServerAddress = "192.168.1.11", ApachePort = 8090 }.SaveTo(path);
            var content = File.ReadAllText(path).ToLowerInvariant();

            Assert.DoesNotContain("password", content);
            Assert.DoesNotContain("token", content);
            Assert.DoesNotContain("session", content);
            Assert.Contains("serveraddress", content);
        }
        finally
        {
            File.Delete(path);
        }
    }
}
