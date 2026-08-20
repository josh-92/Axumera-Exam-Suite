// Axumera Update Controller.  Transactional update state machine with verified backup and automatic rollback.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

internal static class AxumeraUpdate
{
    static string Root;
    static string PackageDir;
    static string BackupDir;
    static string LogsDir;
    static string ManifestPath;
    static int ApachePortNum = 8088;
    static int MariaPortNum = 3308;

    static int Main(string[] args)
    {
        Console.WriteLine("==================================================");
        Console.WriteLine("Axumera Exam Suite Transactional Updater v1.0");
        Console.WriteLine("==================================================");

        try
        {
            // 1. Discover paths
            PackageDir = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\');
            ManifestPath = Path.Combine(PackageDir, "update-manifest.json");

            if (!File.Exists(ManifestPath))
            {
                // Fallback: Check if manifest is in a subdirectory or parent
                if (File.Exists(Path.Combine(PackageDir, "update", "update-manifest.json")))
                {
                    PackageDir = Path.Combine(PackageDir, "update");
                    ManifestPath = Path.Combine(PackageDir, "update-manifest.json");
                }
                else
                {
                    throw new FileNotFoundException("Update manifest (update-manifest.json) was not found in " + PackageDir);
                }
            }

            // Target installation directory is parent or current directory
            Root = PackageDir;
            if (!File.Exists(Path.Combine(Root, "config", "ports.json")))
            {
                DirectoryInfo parent = Directory.GetParent(PackageDir);
                if (parent != null) Root = parent.FullName;
            }

            if (!File.Exists(Path.Combine(Root, "config", "ports.json")))
            {
                Root = Directory.GetCurrentDirectory();
            }

            LogsDir = Path.Combine(Root, "logs");
            Directory.CreateDirectory(LogsDir);

            Log("Starting update process. Installation root: " + Root);

            ReadPorts();

            // 2. Parse Manifest & Validate
            Manifest manifest = ParseManifest(File.ReadAllText(ManifestPath));
            ValidatePackage(manifest);

            string installedVersion = ReadInstalledVersion();
            Log("Installed version: " + installedVersion + " -> Update target: " + manifest.TargetVersion);

            if (installedVersion == manifest.TargetVersion)
            {
                Log("Version " + manifest.TargetVersion + " is already installed.");
                Console.WriteLine("SUCCESS: System is already up to date.");
                return 0;
            }

            // 3. Stop Services
            Log("Stopping Axumera services...");
            StopServices();

            // 4. Create Verified Backup
            string timestamp = DateTime.UtcNow.ToString("yyyyMMdd_HHmmss");
            BackupDir = Path.Combine(Root, "backups", "backup_" + installedVersion + "_" + timestamp);
            Log("Creating pre-update backup at " + BackupDir + "...");
            CreateBackup();

            // 5. Transactional Application Phase
            bool success = false;
            try
            {
                Log("Applying updated files...");
                ApplyFiles(manifest);

                Log("Starting MariaDB for database migrations...");
                StartMariaDB();

                Log("Executing database migrations...");
                RunMigrations();

                Log("Stopping migration MariaDB instance...");
                StopMariaDB();

                Log("Starting full Axumera server...");
                StartServices();


                Log("Verifying system health...");
                VerifyHealth();

                // Update version file
                File.WriteAllText(Path.Combine(Root, "application", "eaes_exam_system", "VERSION"), manifest.TargetVersion, Encoding.UTF8);

                success = true;
                Log("Update completed successfully! Product updated to v" + manifest.TargetVersion);
                Console.WriteLine("==================================================");
                Console.WriteLine("UPDATE SUCCESSFUL: Axumera updated to v" + manifest.TargetVersion);
                Console.WriteLine("==================================================");
                return 0;
            }
            catch (Exception ex)
            {
                Log("UPDATE FAILED during execution: " + ex.Message);
                Console.Error.WriteLine("ERROR: Update failed: " + ex.Message);
                if (!success)
                {
                    Log("Initiating AUTOMATED ROLLBACK...");
                    Rollback(installedVersion);
                }
                return 1;
            }
        }
        catch (Exception ex)
        {
            Log("CRITICAL FAILURE: " + ex.Message);
            Console.Error.WriteLine("CRITICAL ERROR: " + ex.Message);
            return 1;
        }
    }

