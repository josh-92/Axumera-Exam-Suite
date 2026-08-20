// =============================================================================
// Axumera_Update.exe — AXE 1.1 production updater.
//
// Transactional, GUI-driven update of an existing installed AXUMERA deployment
// from one released version to the next (e.g. AXE 1.0 -> AXE 1.1).
//
// - Discovers the installed AXUMERA location from the Inno Setup uninstall
//   registry key (HKLM/HKCU, 32/64 views) or an explicit --path override.
//   It never treats its own directory as the customer installation.
// - Treats the update package next to itself as READ-ONLY source material.
// - Creates a timestamped, verified pre-update backup OUTSIDE the installation
//   (%ProgramData%\Axumera Exam Suite\update-backups\...).
// - Stops services, applies only manifest-listed application files, runs the
//   versioned migration runner, restarts the server and health-checks it.
// - On any failure after backup: restores the previous application files,
//   database, configuration and version, restarts the previous version, and
//   reports "The previous version has been restored."
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
using System.Net;
using System.Security.Principal;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Win32;

internal static class AxumeraUpdate11
{
    const string ProductName = "Axumera Exam Suite";
    const string AppId = "{4E5B38A1-9775-4BF5-9FA6-C450A1C1FEFE}_is1";
    internal const string AppRel = "application\\eaes_exam_system";
    internal const string AppDbName = "eaes_exam";

    static string ExeDir;
    static string SourceRoot;   // <exeDir>\eaes_exam_system_protected  (READ-ONLY)
    static string TargetRoot;   // installed AXUMERA root
    static string BackupRoot;   // %ProgramData%\Axumera Exam Suite\update-backups
    static string LogsRoot;     // %ProgramData%\Axumera Exam Suite\logs

    static bool AutoMode = false;
    static string AutoPath = null;

    static string InstalledVersion = "1.0.0";

    // ------------------------------------------------------------------ main

