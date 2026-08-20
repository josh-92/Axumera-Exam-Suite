// =============================================================================
// Axumera_Update.exe — AXE 2.0 production updater (self-contained).
//
// Transactional, GUI-driven update of an existing installed AXUMERA deployment
// from AXE 1.0 (or any supported version) to AXE 2.0.
//
// SELF-CONTAINED PAYLOAD
//   The complete AXE 2.0 application payload is EMBEDDED inside this
//   executable as a managed resource (GZip-compressed custom container).
//   No external payload folder is required next to Axumera_Update.exe — the
//   updater extracts the payload to a temporary directory, verifies every
//   file's SHA-256 against the embedded manifest, and only then applies it.
//
// UPDATE FLOW
//   - Discovers the installed AXUMERA location from the Inno Setup uninstall
//     registry key (HKLM/HKCU, 32/64 views) or an explicit --path override.
//   - Creates a timestamped, verified pre-update backup OUTSIDE the
//     installation (%ProgramData%\Axumera Exam Suite\update-backups\...).
//   - Stops services, applies only manifest-listed application files, starts
//     MariaDB and waits for GENUINE database readiness (a real connection
//     that can run queries — not just a listening process), runs the
//     versioned migration runner (database/migrate.php), verifies the
//     migration ledger, restarts the server and health-checks it.
//   - On any failure after backup: restores the previous application files,
//     database, configuration and version, restarts the previous version, and
//     reports "The previous version has been restored."
//
// Persistent customer state (.env, storage/license.lic, installed.lock,
// data/mariadb, uploads, logs, backups, config) is never replaced.
//
// Built with the .NET Framework C# compiler (C# 5), WinForms GUI; --auto runs
// the full flow headless for automated testing and unattended operation.
// =============================================================================
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Net;
using System.Reflection;
using System.Security.Principal;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Win32;

internal static class AxumeraUpdate2
{
    const string ProductName = "Axumera Exam Suite";
    const string AppId = "{4E5B38A1-9775-4BF5-9FA6-C450A1C1FEFE}_is1";
    internal const string AppRel = "application\\eaes_exam_system";
    internal const string AppDbName = "eaes_exam";
    const string PayloadResource = "AxumeraPayload";
    const string PayloadMagic = "AXU2PAYL";

    static string ExeDir;
    static string PayloadTempDir;   // extracted embedded payload (temporary)
    static string TargetRoot;       // installed AXUMERA root
    static string BackupRoot;       // %ProgramData%\Axumera Exam Suite\update-backups
    static string LogsRoot;         // %ProgramData%\Axumera Exam Suite\logs

    static bool AutoMode = false;
    static string AutoPath = null;

    static string InstalledVersion = "1.0.0";

    // ------------------------------------------------------------------ main

