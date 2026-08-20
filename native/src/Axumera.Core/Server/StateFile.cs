namespace Axumera.Core.Server;

/// <summary>Contents of the controller state file.</summary>
public sealed record StateFileContents(int MariaDbPid, int ApachePid, DateTimeOffset WrittenUtc);

/// <summary>
/// PID-based state file read/write with a liveness cross-check. A recorded state
/// is trusted only when the recorded PIDs are alive AND the corresponding ports
/// confirm the services; anything else is treated as stale (previous controller
/// crash) and is safe to ignore/clear.
/// </summary>
public static class StateFile
{
    public static StateFileContents? TryRead(string path)
    {
        try
        {
            if (!File.Exists(path))
            {
                return null;
            }

            var lines = File.ReadAllLines(path);
            if (lines.Length < 2)
            {
                return null;
            }

            if (!int.TryParse(lines[0].Trim(), out var maria) || !int.TryParse(lines[1].Trim(), out var apache))
            {
                return null;
            }

            return new StateFileContents(maria, apache, File.GetLastWriteTimeUtc(path));
        }
        catch (IOException)
        {
            return null;
        }
        catch (UnauthorizedAccessException)
        {
            return null;
        }
    }

    public static void Write(string path, int mariaDbPid, int apachePid)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, mariaDbPid + System.Environment.NewLine + apachePid + System.Environment.NewLine);
    }

    public static void Clear(string path)
    {
        try
        {
            if (File.Exists(path))
            {
                File.Delete(path);
            }
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    /// <summary>
    /// Live only when a recorded PID is alive AND its service port confirms it.
    /// </summary>
    public static bool IsLive(
        string path,
        Func<int, bool> pidAlive,
        Func<bool> apachePortAlive,
        Func<bool> mariaPortAlive,
        int apachePort,
        int mariaDbPort)
    {
        var contents = TryRead(path);
        if (contents is null)
        {
            return false;
        }

        bool mariaPidAlive = contents.MariaDbPid > 0 && pidAlive(contents.MariaDbPid);
        bool apachePidAlive = contents.ApachePid > 0 && pidAlive(contents.ApachePid);

        // Require both a live PID and a live port: a PID alone (or a port alone)
        // can be coincidental, but the pair is strong evidence of our runtime.
        bool mariaLive = mariaPidAlive && mariaPortAlive();
        bool apacheLive = apachePidAlive && apachePortAlive();

        if (mariaLive || apacheLive)
        {
            return true;
        }

        // Without a live PID we cannot attribute the ports to our runtime, so a
        // leftover file is treated as stale rather than live.
        return false;
    }
}
