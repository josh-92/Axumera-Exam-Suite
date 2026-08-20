using Axumera.Core.Server;
using Xunit;

namespace Axumera.Server.Tests;

public class ServerConfigurationTests : IDisposable
{
    private readonly string _tempRoot;

    public ServerConfigurationTests()
    {
        _tempRoot = Path.Combine(Path.GetTempPath(), "axumera-config-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_tempRoot);
    }

    public void Dispose()
    {
        try
        {
            Directory.Delete(_tempRoot, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    private void WriteRuntimeLayout(string portsJson)
    {
        Directory.CreateDirectory(Path.Combine(_tempRoot, "config"));
        File.WriteAllText(Path.Combine(_tempRoot, "config", "ports.json"), portsJson);
    }

    [Fact]
    public void Load_parses_authoritative_ports()
    {
        WriteRuntimeLayout("{\r\n    \"mariadb\":  3310,\r\n    \"apache\":  8090\r\n}\r\n");

        var result = ServerConfiguration.Load(_tempRoot);

        Assert.True(result.Succeeded);
        Assert.Equal(8090, result.Value!.ApachePort);
        Assert.Equal(3310, result.Value.MariaDbPort);
        Assert.Equal(_tempRoot, result.Value.InstallRoot);
    }

    [Fact]
    public void Load_applies_fallbacks_when_ports_json_missing_entries()
    {
        WriteRuntimeLayout("{}");

        var result = ServerConfiguration.Load(_tempRoot);

        Assert.True(result.Succeeded);
        Assert.Equal(8088, result.Value!.ApachePort);
        Assert.Equal(3308, result.Value.MariaDbPort);
        // Loopback-only remains the default: LAN binding is an explicit choice.
        Assert.Equal("127.0.0.1", result.Value!.BindAddress);
    }

    [Fact]
    public void Load_parses_bind_address()
    {
        WriteRuntimeLayout("{\"apache\": 8188, \"mariadb\": 3388, \"bindAddress\": \"0.0.0.0\"}");

        var result = ServerConfiguration.Load(_tempRoot);

        Assert.True(result.Succeeded);
        Assert.Equal("0.0.0.0", result.Value!.BindAddress);
    }

    [Fact]
    public void Load_rejects_empty_bind_address()
    {
        WriteRuntimeLayout("{\"bindAddress\": \"\"}");

        var result = ServerConfiguration.Load(_tempRoot);

        Assert.False(result.Succeeded);
        Assert.Equal("config.invalid-bind-address", result.ErrorCode);
    }

    [Fact]
    public void Load_fails_when_root_missing()
    {
        var result = ServerConfiguration.Load(Path.Combine(_tempRoot, "does-not-exist"));

        Assert.False(result.Succeeded);
        Assert.Equal("config.missing-root", result.ErrorCode);
    }

    [Fact]
    public void Load_fails_when_ports_file_missing()
    {
        var result = ServerConfiguration.Load(_tempRoot);

        Assert.False(result.Succeeded);
        Assert.Equal("config.missing-ports", result.ErrorCode);
    }

    [Fact]
    public void Load_rejects_identical_ports()
    {
        WriteRuntimeLayout("{\"apache\": 5555, \"mariadb\": 5555}");

        var result = ServerConfiguration.Load(_tempRoot);

        Assert.False(result.Succeeded);
        Assert.Equal("config.port-collision", result.ErrorCode);
    }

    [Fact]
    public void Derived_paths_stay_inside_the_runtime_root()
    {
        WriteRuntimeLayout("{}");

        var config = ServerConfiguration.Load(_tempRoot).Value!;

        Assert.StartsWith(config.InstallRoot, config.ApacheExe, StringComparison.OrdinalIgnoreCase);
        Assert.StartsWith(config.InstallRoot, config.ApplicationRoot, StringComparison.OrdinalIgnoreCase);
        Assert.StartsWith(config.InstallRoot, config.StateFile, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("httpd.exe", config.ApacheExe, StringComparison.OrdinalIgnoreCase);
        Assert.Contains("mysqld.exe", config.MySqldExe, StringComparison.OrdinalIgnoreCase);
    }
}