    [STAThread]
    static int Main(string[] args)
    {
        // Enable long-path support (.NET Framework 4.6.2+ on Windows 10 1607+).
        // MariaDB can leave transient error artifacts with >260-character paths
        // in the data directory; without this the pre-update backup (and any
        // copy of the data directory) fails with a MAX_PATH error.
        AppContext.SetSwitch("Switch.System.IO.UseLegacyPathHandling", false);

        ExeDir = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\');
        BackupRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Axumera Exam Suite", "update-backups");
        LogsRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Axumera Exam Suite", "logs");

        for (int i = 0; i < args.Length; i++)
        {
            if (string.Equals(args[i], "--auto", StringComparison.OrdinalIgnoreCase)) AutoMode = true;
            else if (string.Equals(args[i], "--path", StringComparison.OrdinalIgnoreCase) && i + 1 < args.Length) AutoPath = args[++i];
        }

        try
        {
            Log("Updater start. Target version: " + AxumeraUpdateManifest.TargetVersion);

            // 1. Extract the embedded payload and verify it BEFORE touching
            //    anything on the target machine.
            ReportStatic("Preparing AXE 2.0 update...");
            string payloadDir = ExtractPayload();
            ReportStatic("Extracting embedded update payload...");
            VerifyPackageStatic(payloadDir);
            ReportStatic("Verifying payload...");

            TargetRoot = DiscoverTarget();
            if (TargetRoot == null)
            {
                Fail("Existing Axumera installation not found.");
                return 1;
            }

            ReadPorts();
            InstalledVersion = ReadInstalledVersion();
            Log("Installation root: " + TargetRoot);
            Log("Installed version: " + InstalledVersion + "  ->  update target: " + AxumeraUpdateManifest.TargetVersion);

            // Version guards
            // NOTE: a same-version re-apply (installed == target) is ALLOWED.
            // A corrected payload may ship under the same version number (e.g.
            // the AXE 2.0 footer fix), and machines already on that version
            // must be able to receive it. Re-applying is safe: a verified
            // backup is created first, migrations are ledger-idempotent, and
            // any failure triggers the rollback path.
            if (string.Equals(InstalledVersion, AxumeraUpdateManifest.TargetVersion, StringComparison.OrdinalIgnoreCase))
            {
                Log("Version " + AxumeraUpdateManifest.TargetVersion + " is already installed — re-applying this build so corrected payload files are delivered.");
            }
            if (CompareVersions(AxumeraUpdateManifest.TargetVersion, InstalledVersion) < 0)
            {
                Fail("Downgrade is not supported (target AXE " + ShortVersion(AxumeraUpdateManifest.TargetVersion) + " is older than installed AXE " + ShortVersion(InstalledVersion) + ").");
                return 1;
            }
            if (CompareVersions(InstalledVersion, AxumeraUpdateManifest.MinSupportedVersion) < 0)
            {
                Fail("Installed version AXE " + ShortVersion(InstalledVersion) + " is older than the minimum supported version (AXE " + ShortVersion(AxumeraUpdateManifest.MinSupportedVersion) + ").");
                return 1;
            }

            UpdateEngine engine = new UpdateEngine(payloadDir, TargetRoot, BackupRoot, LogsRoot, ApachePort, MariaPort, InstalledVersion);

            if (AutoMode)
            {
                try
                {
                    engine.Run();
                }
                finally
                {
                    CleanupPayload();
                }
                return engine.ExitCode;
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            using (UpdateForm2 form = new UpdateForm2(engine))
            {
                Application.Run(form);
            }
            CleanupPayload();
            return engine.ExitCode;
        }
        catch (Exception ex)
        {
            Fail("CRITICAL ERROR: " + ex.Message);
            CleanupPayload();
            return 1;
        }
    }

    static void Fail(string message)
    {
        Log("FAIL: " + message);
        if (AutoMode) { /* exit code communicates result */ }
        else MessageBox.Show(message, "AXUMERA UPDATE", MessageBoxButtons.OK, MessageBoxIcon.Error);
    }

    // ------------------------------------------------- embedded payload

    /// <summary>
    /// Read the embedded GZip payload container and write every entry into a
    /// fresh temporary directory. Paths are validated so the container can
    /// never write outside the extraction root.
    /// </summary>
    static string ExtractPayload()
    {
        byte[] raw;
        using (Stream stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(PayloadResource))
        {
            if (stream == null)
                throw new InvalidOperationException("The AXE 2.0 update payload is missing inside Axumera_Update.exe. This build is incomplete; obtain a full Axumera_Update.exe.");
            using (MemoryStream ms = new MemoryStream())
            {
                stream.CopyTo(ms);
                raw = ms.ToArray();
            }
        }

        byte[] data;
        using (MemoryStream compressed = new MemoryStream(raw))
        using (GZipStream gz = new GZipStream(compressed, CompressionMode.Decompress))
        using (MemoryStream outMs = new MemoryStream())
        {
            gz.CopyTo(outMs);
            data = outMs.ToArray();
        }

        List<PayloadEntry> entries = new List<PayloadEntry>();
        using (MemoryStream ms = new MemoryStream(data))
        using (BinaryReader br = new BinaryReader(ms))
        {
            string magic = new string(br.ReadChars(PayloadMagic.Length));
            if (magic != PayloadMagic)
                throw new InvalidOperationException("Embedded payload header is invalid; the update package is corrupted.");
            int containerVersion = br.ReadInt32();
            int count = br.ReadInt32();
            if (containerVersion != 1 || count <= 0 || count > 20000)
                throw new InvalidOperationException("Embedded payload has an invalid header (version/count).");
            for (int i = 0; i < count; i++)
            {
                int nameLen = br.ReadInt32();
                if (nameLen <= 0 || nameLen > 4096) throw new InvalidOperationException("Embedded payload entry name is invalid.");
                string name = Encoding.UTF8.GetString(br.ReadBytes(nameLen));
                long contentLen = br.ReadInt64();
                if (contentLen < 0 || contentLen > int.MaxValue) throw new InvalidOperationException("Embedded payload entry is too large: " + name);
                byte[] content = br.ReadBytes((int)contentLen);
                if (content.Length != (int)contentLen) throw new InvalidOperationException("Embedded payload is truncated while reading: " + name);
                entries.Add(new PayloadEntry(name, content));
            }
        }

        PayloadTempDir = Path.Combine(Path.GetTempPath(), "AxumeraUpdate_" + Guid.NewGuid().ToString("N"));
        string payloadDir = Path.Combine(PayloadTempDir, "payload");
        Directory.CreateDirectory(payloadDir);
        string payloadDirFull = Path.GetFullPath(payloadDir).TrimEnd('\\') + "\\";

        foreach (PayloadEntry entry in entries)
        {
            if (!IsSafeRelativePath(entry.Name))
                throw new InvalidOperationException("Unsafe path inside embedded payload: " + entry.Name);
            string dst = Path.GetFullPath(Path.Combine(payloadDir, entry.Name));
            if (!dst.StartsWith(payloadDirFull, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Payload entry escapes the extraction directory: " + entry.Name);
            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.WriteAllBytes(dst, entry.Content);
        }
        Log("Extracted embedded payload to " + payloadDir + " (" + entries.Count + " entries).");
        return payloadDir;
    }

    static void CleanupPayload()
    {
        try
        {
            if (!string.IsNullOrEmpty(PayloadTempDir) && Directory.Exists(PayloadTempDir))
                Directory.Delete(PayloadTempDir, true);
        }
        catch { }
    }

    static void ReportStatic(string message)
    {
        Log(message);
        Console.WriteLine(DateTime.UtcNow.ToString("o") + " [UPDATER] " + message);
    }

    static void VerifyPackageStatic(string payloadDir)
    {
        foreach (string[] entry in AxumeraUpdateManifest.Files)
        {
            string rel = entry[0];
            string expected = entry[1];
            string src = Path.Combine(payloadDir, rel);
            if (!File.Exists(src))
                throw new InvalidOperationException("Package file missing from embedded payload: " + rel);
            string actual = ComputeSha256Static(src);
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("SHA256 mismatch for package file: " + rel);
        }
        Log("Payload verified before update (" + AxumeraUpdateManifest.Files.Length + " files, hashes OK).");
    }

    static string ComputeSha256Static(string filePath)
    {
        using (System.Security.Cryptography.SHA256 sha = System.Security.Cryptography.SHA256.Create())
        using (FileStream stream = File.OpenRead(filePath))
        {
            byte[] hash = sha.ComputeHash(stream);
            StringBuilder sb = new StringBuilder();
            foreach (byte b in hash) sb.Append(b.ToString("x2"));
            return sb.ToString();
        }
    }

    // ------------------------------------------------------------ discovery

    static string DiscoverTarget()
    {
        if (!string.IsNullOrEmpty(AutoPath))
        {
            string p = Path.GetFullPath(AutoPath).TrimEnd('\\');
            if (string.Equals(p, ExeDir, StringComparison.OrdinalIgnoreCase) ||
                p.IndexOf(ExeDir, StringComparison.OrdinalIgnoreCase) == 0)
            {
                throw new InvalidOperationException("The update package location must never be used as the update target.");
            }
            if (IsValidInstallation(p)) return p;
            return null;
        }

        string[] hives = { "Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall" };
        foreach (RegistryView view in new RegistryView[] { RegistryView.Registry64, RegistryView.Registry32 })
        {
            foreach (RegistryHive hive in new RegistryHive[] { RegistryHive.LocalMachine, RegistryHive.CurrentUser })
            {
                try
                {
                    using (RegistryKey baseKey = RegistryKey.OpenBaseKey(hive, view))
                    using (RegistryKey key = baseKey.OpenSubKey(hives[0] + "\\" + AppId))
                    {
                        if (key == null) continue;
                        string loc = key.GetValue("InstallLocation") as string;
                        if (!string.IsNullOrEmpty(loc) && IsValidInstallation(loc))
                        {
                            Log("Installed AXUMERA discovered via registry (" + hive + "/" + view + "): " + loc);
                            return loc.TrimEnd('\\');
                        }
                    }
                }
                catch { }
            }
        }
        return null;
    }

    static bool IsValidInstallation(string root)
    {
        try
        {
            return File.Exists(Path.Combine(root, "AxumeraServer.exe")) &&
                   File.Exists(Path.Combine(root, "config", "ports.json")) &&
                   File.Exists(Path.Combine(root, "runtime", "apache", "bin", "httpd.exe")) &&
                   File.Exists(Path.Combine(root, "runtime", "mariadb", "bin", "mysqld.exe")) &&
                   File.Exists(Path.Combine(root, AppRel, "health.php")) &&
                   File.Exists(Path.Combine(root, AppRel, "VERSION"));
        }
        catch { return false; }
    }

    // -------------------------------------------------------------- versions

    static string ReadInstalledVersion()
    {
        string path = Path.Combine(TargetRoot, AppRel, "VERSION");
        try
        {
            if (File.Exists(path))
            {
                string v = File.ReadAllText(path).Trim();
                if (!string.IsNullOrEmpty(v)) return v;
            }
        }
        catch { }
        return "1.0.0";
    }

    internal static string ShortVersion(string v)
    {
        string[] parts = v.Split('.');
        return parts.Length >= 2 ? parts[0] + "." + parts[1] : v;
    }

    static int CompareVersions(string a, string b)
    {
        string[] pa = a.Split('.');
        string[] pb = b.Split('.');
        int n = Math.Max(pa.Length, pb.Length);
        for (int i = 0; i < n; i++)
        {
            int x = i < pa.Length ? ParseInt(pa[i]) : 0;
            int y = i < pb.Length ? ParseInt(pb[i]) : 0;
            if (x != y) return x.CompareTo(y);
        }
        return 0;
    }

    static int ParseInt(string s)
    {
        int v;
        return int.TryParse(s, out v) ? v : 0;
    }

    // ----------------------------------------------------------------- ports

    static int ApachePort = 8088;
    static int MariaPort = 3308;

    static void ReadPorts()
    {
        try
        {
            string json = File.ReadAllText(Path.Combine(TargetRoot, "config", "ports.json"));
            Match m = Regex.Match(json, "\"apache\"\\s*:\\s*(\\d+)");
            if (m.Success) ApachePort = int.Parse(m.Groups[1].Value);
            m = Regex.Match(json, "\"mariadb\"\\s*:\\s*(\\d+)");
            if (m.Success) MariaPort = int.Parse(m.Groups[1].Value);
        }
        catch { }
    }

    // ------------------------------------------------------------------ log

    static void Log(string message)
    {
        try
        {
            Directory.CreateDirectory(LogsRoot);
            string line = DateTime.UtcNow.ToString("o") + " [UPDATER] " + message;
            File.AppendAllText(Path.Combine(LogsRoot, "axumera-update.log"), line + Environment.NewLine);
        }
        catch { }
    }

    internal static bool IsSafeRelativePath(string rel)
    {
        if (string.IsNullOrEmpty(rel)) return false;
        if (Path.IsPathRooted(rel)) return false;
        string norm = rel.Replace('\\', '/');
        if (norm.StartsWith("/")) return false;
        if (norm.Contains(":")) return false;
        foreach (string seg in norm.Split('/'))
        {
            if (seg == ".." || seg == "." || seg.Length == 0) return false;
        }
        return true;
    }

    class PayloadEntry
    {
        public readonly string Name;
        public readonly byte[] Content;
        public PayloadEntry(string name, byte[] content) { Name = name; Content = content; }
    }
}

// =============================================================================
// Update engine — all state-changing work happens here, on a worker thread.
// =============================================================================
internal class UpdateEngine
{
    public event Action<string> Progress;
    public event Action<bool, string> Completed;
    public int ExitCode = 1;
    public bool AlreadyUpToDate = false;

    readonly string PayloadDir;
    readonly string TargetRoot;
    readonly string BackupRoot;
    readonly string LogsRoot;
    readonly int ApachePort;
    readonly int MariaPort;
    readonly string InstalledVersion;
    string BackupDir;
    int ReplacedCount = 0;

    public string TargetVersionText { get { return "AXE " + AxumeraUpdate2.ShortVersion(AxumeraUpdateManifest.TargetVersion); } }
    public string InstalledVersionText { get { return "AXE " + AxumeraUpdate2.ShortVersion(InstalledVersion); } }
    public static string ChangesText
    {
        get
        {
            return
                "AXE 2.0 - Registered Student Examination & Question Bank Update\r\n" +
                "\r\n" +
                "  - Registered student authentication and controlled exam access\r\n" +
                "    (student ID + password, lockout/rate limiting, no bypass)\r\n" +
                "  - Question bank architecture improvements\r\n" +
                "    (decoupled from the exam lifecycle)\r\n" +
                "  - Question bank CRUD and approval/audit functionality\r\n" +
                "  - Updated administrator functionality\r\n" +
                "    (student management, settings, archiving, bulk import)\r\n" +
                "  - Official Axumera Technologies copyright branding\r\n" +
                "  - Protected production application\r\n" +
                "  - Existing Apache/PHP/MariaDB runtime retained";
        }
    }

    public UpdateEngine(string payloadDir, string targetRoot, string backupRoot, string logsRoot, int apachePort, int mariaPort, string installedVersion)
    {
        PayloadDir = payloadDir;
        TargetRoot = targetRoot;
        BackupRoot = backupRoot;
        LogsRoot = logsRoot;
        ApachePort = apachePort;
        MariaPort = mariaPort;
        InstalledVersion = installedVersion;
    }

    void Report(string message)
    {
        if (Progress != null) Progress(message);
        try
        {
            Directory.CreateDirectory(LogsRoot);
            File.AppendAllText(Path.Combine(LogsRoot, "axumera-update.log"), DateTime.UtcNow.ToString("o") + " [UPDATER] " + message + Environment.NewLine);
        }
        catch { }
    }

    public void Run()
    {
        try
        {
            Report("Validating installation...");
            CheckPrivileges();
            CheckDiskSpace();

            Report("Verifying payload...");
            VerifyPackage();

            // Stop services BEFORE the backup so the MariaDB data directory is
            // copied in a consistent, closed state.
            Report("Stopping server...");
            if (!StopServices())
                throw new InvalidOperationException("A process is still holding the update port (" + ApachePort + "/" + MariaPort + ")." + DescribePortHolders() + " Stop that process (e.g. stop the other Axumera installation) and re-run the update; no changes were made.");

            try
            {
                Report("Creating backup...");
                CreateBackup();
                Report("Verifying backup...");
                VerifyBackup();
            }
            catch (Exception ex)
            {
                try { StartServer(); } catch { }
                throw new InvalidOperationException("Pre-update backup failed; the server was left stopped. " + ex.Message);
            }

            bool success = false;
            try
            {
                Report("Updating application...");
                ApplyFiles();

                Report("Preparing database...");
                RunMigrations();

                Report("Verifying application...");
                VerifyInstallation();

                WriteVersionFile();

                Report("Final health check...");
                StartServer();
                WaitHealth(120);

                Report("Finalizing AXE 2.0...");
                success = true;
                ExitCode = 0;
                Report("Update completed successfully: " + InstalledVersionText + " -> " + TargetVersionText);
                if (Completed != null) Completed(true, "Previous Version: " + InstalledVersionText + "\r\nCurrent Version: " + TargetVersionText + "\r\nServer Status: RUNNING\r\n\r\nAXE 2.0 has been installed successfully.");
            }
            catch (Exception ex)
            {
                Report("UPDATE FAILED: " + ex.Message);
                if (!success)
                {
                    Report("Rolling back to " + InstalledVersionText + "...");
                    string rb = Rollback();
                    if (Completed != null) Completed(false, "The previous version has been restored.\r\n\r\nPrevious Version: " + InstalledVersionText + "\r\nReason: " + ex.Message + (rb != null ? "\r\nRollback: " + rb : ""));
                }
                else if (Completed != null) Completed(false, "Update failed: " + ex.Message);
            }
        }
        catch (Exception ex)
        {
            Report("FATAL: " + ex.Message);
            if (Completed != null) Completed(false, "Update aborted before backup: " + ex.Message);
        }
    }

    // ------------------------------------------------------------- preflight

    void CheckPrivileges()
    {
        if (Environment.GetEnvironmentVariable("AXUMERA_UPDATE_ALLOW_NONADMIN") == "1")
        {
            Report("WARNING: running without elevation (AXUMERA_UPDATE_ALLOW_NONADMIN override) - test environment only.");
            return;
        }
        WindowsPrincipal principal = new WindowsPrincipal(WindowsIdentity.GetCurrent());
        if (!principal.IsInRole(WindowsBuiltInRole.Administrator))
            throw new InvalidOperationException("Administrator privileges are required to update AXUMERA.");
    }

    void CheckDiskSpace()
    {
        string drive = Path.GetPathRoot(TargetRoot);
        try
        {
            DriveInfo di = new DriveInfo(drive);
            long needed = (DirSize(Path.Combine(TargetRoot, "data", "mariadb")) +
                           DirSize(Path.Combine(TargetRoot, AppRelPath())) +
                           DirSize(PayloadDir)) * 2 + (200L * 1024 * 1024);
            if (di.AvailableFreeSpace < needed)
                throw new InvalidOperationException("Insufficient free disk space on " + drive + " (need ~" + (needed / 1048576) + " MB, have " + (di.AvailableFreeSpace / 1048576) + " MB).");
        }
        catch (InvalidOperationException) { throw; }
        catch { }
    }

    static long DirSize(string dir)
    {
        long total = 0;
        try
        {
            if (!Directory.Exists(dir)) return 0;
            foreach (string f in Directory.GetFiles(dir, "*", SearchOption.AllDirectories))
            {
                try { total += new FileInfo(f).Length; } catch { }
            }
        }
        catch { }
        return total;
    }

    // ------------------------------------------------------------ packaging

    void VerifyPackage()
    {
        foreach (string[] entry in AxumeraUpdateManifest.Files)
        {
            string rel = entry[0];
            string expected = entry[1];
            string src = Path.Combine(PayloadDir, rel);
            if (!File.Exists(src))
                throw new InvalidOperationException("Package file missing from embedded payload: " + rel);
            string actual = ComputeSha256(src);
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("SHA256 mismatch for package file: " + rel);
        }
        Report("Payload verified (" + AxumeraUpdateManifest.Files.Length + " files, hashes OK).");
    }

    static string ComputeSha256(string filePath)
    {
        using (System.Security.Cryptography.SHA256 sha = System.Security.Cryptography.SHA256.Create())
        using (FileStream stream = File.OpenRead(filePath))
        {
            byte[] hash = sha.ComputeHash(stream);
            StringBuilder sb = new StringBuilder();
            foreach (byte b in hash) sb.Append(b.ToString("x2"));
            return sb.ToString();
        }
    }

    // --------------------------------------------------------------- backup

    void CreateBackup()
    {
        string ts = DateTime.UtcNow.ToString("yyyyMMdd_HHmmss");
        BackupDir = Path.Combine(BackupRoot, "backup_" + InstalledVersion + "_" + ts);
        Directory.CreateDirectory(BackupDir);

        string dbSource = Path.Combine(TargetRoot, "data", "mariadb");
        if (Directory.Exists(dbSource)) CopyDirectory(dbSource, Path.Combine(BackupDir, "data", "mariadb"), IsDbArtifact);
        string appSource = Path.Combine(TargetRoot, AppRelPath());
        if (Directory.Exists(appSource)) CopyDirectory(appSource, Path.Combine(BackupDir, AppRelPath()));

        BackupFile("config/ports.json");
        BackupFile("config/axumera-my.ini");
    }

    void VerifyBackup()
    {
        if (!Directory.Exists(Path.Combine(BackupDir, "data", "mariadb")) ||
            Directory.GetFileSystemEntries(Path.Combine(BackupDir, "data", "mariadb")).Length == 0)
            throw new InvalidOperationException("Backup verification failed: MariaDB data directory backup is empty or missing.");
        if (!File.Exists(Path.Combine(BackupDir, AppRelPath(), ".env")))
            throw new InvalidOperationException("Backup verification failed: application .env was not captured.");
        if (!File.Exists(Path.Combine(BackupDir, AppRelPath(), "VERSION")))
            throw new InvalidOperationException("Backup verification failed: VERSION was not captured.");

        Report("Backup created and verified at " + BackupDir);
    }

    void BackupFile(string relativePath)
    {
        string src = Path.Combine(TargetRoot, relativePath);
        if (File.Exists(src))
        {
            string dst = Path.Combine(BackupDir, relativePath);
            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.Copy(src, dst, true);
        }
    }

    // -------------------------------------------------------------- services

    bool StopServices()
    {
        string controller = Path.Combine(TargetRoot, "AxumeraServer.exe");
        if (File.Exists(controller))
        {
            try
            {
                Process p = Process.Start(new ProcessStartInfo(controller, "stop") { UseShellExecute = false, CreateNoWindow = true });
                if (p != null) p.WaitForExit(15000);
            }
            catch { }
        }
        KillProcessesUnderRoot("httpd");
        KillProcessesUnderRoot("mysqld");

        for (int i = 0; i < 45; i++)
        {
            if (!IsApacheAlive() && !IsMariaAlive()) return true;
            Thread.Sleep(1000);
        }
        bool free = !IsApacheAlive() && !IsMariaAlive();
        if (!free)
            Report("WARNING: update port " + ApachePort + "/" + MariaPort + " is still occupied after stopping services." + DescribePortHolders());
        return free;
    }

    /// <summary>
    /// Identify exactly which process(es) hold the update ports, so the
    /// administrator can stop the conflicting program (usually another
    /// Axumera installation on the same machine) instead of guessing.
    /// </summary>
    string DescribePortHolders()
    {
        StringBuilder sb = new StringBuilder();
        foreach (int port in new[] { ApachePort, MariaPort })
        {
            if (!IsTcpOpen(port)) continue;
            string pid = FindPidOnPort(port);
            sb.Append(" Port " + port + " is held by PID " + (pid ?? "?") + " (" + DescribeProcess(pid) + ").");
        }
        return sb.ToString();
    }

    static string FindPidOnPort(int port)
    {
        try
        {
            ProcessStartInfo psi = new ProcessStartInfo("netstat", "-ano -p tcp")
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };
            Process p = Process.Start(psi);
            if (p == null) return null;
            string output = p.StandardOutput.ReadToEnd();
            p.WaitForExit(3000);
            foreach (string line in output.Split('\n'))
            {
                string t = line.Trim();
                if (!t.StartsWith("TCP", StringComparison.OrdinalIgnoreCase)) continue;
                string[] parts = t.Split(new[] { ' ', '\t' }, StringSplitOptions.RemoveEmptyEntries);
                if (parts.Length >= 5 && parts[1].EndsWith(":" + port) && string.Equals(parts[3], "LISTENING", StringComparison.OrdinalIgnoreCase))
                    return parts[4];
            }
        }
        catch { }
        return null;
    }

    static string DescribeProcess(string pid)
    {
        if (string.IsNullOrEmpty(pid)) return "unknown process";
        try
        {
            Process p = Process.GetProcessById(int.Parse(pid));
            try { return p.ProcessName + " at " + p.MainModule.FileName; }
            catch { return p.ProcessName; }
        }
        catch { return "process no longer running"; }
    }

    void KillProcessesUnderRoot(string name)
    {
        foreach (Process p in Process.GetProcessesByName(name))
        {
            try
            {
                if (p.MainModule.FileName.StartsWith(TargetRoot, StringComparison.OrdinalIgnoreCase)) { p.Kill(); p.WaitForExit(3000); }
            }
            catch { }
        }
    }

    bool IsApacheAlive() { return IsTcpOpen(ApachePort); }
    bool IsMariaAlive() { return IsTcpOpen(MariaPort); }

    static bool IsTcpOpen(int port)
    {
        try
        {
            using (System.Net.Sockets.TcpClient client = new System.Net.Sockets.TcpClient())
            {
                IAsyncResult result = client.BeginConnect("127.0.0.1", port, null, null);
                if (!result.AsyncWaitHandle.WaitOne(1000)) return false;
                client.EndConnect(result);
                return true;
            }
        }
        catch { return false; }
    }

    // ------------------------------------------------------------- applying

    string AppRelPath() { return AxumeraUpdate2.AppRel; }

    void ApplyFiles()
    {
        string appDir = Path.Combine(TargetRoot, AppRelPath());
        string appDirFull = Path.GetFullPath(appDir).TrimEnd('\\') + "\\";
        ReplacedCount = 0;
        foreach (string[] entry in AxumeraUpdateManifest.Files)
        {
            string rel = entry[0];
            if (!AxumeraUpdate2.IsSafeRelativePath(rel))
                throw new InvalidOperationException("Unsafe path in update manifest: " + rel);

            string src = Path.Combine(PayloadDir, rel);
            string dst = Path.Combine(appDir, rel);
            string dstFull = Path.GetFullPath(dst);
            if (!dstFull.StartsWith(appDirFull, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Update file escapes the application directory: " + rel);

            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.Copy(src, dst, true);
            ReplacedCount++;
        }
        int removed = RemoveStaleFiles(appDirFull);
        Report("Applied " + ReplacedCount + " application file(s); removed " + removed + " obsolete file(s).");
    }

    /// <summary>
    /// Delete application files that exist in the installation but are not
    /// part of the AXE 2.0 manifest (removed pages, obsolete migrations, old
    /// assets). Persistent customer state (.env, storage/**, VERSION) is
    /// never touched — VERSION is rewritten by the updater itself.
    /// </summary>
    int RemoveStaleFiles(string appDirFull)
    {
        string appDir = Path.Combine(TargetRoot, AppRelPath());
        HashSet<string> manifest = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (string[] entry in AxumeraUpdateManifest.Files) manifest.Add(entry[0].Replace('\\', '/'));

        int removed = 0;
        foreach (string file in Directory.GetFiles(appDir, "*", SearchOption.AllDirectories))
        {
            string rel = file.Substring(appDir.Length + 1).Replace('\\', '/');
            if (manifest.Contains(rel)) continue;
            if (IsPersistentAppPath(rel)) continue;
            try { File.Delete(file); removed++; } catch { }
        }
        return removed;
    }

    static bool IsPersistentAppPath(string rel)
    {
        string r = rel.ToLowerInvariant();
        if (r == ".env" || r == ".env.example" || r == "version") return true;
        if (r.StartsWith("storage/")) return true;
        return false;
    }

    void WriteVersionFile()
    {
        File.WriteAllText(Path.Combine(TargetRoot, AppRelPath(), "VERSION"), AxumeraUpdateManifest.TargetVersion, new UTF8Encoding(false));
        Report("Version file updated to " + AxumeraUpdateManifest.TargetVersion + ".");
    }

    void VerifyInstallation()
    {
        string appDir = Path.Combine(TargetRoot, AppRelPath());
        if (!File.Exists(Path.Combine(appDir, "health.php")))
            throw new InvalidOperationException("Application verification failed: health.php is missing after update.");
        if (!File.Exists(Path.Combine(appDir, "slogin.php")))
            throw new InvalidOperationException("Application verification failed: student login (slogin.php) is missing after update.");
        if (!File.Exists(Path.Combine(appDir, "admin_question_bank.php")))
            throw new InvalidOperationException("Application verification failed: admin_question_bank.php is missing after update.");
        if (!File.Exists(Path.Combine(appDir, "database", "migrate.php")))
            throw new InvalidOperationException("Application verification failed: database/migrate.php is missing after update.");
    }

    // ------------------------------------------------------------ migrations

    void RunMigrations()
    {
        RegenerateMyIni();
        CleanTransientDbArtifacts();
        StartMariaDB();
        try
        {
            RunMigrationScript();
            Report("Verifying database...");
            VerifyMigrationLedger();
        }
        finally
        {
            StopMariaDB();
        }
    }

    void RegenerateMyIni()
    {
        // MariaDB option files treat '\' as an escape character, so paths MUST
        // use forward slashes (identical to the controller's generated config).
        string runtime = Path.Combine(TargetRoot, "runtime").Replace('\\', '/');
        string data = Path.Combine(TargetRoot, "data").Replace('\\', '/');
        string logs = Path.Combine(TargetRoot, "logs").Replace('\\', '/');
        string content =
            "[client]\nhost=127.0.0.1\nport=" + MariaPort + "\n\n" +
            "[mysqld]\nbasedir=\"" + runtime + "/mariadb\"" +
            "\ndatadir=\"" + data + "/mariadb\"" +
            "\ntmpdir=\"" + data + "/tmp\"" +
            "\nport=" + MariaPort +
            "\nbind-address=127.0.0.1" +
            "\ncharacter-set-server=utf8mb4" +
            "\ncollation-server=utf8mb4_general_ci" +
            "\ndefault-storage-engine=InnoDB" +
            "\nlog_error=\"" + logs + "/mariadb-error.log\"" +
            "\npid-file=\"" + data + "/mariadb/axumera-mariadb.pid\"" +
            "\n";
        File.WriteAllText(Path.Combine(TargetRoot, "config", "axumera-my.ini"), content, Encoding.ASCII);
        Report("Database configuration prepared.");
    }

    /// <summary>
    /// Start MariaDB and wait for GENUINE readiness: the TCP port is open,
    /// a real query executes, the expected Axumera database exists, and
    /// queries can run inside it. A listening process alone is NOT readiness.
    /// </summary>
    void StartMariaDB()
    {
        string mysqld = Path.Combine(TargetRoot, "runtime", "mariadb", "bin", "mysqld.exe");
        string ini = Path.Combine(TargetRoot, "config", "axumera-my.ini");
        Process.Start(new ProcessStartInfo(mysqld, "--defaults-file=\"" + ini + "\"") { UseShellExecute = false, CreateNoWindow = true });

        string mysql = Path.Combine(TargetRoot, "runtime", "mariadb", "bin", "mysql.exe");
        string lastError = "no response";
        for (int i = 0; i < 60; i++)
        {
            // 1. A real query executes (proves TCP + auth + server up).
            if (RunSql(mysql, "SELECT 1", out lastError))
            {
                // 2. The expected Axumera database exists.
                string existsOut;
                if (RunSql(mysql, "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + AxumeraUpdate2.AppDbName + "'", out existsOut) &&
                    existsOut.Trim() == "1")
                {
                    // 3. Queries can execute inside the database.
                    if (RunSql(mysql, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='" + AxumeraUpdate2.AppDbName + "'", out existsOut))
                    {
                        Report("Database ready: " + AxumeraUpdate2.AppDbName + " reachable and queryable.");
                        return;
                    }
                }
                lastError = "database '" + AxumeraUpdate2.AppDbName + "' not present yet";
            }
            Thread.Sleep(1000);
        }
        throw new InvalidOperationException("MariaDB did not become ready within 60s (" + lastError + "); see logs/mariadb-error.log.");
    }

    bool RunSql(string mysql, string sql, out string errorOrOutput, string database = null)
    {
        errorOrOutput = "";
        try
        {
            string args = "--protocol=tcp -h 127.0.0.1 -P " + MariaPort + " -u root" + (string.IsNullOrEmpty(database) ? "" : " " + database) + " -N -e \"" + sql.Replace("\"", "\\\"") + "\"";
            ProcessStartInfo psi = new ProcessStartInfo(mysql, args) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
            Process p = Process.Start(psi);
            string output = p != null ? p.StandardOutput.ReadToEnd() : "";
            string err = p != null ? p.StandardError.ReadToEnd() : "";
            if (p != null) p.WaitForExit(5000);
            if (p != null && p.ExitCode == 0) { errorOrOutput = output; return true; }
            errorOrOutput = (err.Length > 0 ? err.Trim() : output.Trim());
            return false;
        }
        catch (Exception ex) { errorOrOutput = ex.Message; return false; }
    }

    void StopMariaDB()
    {
        string admin = Path.Combine(TargetRoot, "runtime", "mariadb", "bin", "mysqladmin.exe");
        try
        {
            Process p = Process.Start(new ProcessStartInfo(admin, "--protocol=tcp -h 127.0.0.1 -P " + MariaPort + " shutdown") { UseShellExecute = false, CreateNoWindow = true });
            if (p != null) p.WaitForExit(5000);
        }
        catch { }
        foreach (Process proc in Process.GetProcessesByName("mysqld"))
        {
            try { if (proc.MainModule.FileName.StartsWith(TargetRoot, StringComparison.OrdinalIgnoreCase)) { proc.Kill(); proc.WaitForExit(3000); } }
            catch { }
        }
        Thread.Sleep(1000);
    }

    void RunMigrationScript()
    {
        string php = Path.Combine(TargetRoot, "runtime", "php", "php.exe");
        string script = Path.Combine(TargetRoot, AppRelPath(), "database", "migrate.php");
        if (!File.Exists(script))
            throw new InvalidOperationException("Migration runner database/migrate.php is missing.");

        ProcessStartInfo psi = new ProcessStartInfo(php, "\"" + script + "\"")
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = Path.Combine(TargetRoot, AppRelPath())
        };
        Process p = Process.Start(psi);
        string outText = p != null ? p.StandardOutput.ReadToEnd() : "";
        string errText = p != null ? p.StandardError.ReadToEnd() : "";
        if (p != null) p.WaitForExit();

        foreach (string line in outText.Split(new[] { '\r', '\n' }, StringSplitOptions.RemoveEmptyEntries)) Report("  " + line.Trim());
        if (p == null || p.ExitCode != 0)
            throw new InvalidOperationException("Database migration failed: " + (errText.Length > 0 ? errText.Trim() : outText.Trim()));
        Report("Migrations applied successfully.");
    }

    void VerifyMigrationLedger()
    {
        string mysql = Path.Combine(TargetRoot, "runtime", "mariadb", "bin", "mysql.exe");
        string[] required = AxumeraUpdateManifest.Migrations;
        string inList = "'" + string.Join("','", required) + "'";
        string query = "SELECT COUNT(DISTINCT filename) FROM schema_migrations WHERE filename IN (" + inList + ");";
        string output;
        if (!RunSql(mysql, query, out output, AxumeraUpdate2.AppDbName))
            throw new InvalidOperationException("Migration ledger verification could not run: " + output);
        int found;
        if (!int.TryParse(output.Trim(), out found) || found != required.Length)
            throw new InvalidOperationException("Migration ledger verification failed: " + found + "/" + required.Length + " expected migrations recorded (" + output.Trim() + ").");
        Report("Database verified: all " + required.Length + " AXE 2.0 migrations recorded in the schema_migrations ledger.");
    }

    // ---------------------------------------------------------- restart/health

    void StartServer()
    {
        string controller = Path.Combine(TargetRoot, "AxumeraServer.exe");
        if (!File.Exists(controller)) throw new InvalidOperationException("AxumeraServer.exe is missing in the installation.");
        Process.Start(new ProcessStartInfo(controller, "start") { UseShellExecute = true });
    }

    void WaitHealth(int seconds)
    {
        for (int i = 0; i < seconds; i++)
        {
            try
            {
                HttpWebRequest req = (HttpWebRequest)WebRequest.Create("http://127.0.0.1:" + ApachePort + "/health.php");
                req.Timeout = 2000;
                using (HttpWebResponse res = (HttpWebResponse)req.GetResponse())
                {
                    if (res.StatusCode == HttpStatusCode.OK)
                    {
                        using (StreamReader reader = new StreamReader(res.GetResponseStream()))
                        {
                            string body = reader.ReadToEnd();
                            if (body.Contains("\"status\":") && body.Contains("\"ok\""))
                            {
                                Report("Health check passed (HTTP 200 OK).");
                                return;
                            }
                        }
                    }
                }
            }
            catch { }
            Thread.Sleep(1000);
        }
        throw new InvalidOperationException("Health check failed after the update; the server did not become operational.");
    }

    // --------------------------------------------------------------- rollback

    string Rollback()
    {
        string message = null;
        try
        {
            if (!StopServices()) Report("WARNING: a process still holds the update port; continuing the restore anyway.");

            string dbBackup = Path.Combine(BackupDir, "data", "mariadb");
            string dbTarget = Path.Combine(TargetRoot, "data", "mariadb");
            if (Directory.Exists(dbBackup))
            {
                if (Directory.Exists(dbTarget)) Directory.Delete(dbTarget, true);
                CopyDirectory(dbBackup, dbTarget);
            }

            string appBackup = Path.Combine(BackupDir, AppRelPath());
            string appTarget = Path.Combine(TargetRoot, AppRelPath());
            if (Directory.Exists(appBackup))
            {
                if (Directory.Exists(appTarget)) Directory.Delete(appTarget, true);
                CopyDirectory(appBackup, appTarget);
            }

            RestoreFile("config/ports.json");
            RestoreFile("config/axumera-my.ini");

            StartServer();
            WaitHealth(180);
            Report("Rollback complete: " + InstalledVersionText + " restored and operational.");
        }
        catch (Exception ex)
        {
            message = "Rollback error: " + ex.Message;
            Report(message);
        }
        return message;
    }

    void RestoreFile(string relativePath)
    {
        string src = Path.Combine(BackupDir, relativePath);
        if (File.Exists(src))
        {
            string dst = Path.Combine(TargetRoot, relativePath);
            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.Copy(src, dst, true);
        }
    }

    static void CopyDirectory(string sourceDir, string destinationDir, Func<string, bool> skipFile = null)
    {
        Directory.CreateDirectory(destinationDir);
        foreach (string file in Directory.GetFiles(sourceDir))
        {
            if (skipFile != null && skipFile(file)) continue;
            File.Copy(file, Path.Combine(destinationDir, Path.GetFileName(file)), true);
        }
        foreach (string subDir in Directory.GetDirectories(sourceDir))
        {
            if (skipFile != null && skipFile(subDir)) continue;
            CopyDirectory(subDir, Path.Combine(destinationDir, Path.GetFileName(subDir)), skipFile);
        }
    }

    // MariaDB writes transient error artifacts into the data directory when a
    // startup attempt fails (e.g. master-version@002.../relay-bin note files).
    // They are never legitimate data, their %-encoded names can exceed
    // MAX_PATH, and their presence makes subsequent startups fail. They must
    // never be backed up (or restored), and they must be removed from the live
    // data directory before the migration database is started.
    static bool IsTransientDbArtifact(string path)
    {
        return Path.GetFileName(path).IndexOf("@002", StringComparison.OrdinalIgnoreCase) >= 0;
    }

    // MariaDB 10.4's multi-source replication writes its startup log lines into
    // multi-master.info when the error log cannot be opened (e.g. a failed,
    // non-elevated start against a protected install). A healthy file is empty
    // (the application never uses replication); a corrupted one grows to many
    // KB of log lines that mysqld later misreads as replication entries, fails
    // to persist (their %-encoded names exceed MAX_PATH, errno 38), and aborts
    // with "Failed to initialize multi master structures" on the next startup.
    static bool IsCorruptMasterInfo(string path)
    {
        if (!string.Equals(Path.GetFileName(path), "multi-master.info", StringComparison.OrdinalIgnoreCase)) return false;
        try
        {
            if (new FileInfo(path).Length > 512) return true;   // healthy file is empty / tiny
            string text = File.ReadAllText(path);
            return text.IndexOf("@002", StringComparison.OrdinalIgnoreCase) >= 0
                || text.Contains("[Note]") || text.Contains("[Warning]") || text.Contains("[ERROR]");
        }
        catch { return false; }
    }

    static bool IsDbArtifact(string path)
    {
        return IsTransientDbArtifact(path) || IsCorruptMasterInfo(path);
    }

    void CleanTransientDbArtifacts()
    {
        string dbDir = Path.Combine(TargetRoot, "data", "mariadb");
        if (!Directory.Exists(dbDir)) return;
        int removed = 0;
        foreach (string file in Directory.GetFiles(dbDir))
        {
            if (!IsDbArtifact(file)) continue;
            try { File.Delete(file); removed++; }
            catch (Exception ex) { Report("WARNING: could not remove broken DB artifact " + Path.GetFileName(file) + ": " + ex.Message); }
        }
        if (removed > 0) Report("Removed " + removed + " broken MariaDB startup artifact(s) from the data directory (log-file corruption leftovers that block startup).");
    }
}

// =============================================================================
// WinForms GUI
// =============================================================================
internal class UpdateForm2 : Form
{
    readonly UpdateEngine Engine;
    readonly Label LblCurrent;
    readonly Label LblNew;
    readonly TextBox TxtChanges;
    readonly Button BtnInstall;
    readonly Button BtnClose;
    readonly RichTextBox TxtProgress;
    Thread Worker;

    public UpdateForm2(UpdateEngine engine)
    {
        Engine = engine;
        Text = "AXUMERA UPDATE";
        Font = new System.Drawing.Font("Segoe UI", 9.5f);
        ClientSize = new System.Drawing.Size(680, 560);
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;

        Label title = new Label { Text = "AXUMERA UPDATE", Font = new System.Drawing.Font("Segoe UI", 16f, System.Drawing.FontStyle.Bold), AutoSize = true, Location = new System.Drawing.Point(20, 14) };
        Controls.Add(title);

        LblCurrent = new Label { Text = "Current Version: " + Engine.InstalledVersionText, AutoSize = true, Location = new System.Drawing.Point(22, 52) };
        LblNew = new Label { Text = "New Version: " + Engine.TargetVersionText, AutoSize = true, Location = new System.Drawing.Point(22, 76) };
        Controls.Add(LblCurrent);
        Controls.Add(LblNew);

        Label lblChanges = new Label { Text = "Changes:", AutoSize = true, Location = new System.Drawing.Point(22, 104) };
        Controls.Add(lblChanges);

        TxtChanges = new TextBox { Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, Location = new System.Drawing.Point(22, 126), Size = new System.Drawing.Size(636, 130), Text = UpdateEngine.ChangesText };
        Controls.Add(TxtChanges);

        BtnInstall = new Button { Text = "Install Update", Location = new System.Drawing.Point(22, 268), Size = new System.Drawing.Size(140, 34) };
        BtnInstall.Click += BtnInstall_Click;
        Controls.Add(BtnInstall);

        BtnClose = new Button { Text = "Close", Location = new System.Drawing.Point(168, 268), Size = new System.Drawing.Size(90, 34), Visible = false };
        BtnClose.Click += (s, e) => Close();
        Controls.Add(BtnClose);

        Label lblProgress = new Label { Text = "Progress:", AutoSize = true, Location = new System.Drawing.Point(22, 310) };
        Controls.Add(lblProgress);

        TxtProgress = new RichTextBox { ReadOnly = true, Location = new System.Drawing.Point(22, 332), Size = new System.Drawing.Size(636, 200), BackColor = System.Drawing.Color.White };
        Controls.Add(TxtProgress);

        Engine.Progress += OnProgress;
        Engine.Completed += OnCompleted;
    }

    void BtnInstall_Click(object sender, EventArgs e)
    {
        if (MessageBox.Show(
            "This will update AXUMERA from " + Engine.InstalledVersionText + " to " + Engine.TargetVersionText + ".\n\nThe update payload is embedded in this program; a verified backup will be created first, and the server will be stopped during the update.\n\nContinue?",
            "AXUMERA UPDATE - Confirm",
            MessageBoxButtons.YesNo,
            MessageBoxIcon.Question) != DialogResult.Yes) return;

        BtnInstall.Enabled = false;
        TxtProgress.Clear();
        Worker = new Thread(Engine.Run);
        Worker.IsBackground = true;
        Worker.Start();
    }

    void OnProgress(string line)
    {
        if (IsDisposed) return;
        BeginInvoke((Action)(() =>
        {
            TxtProgress.AppendText(line + Environment.NewLine);
            TxtProgress.ScrollToCaret();
        }));
    }

    void OnCompleted(bool success, string summary)
    {
        if (IsDisposed) return;
        BeginInvoke((Action)(() =>
        {
            BtnClose.Visible = true;
            if (success)
            {
                MessageBox.Show(summary, "AXUMERA UPDATE - Success", MessageBoxButtons.OK, MessageBoxIcon.Information);
            }
            else
            {
                MessageBox.Show(summary, "AXUMERA UPDATE - Failed", MessageBoxButtons.OK, MessageBoxIcon.Error);
                BtnInstall.Enabled = true;
            }
        }));
    }
}