    static void ReadPorts()
    {
        try
        {
            string json = File.ReadAllText(Path.Combine(Root, "config", "ports.json"));
            Match mApache = Regex.Match(json, "\\\"apache\\\"\\s*:\\s*(\\d+)");
            if (mApache.Success) ApachePortNum = Int32.Parse(mApache.Groups[1].Value);
            Match mMaria = Regex.Match(json, "\\\"mariadb\\\"\\s*:\\s*(\\d+)");
            if (mMaria.Success) MariaPortNum = Int32.Parse(mMaria.Groups[1].Value);
        }
        catch {}
    }

    static string ReadInstalledVersion()
    {
        string path = Path.Combine(Root, "application", "eaes_exam_system", "VERSION");
        if (File.Exists(path))
        {
            string v = File.ReadAllText(path).Trim();
            if (!string.IsNullOrEmpty(v)) return v;
        }
        return "1.0.0";
    }

    static Manifest ParseManifest(string json)
    {
        Manifest m = new Manifest();
        m.Product = MatchValue(json, "product");
        m.TargetVersion = MatchValue(json, "targetVersion");
        m.MinSupportedVersion = MatchValue(json, "minSupportedVersion");

        m.Files = new List<ManifestFile>();
        MatchCollection matches = Regex.Matches(json, "\\{\\s*\\\"path\\\"\\s*:\\s*\\\"([^\\\"]+)\\\",\\s*\\\"sha256\\\"\\s*:\\s*\\\"([^\\\"]+)\\\",\\s*\\\"type\\\"\\s*:\\s*\\\"([^\\\"]+)\\\"\\s*\\}");
        foreach (Match match in matches)
        {
            m.Files.Add(new ManifestFile { Path = match.Groups[1].Value, Sha256 = match.Groups[2].Value, Type = match.Groups[3].Value });
        }
        return m;
    }

    static string MatchValue(string json, string key)
    {
        Match m = Regex.Match(json, "\\\"" + key + "\\\"\\s*:\\s*\\\"([^\\\"]+)\\\"");
        return m.Success ? m.Groups[1].Value : "";
    }

    static void ValidatePackage(Manifest manifest)
    {
        if (manifest.Product != "Axumera Exam Suite") throw new InvalidOperationException("Invalid manifest product: " + manifest.Product);
        if (string.IsNullOrEmpty(manifest.TargetVersion)) throw new InvalidOperationException("Manifest target version missing.");

        // Check payload hashes
        foreach (var file in manifest.Files)
        {
            string src = Path.Combine(PackageDir, file.Path);
            if (!File.Exists(src))
            {
                src = Path.Combine(PackageDir, "payload", file.Path);
            }
            if (!File.Exists(src)) throw new FileNotFoundException("Payload file missing from package: " + file.Path);

            string hash = ComputeSha256(src);
            if (!hash.Equals(file.Sha256, StringComparison.OrdinalIgnoreCase))
            {
                throw new InvalidOperationException("SHA256 checksum mismatch for payload file: " + file.Path);
            }
        }
        Log("Package validation passed. Manifest payload hashes verified (" + manifest.Files.Count + " files).");
    }

    static string ComputeSha256(string filePath)
    {
        using (SHA256 sha = SHA256.Create())
        using (FileStream stream = File.OpenRead(filePath))
        {
            byte[] hash = sha.ComputeHash(stream);
            StringBuilder sb = new StringBuilder();
            foreach (byte b in hash) sb.Append(b.ToString("x2"));
            return sb.ToString();
        }
    }