    [STAThread]
    static int Main(string[] args)
    {
        ExeDir = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\');
        SourceRoot = Path.Combine(ExeDir, "eaes_exam_system_protected");
        BackupRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Axumera Exam Suite", "update-backups");
        LogsRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Axumera Exam Suite", "logs");

        for (int i = 0; i < args.Length; i++)
        {
            if (string.Equals(args[i], "--auto", StringComparison.OrdinalIgnoreCase)) AutoMode = true;
            else if (string.Equals(args[i], "--path", StringComparison.OrdinalIgnoreCase) && i + 1 < args.Length) AutoPath = args[++i];
        }

        try
        {
            if (!Directory.Exists(SourceRoot))
            {
                Fail("Update package payload (eaes_exam_system_protected) was not found next to Axumera_Update.exe.");
                return 1;
            }

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
            if (string.Equals(InstalledVersion, AxumeraUpdateManifest.TargetVersion, StringComparison.OrdinalIgnoreCase))
            {
                Log("Version " + AxumeraUpdateManifest.TargetVersion + " is already installed.");
                if (AutoMode) return 0;
                MessageBox.Show("AXUMERA is already up to date (AXE " + ShortVersion(InstalledVersion) + ").", "AXUMERA UPDATE", MessageBoxButtons.OK, MessageBoxIcon.Information);
                return 0;
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

            UpdateEngine engine = new UpdateEngine(SourceRoot, TargetRoot, BackupRoot, LogsRoot, ApachePort, MariaPort, InstalledVersion);

            if (AutoMode)
            {
                engine.Run();
                return engine.ExitCode;
            }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            using (UpdateForm form = new UpdateForm(engine))
            {
                Application.Run(form);
            }
            return engine.ExitCode;
        }
        catch (Exception ex)
        {
            Fail("CRITICAL ERROR: " + ex.Message);
            return 1;
        }
    }

    static void Fail(string message)
    {
        Log("FAIL: " + message);
        if (AutoMode) { /* exit code communicates result */ }
        else MessageBox.Show(message, "AXUMERA UPDATE", MessageBoxButtons.OK, MessageBoxIcon.Error);
    }

    // ------------------------------------------------------------ discovery

    static string DiscoverTarget()
    {
        if (!string.IsNullOrEmpty(AutoPath))
        {
            string p = Path.GetFullPath(AutoPath).TrimEnd('\\');
            if (string.Equals(p, ExeDir, StringComparison.OrdinalIgnoreCase) ||
                string.Equals(p, SourceRoot, StringComparison.OrdinalIgnoreCase) ||
                p.IndexOf(SourceRoot, StringComparison.OrdinalIgnoreCase) == 0)
            {
                throw new InvalidOperationException("The update source must never be used as the update target.");
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

    readonly string SourceRoot;
    readonly string TargetRoot;
    readonly string BackupRoot;
    readonly string LogsRoot;
    readonly int ApachePort;
    readonly int MariaPort;
    readonly string InstalledVersion;
    string BackupDir;
    int ReplacedCount = 0;

    public string TargetVersionText { get { return "AXE " + AxumeraUpdate11.ShortVersion(AxumeraUpdateManifest.TargetVersion); } }
    public string InstalledVersionText { get { return "AXE " + AxumeraUpdate11.ShortVersion(InstalledVersion); } }
    public static string ChangesText
    {
        get
        {
            return
                "AXE 1.1 - Question Bank & Production Update\r\n" +
                "\r\n" +
                "  - Standalone Question Bank with full CRUD management\r\n" +
                "    (admin_question_bank.php, api_questions.php, question_bank.js/css)\r\n" +
                "  - Question bank decoupled from the exam lifecycle\r\n" +
                "    (schema migration 2026_08_05_decouple_question_bank)\r\n" +
                "  - Question bank CRUD schema + approval/audit columns\r\n" +
                "    (schema migration 2026_08_06_add_question_bank_crud)\r\n" +
                "  - Official copyright footer on admin & login pages\r\n" +
                "  - Protected (obfuscated) application sources\r\n" +
                "  - No runtime changes - Apache/PHP/MariaDB retained";
        }
    }

    public UpdateEngine(string sourceRoot, string targetRoot, string backupRoot, string logsRoot, int apachePort, int mariaPort, string installedVersion)
    {
        SourceRoot = sourceRoot;
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
            Report("Checking installation...");
            CheckPrivileges();
            CheckDiskSpace();

            Report("Verifying update package...");
            VerifyPackage();

            // Stop services BEFORE the backup so the MariaDB data directory is copied in a
            // consistent, closed state (a live-copy of a running InnoDB data dir is not safe
            // to restore from). If the backup fails after stopping, bring the server back up
            // before reporting failure.
            Report("Stopping server...");
            if (!StopServices())
                throw new InvalidOperationException("A process is still holding the update port (" + ApachePort + "/" + MariaPort + "). Close it and re-run the update; no changes were made.");

            try
            {
                Report("Creating backup...");
                CreateBackup();
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

                Report("Running database migrations...");
                RunMigrations();

                Report("Verifying installation...");
                WriteVersionFile();
                VerifyInstallation();

                Report("Starting server...");
                StartServer();

                Report("Running health check...");
                WaitHealth(90);

                success = true;
                ExitCode = 0;
                Report("Update completed successfully: " + InstalledVersionText + " -> " + TargetVersionText);
                if (Completed != null) Completed(true, "Previous Version: " + InstalledVersionText + "\r\nCurrent Version: " + TargetVersionText + "\r\nServer Status: RUNNING\r\n\r\nThe application has been successfully updated.");
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
        // Production requires elevation (the installation lives under Program Files).
        // Automated/CI tests against a user-writable clone may opt out explicitly;
        // this override is off by default and logged when used.
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
                           DirSize(SourceRoot)) * 2 + (200L * 1024 * 1024);
            if (di.AvailableFreeSpace < needed)
                throw new InvalidOperationException("Insufficient free disk space on " + drive + " (need ~" + (needed / 1048576) + " MB, have " + (di.AvailableFreeSpace / 1048576) + " MB).");
        }
        catch (InvalidOperationException) { throw; }
        catch { /* drive info unavailable — proceed */ }
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
        string appSource = Path.Combine(SourceRoot);
        foreach (string[] entry in AxumeraUpdateManifest.Files)
        {
            string rel = entry[0];
            string expected = entry[1];
            string src = Path.Combine(appSource, rel);
            if (!File.Exists(src))
                throw new InvalidOperationException("Package file missing: " + rel);
            string actual = ComputeSha256(src);
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("SHA256 mismatch for package file: " + rel);
        }
        Report("Package verified (" + AxumeraUpdateManifest.Files.Length + " files, hashes OK).");
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
        if (Directory.Exists(dbSource)) CopyDirectory(dbSource, Path.Combine(BackupDir, "data", "mariadb"));
        string appSource = Path.Combine(TargetRoot, AppRelPath());
        if (Directory.Exists(appSource)) CopyDirectory(appSource, Path.Combine(BackupDir, AppRelPath()));

        BackupFile("config/ports.json");
        BackupFile("config/axumera-my.ini");

        // Verify backup is usable
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

        for (int i = 0; i < 30; i++)
        {
            if (!IsApacheAlive() && !IsMariaAlive()) return true;
            Thread.Sleep(1000);
        }
        bool free = !IsApacheAlive() && !IsMariaAlive();
        if (!free) Report("WARNING: update port " + ApachePort + "/" + MariaPort + " is still occupied after stopping services.");
        return free;
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

    string AppRelPath() { return AxumeraUpdate11.AppRel; }

    void ApplyFiles()
    {
        string appDir = Path.Combine(TargetRoot, AppRelPath());
        string appDirFull = Path.GetFullPath(appDir).TrimEnd('\\') + "\\";
        ReplacedCount = 0;
        foreach (string[] entry in AxumeraUpdateManifest.Files)
        {
            string rel = entry[0];
            if (!IsSafeRelativePath(rel))
                throw new InvalidOperationException("Unsafe path in update manifest: " + rel);

            string src = Path.Combine(SourceRoot, rel);
            string dst = Path.Combine(appDir, rel);
            string dstFull = Path.GetFullPath(dst);
            if (!dstFull.StartsWith(appDirFull, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("Update file escapes the application directory: " + rel);

            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.Copy(src, dst, true);
            ReplacedCount++;
        }
        Report("Applied " + ReplacedCount + " application file(s).");
    }

    static bool IsSafeRelativePath(string rel)
    {
        if (string.IsNullOrEmpty(rel)) return false;
        if (Path.IsPathRooted(rel)) return false;
        string norm = rel.Replace('\\', '/');
        if (norm.StartsWith("/")) return false;
        if (norm.Contains(":")) return false; // drive letters / NTFS streams are never valid in package paths
        foreach (string seg in norm.Split('/'))
        {
            if (seg == ".." || seg == "." || seg.Length == 0) return false;
        }
        return true;
    }

    void WriteVersionFile()
    {
        // BOM-free UTF-8, matching the format shipped by the installer.
        File.WriteAllText(Path.Combine(TargetRoot, AppRelPath(), "VERSION"), AxumeraUpdateManifest.TargetVersion, new UTF8Encoding(false));
    }

    void VerifyInstallation()
    {
        string appDir = Path.Combine(TargetRoot, AppRelPath());
        if (!File.Exists(Path.Combine(appDir, "health.php")))
            throw new InvalidOperationException("Application verification failed: health.php is missing after update.");
        if (!File.Exists(Path.Combine(appDir, "admin_question_bank.php")))
            throw new InvalidOperationException("Application verification failed: admin_question_bank.php is missing after update.");
    }

    // ------------------------------------------------------------ migrations

    void RunMigrations()
    {
        RegenerateMyIni();
        StartMariaDB();
        try
        {
            RunMigrationScript();
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
    }

    void StartMariaDB()
    {
        string mysqld = Path.Combine(TargetRoot, "runtime", "mariadb", "bin", "mysqld.exe");
        string ini = Path.Combine(TargetRoot, "config", "axumera-my.ini");
        Process.Start(new ProcessStartInfo(mysqld, "--defaults-file=\"" + ini + "\"") { UseShellExecute = false, CreateNoWindow = true });

        string admin = Path.Combine(TargetRoot, "runtime", "mariadb", "bin", "mysqladmin.exe");
        for (int i = 0; i < 30; i++)
        {
            Process p = Process.Start(new ProcessStartInfo(admin, "--protocol=tcp -h 127.0.0.1 -P " + MariaPort + " ping --silent") { UseShellExecute = false, CreateNoWindow = true });
            if (p != null) p.WaitForExit(3000);
            if (p != null && p.ExitCode == 0) return;
            Thread.Sleep(1000);
        }
        throw new InvalidOperationException("MariaDB failed to start for migrations; see logs/mariadb-error.log.");
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
        string script = Path.Combine(TargetRoot, AppRelPath(), "database", "run_migrations.php");
        if (!File.Exists(script)) throw new InvalidOperationException("Migration runner database/run_migrations.php is missing.");

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
        string query = "SELECT COUNT(*) FROM schema_migrations WHERE version IN ('2026_08_05_decouple_question_bank','2026_08_06_add_question_bank_crud');";
        string args = "--protocol=tcp -h 127.0.0.1 -P " + MariaPort + " -u root " + AxumeraUpdate11.AppDbName + " -N -e \"" + query + "\"";
        try
        {
            ProcessStartInfo psi = new ProcessStartInfo(mysql, args) { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
            Process p = Process.Start(psi);
            string output = p != null ? p.StandardOutput.ReadToEnd() : "";
            if (p != null) p.WaitForExit(5000);
            if (p != null && p.ExitCode == 0 && output.Trim() == "2")
            {
                Report("Migration ledger verified: both new migrations recorded.");
                return;
            }
        }
        catch { }
        throw new InvalidOperationException("Migration ledger verification failed; new schema versions were not recorded.");
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
            // Longer grace window on rollback: the machine may be slower while large
            // directories are being restored.
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

    static void CopyDirectory(string sourceDir, string destinationDir)
    {
        Directory.CreateDirectory(destinationDir);
        foreach (string file in Directory.GetFiles(sourceDir))
        {
            File.Copy(file, Path.Combine(destinationDir, Path.GetFileName(file)), true);
        }
        foreach (string subDir in Directory.GetDirectories(sourceDir))
        {
            CopyDirectory(subDir, Path.Combine(destinationDir, Path.GetFileName(subDir)));
        }
    }
}

// =============================================================================
// WinForms GUI
// =============================================================================
internal class UpdateForm : Form
{
    readonly UpdateEngine Engine;
    readonly Label LblCurrent;
    readonly Label LblNew;
    readonly TextBox TxtChanges;
    readonly Button BtnInstall;
    readonly Button BtnClose;
    readonly RichTextBox TxtProgress;
    Thread Worker;

    public UpdateForm(UpdateEngine engine)
    {
        Engine = engine;
        Text = "AXUMERA UPDATE";
        Font = new System.Drawing.Font("Segoe UI", 9.5f);
        ClientSize = new System.Drawing.Size(660, 540);
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

        TxtChanges = new TextBox { Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, Location = new System.Drawing.Point(22, 126), Size = new System.Drawing.Size(616, 130), Text = UpdateEngine.ChangesText };
        Controls.Add(TxtChanges);

        BtnInstall = new Button { Text = "Install Update", Location = new System.Drawing.Point(22, 268), Size = new System.Drawing.Size(140, 34) };
        BtnInstall.Click += BtnInstall_Click;
        Controls.Add(BtnInstall);

        BtnClose = new Button { Text = "Close", Location = new System.Drawing.Point(168, 268), Size = new System.Drawing.Size(90, 34), Visible = false };
        BtnClose.Click += (s, e) => Close();
        Controls.Add(BtnClose);

        Label lblProgress = new Label { Text = "Progress:", AutoSize = true, Location = new System.Drawing.Point(22, 310) };
        Controls.Add(lblProgress);

        TxtProgress = new RichTextBox { ReadOnly = true, Location = new System.Drawing.Point(22, 332), Size = new System.Drawing.Size(616, 180), BackColor = System.Drawing.Color.White };
        Controls.Add(TxtProgress);

        Engine.Progress += OnProgress;
        Engine.Completed += OnCompleted;
    }

    void BtnInstall_Click(object sender, EventArgs e)
    {
        if (MessageBox.Show(
            "This will update AXUMERA from " + Engine.InstalledVersionText + " to " + Engine.TargetVersionText + ".\n\nA verified backup will be created first. The server will be stopped during the update.\n\nContinue?",
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
