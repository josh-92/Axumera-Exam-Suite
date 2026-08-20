using Axumera.Core.Server;
using Xunit;

namespace Axumera.Server.Tests;

public class StateFileTests : IDisposable
{
    private readonly string _tempDir;

    public StateFileTests()
    {
        _tempDir = Path.Combine(Path.GetTempPath(), "axumera-state-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_tempDir);
    }

    public void Dispose()
    {
        try
        {
            Directory.Delete(_tempDir, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    private string StatePath => Path.Combine(_tempDir, "axumera-server.state");

    [Fact]
    public void Write_and_read_roundtrip()
    {
        StateFile.Write(StatePath, 111, 222);

        var contents = StateFile.TryRead(StatePath);

        Assert.NotNull(contents);
        Assert.Equal(111, contents!.MariaDbPid);
        Assert.Equal(222, contents.ApachePid);
    }

    [Fact]
    public void Clear_removes_the_file()
    {
        StateFile.Write(StatePath, 1, 2);
        StateFile.Clear(StatePath);

        Assert.Null(StateFile.TryRead(StatePath));
    }

    [Fact]
    public void Missing_file_is_not_live()
    {
        Assert.False(StateFile.IsLive(StatePath, _ => true, () => true, () => true, 8090, 3310));
    }

    [Fact]
    public void Dead_pids_with_free_ports_are_stale_not_live()
    {
        StateFile.Write(StatePath, 999999, 999998);

        Assert.False(StateFile.IsLive(StatePath, _ => false, () => false, () => false, 8090, 3310));
    }

    [Fact]
    public void Live_pid_plus_live_port_is_live()
    {
        StateFile.Write(StatePath, 111, 222);

        Assert.True(StateFile.IsLive(
            StatePath,
            pid => pid == 222,
            () => true,
            () => false,
            8090,
            3310));
    }

    [Fact]
    public void Live_pid_with_dead_port_is_stale()
    {
        StateFile.Write(StatePath, 111, 222);

        Assert.False(StateFile.IsLive(
            StatePath,
            pid => pid == 222,
            () => false,
            () => false,
            8090,
            3310));
    }

    [Fact]
    public void Corrupt_file_is_not_live()
    {
        File.WriteAllText(StatePath, "not-a-number\n");

        Assert.Null(StateFile.TryRead(StatePath));
        Assert.False(StateFile.IsLive(StatePath, _ => true, () => true, () => true, 8090, 3310));
    }
}