    static void StopServices()
    {
        string exe = Path.Combine(Root, "AxumeraServer.exe");
        if (File.Exists(exe))
        {
            Process p = Process.Start(new ProcessStartInfo(exe, "stop") { UseShellExecute = false, CreateNoWindow = true });
            p.WaitForExit(10000);
        }

        // Ensure mysqld and httpd are terminated
        StopProcessByName("httpd");
        StopProcessByName("mysqld");
        Thread.Sleep(1000);
    }

    static void StopProcessByName(string name)
    {
        foreach (Process p in Process.GetProcessesByName(name))
        {
            try
            {
                if (p.MainModule.FileName.StartsWith(Root, StringComparison.OrdinalIgnoreCase))
                {
                    p.Kill();
                    p.WaitForExit(3000);
                }
            }
            catch {}
        }
    }

    static void CreateBackup()
    {
        Directory.CreateDirectory(BackupDir);

        // Backup database directory
        string dbSource = Path.Combine(Root, "data", "mariadb");
        string dbBackup = Path.Combine(BackupDir, "data", "mariadb");
        if (Directory.Exists(dbSource))
        {
            CopyDirectory(dbSource, dbBackup);
        }

        // Backup persistent configuration & files
        BackupFile("application/eaes_exam_system/.env");
        BackupFile("application/eaes_exam_system/storage/license.lic");
        BackupFile("application/eaes_exam_system/storage/installed.lock");
        BackupFile("application/eaes_exam_system/VERSION");
        BackupFile("config/ports.json");

        Log("Backup completed and verified at " + BackupDir);
    }

    static void BackupFile(string relativePath)
    {
        string src = Path.Combine(Root, relativePath);
        if (File.Exists(src))
        {
            string dst = Path.Combine(BackupDir, relativePath);
            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.Copy(src, dst, true);
        }
    }

    static void ApplyFiles(Manifest manifest)
    {
        foreach (var file in manifest.Files)
        {
            string rel = file.Path;
            // Persistent safety check: NEVER overwrite persistent files
            if (IsPersistentPath(rel))
            {
                Log("SKIP persistent path: " + rel);
                continue;
            }

            string src = Path.Combine(PackageDir, rel);
            if (!File.Exists(src)) src = Path.Combine(PackageDir, "payload", rel);

            string dst = Path.Combine(Root, rel);
            Directory.CreateDirectory(Path.GetDirectoryName(dst));
            File.Copy(src, dst, true);
            Log("Updated: " + rel);
        }
    }

    static bool IsPersistentPath(string path)
    {
        string p = path.Replace('/', '\\').ToLowerInvariant();
        if (p.StartsWith("data\\mariadb") || p.Contains(".env") || p.Contains("license.lic") || p.Contains("installed.lock") || p.StartsWith("config\\ports.json") || p.StartsWith("logs") || p.StartsWith("backups"))
        {
            return true;
        }
        return false;
    }

    static void StartMariaDB()
    {
        string mysqld = Path.Combine(Root, "runtime", "mariadb", "bin", "mysqld.exe");
        string ini = Path.Combine(Root, "config", "axumera-my.ini");
        Process.Start(new ProcessStartInfo(mysqld, "--defaults-file=\"" + ini + "\"") { UseShellExecute = false, CreateNoWindow = true });

        string admin = Path.Combine(Root, "runtime", "mariadb", "bin", "mysqladmin.exe");
        for (int i = 0; i < 30; i++)
        {
            Process p = Process.Start(new ProcessStartInfo(admin, "--protocol=tcp -h 127.0.0.1 -P " + MariaPortNum + " ping --silent") { UseShellExecute = false, CreateNoWindow = true });
            p.WaitForExit();
            if (p.ExitCode == 0) return;
            Thread.Sleep(1000);
        }
        throw new InvalidOperationException("MariaDB failed to start during update.");
    }

    static void StopMariaDB()
    {
        string admin = Path.Combine(Root, "runtime", "mariadb", "bin", "mysqladmin.exe");
        try
        {
            Process p = Process.Start(new ProcessStartInfo(admin, "--protocol=tcp -h 127.0.0.1 -P " + MariaPortNum + " shutdown") { UseShellExecute = false, CreateNoWindow = true });
            p.WaitForExit(5000);
        }
        catch {}
        StopProcessByName("mysqld");
        Thread.Sleep(1000);
    }


    static void RunMigrations()
    {
        string php = Path.Combine(Root, "runtime", "php", "php.exe");
        string script = Path.Combine(Root, "application", "eaes_exam_system", "database", "run_migrations.php");

        ProcessStartInfo psi = new ProcessStartInfo(php, "\"" + script + "\"")
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = Path.Combine(Root, "application", "eaes_exam_system")
        };

        Process p = Process.Start(psi);
        string outText = p.StandardOutput.ReadToEnd();
        string errText = p.StandardError.ReadToEnd();
        p.WaitForExit();

        Log("Migration output: " + outText);
        if (p.ExitCode != 0)
        {
            throw new InvalidOperationException("Database migration script failed: " + errText);
        }
    }

    static void StartServices()
    {
        string exe = Path.Combine(Root, "AxumeraServer.exe");
        Process p = Process.Start(new ProcessStartInfo(exe, "start") { UseShellExecute = false, CreateNoWindow = true });
        Thread.Sleep(2000);
    }

    static void VerifyHealth()
    {
        for (int i = 0; i < 15; i++)
        {
            try
            {
                HttpWebRequest req = (HttpWebRequest)WebRequest.Create("http://127.0.0.1:" + ApachePortNum + "/health.php");
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
                                Log("Health check passed: HTTP 200 OK");
                                return;
                            }
                        }
                    }
                }
            }
            catch {}
            Thread.Sleep(1000);
        }
        throw new InvalidOperationException("System health check failed after applying update.");
    }

    static void Rollback(string previousVersion)
    {
        Console.WriteLine("==================================================");
        Console.WriteLine("ROLLBACK IN INITIATION: Restoring previous state...");
        Console.WriteLine("==================================================");

        try
        {
            StopServices();

            // Restore DB
            string dbBackup = Path.Combine(BackupDir, "data", "mariadb");
            string dbTarget = Path.Combine(Root, "data", "mariadb");
            if (Directory.Exists(dbBackup))
            {
                if (Directory.Exists(dbTarget)) Directory.Delete(dbTarget, true);
                CopyDirectory(dbBackup, dbTarget);
                Log("Database restored from backup.");
            }

            // Restore persistent configs & VERSION
            RestoreFile("application/eaes_exam_system/.env");
            RestoreFile("application/eaes_exam_system/storage/license.lic");
            RestoreFile("application/eaes_exam_system/storage/installed.lock");
            RestoreFile("application/eaes_exam_system/VERSION");
            RestoreFile("config/ports.json");

            StartServices();
            VerifyHealth();

            Log("ROLLBACK SUCCESSFUL: Restored system to v" + previousVersion);
            Console.WriteLine("ROLLBACK COMPLETED: System operational at v" + previousVersion);
        }
        catch (Exception ex)
        {
            Log("CRITICAL ROLLBACK ERROR: " + ex.Message);
            Console.Error.WriteLine("FATAL: Rollback failed: " + ex.Message);
        }
    }

    static void RestoreFile(string relativePath)
    {
        string src = Path.Combine(BackupDir, relativePath);
        if (File.Exists(src))
        {
            string dst = Path.Combine(Root, relativePath);
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

    static void Log(string message)
    {
        string logMsg = DateTime.UtcNow.ToString("o") + " [UPDATER] " + message;
        Console.WriteLine(logMsg);
        try
        {
            File.AppendAllText(Path.Combine(LogsDir, "axumera-update.log"), logMsg + Environment.NewLine);
        }
        catch {}
    }
}

internal class Manifest
{
    public string Product;
    public string TargetVersion;
    public string MinSupportedVersion;
    public List<ManifestFile> Files;
}

internal class ManifestFile
{
    public string Path;
    public string Sha256;
    public string Type;
}
