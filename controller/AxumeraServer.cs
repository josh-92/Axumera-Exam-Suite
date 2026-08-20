// Axumera private-runtime controller.  It is intentionally independent of the PHP application.
using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

internal static class AxumeraServer
{
    // The controller root is the directory that contains AxumeraServer.exe itself.
    // AppContext.BaseDirectory is the executable base directory and does not depend on the
    // process working directory (which Windows does not guarantee for shortcuts/scripts).
    static readonly string Root = (AppContext.BaseDirectory ?? AppDomain.CurrentDomain.BaseDirectory).TrimEnd('\\');
    static readonly string Logs = Path.Combine(Root, "logs");
    static readonly string State = Path.Combine(Logs, "axumera-server.state");
    static Process maria, apache;
    // setupMode: first-run/setup behaviour (init data dir if empty, verify wizard, wait for init).
    // explicitSetup: the user typed `setup`; refuse when the installation is already initialized.
    // firstRun: application is not yet initialized (.env and/or installed.lock absent).
    static bool setupMode, explicitSetup, firstRun;

    sealed class StartupException : Exception
    {
        public readonly string Stage, Executable, Command;
        public StartupException(string stage, string message, string executable, string command) : base(message) { Stage = stage; Executable = executable; Command = command; }
    }

    static int Main(string[] args)
    {
        string command = args.Length == 0 ? "start" : args[0].TrimStart('-', '/').ToLowerInvariant();
        try {
            Directory.CreateDirectory(Logs);
            if (command == "stop") { StopRecorded(); return 0; }
            if (command == "status") return Status();
            if (command != "start" && command != "setup") throw new StartupException("startup", "Usage: AxumeraServer.exe [start|setup|stop|status]", null, null);
            explicitSetup = command == "setup";
            firstRun = explicitSetup || !IsInitialized();
            setupMode = firstRun;
            bool created;
            using (var single = new Mutex(true, MutexName(), out created)) {
                if (!created) {
                    // A mutex with this name already exists.  Only treat it as "already running"
                    // when a live Axumera server is actually present; otherwise the previous
                    // controller crashed and left a stale mutex, so take ownership and continue.
                    if (IsLiveServer()) { ShowAlreadyRunning(); WaitForKey(); return 0; }
                    try { single.WaitOne(); } catch (AbandonedMutexException) { }
                }
                Validate();
                WriteRuntimeConfiguration();
                Start();
                Console.CancelKeyPress += delegate(object s, ConsoleCancelEventArgs e) { e.Cancel = true; StopOwned(); Environment.Exit(0); };
                if (firstRun) {
                    // First-run setup mode: the setup wizard runs in the browser and performs the
                    // database initialization, .env generation, administrator creation, and writes
                    // storage/installed.lock.  The controller orchestrates it and then transitions
                    // to normal server mode.
                    if (!WaitForInitialization()) return 0; // stop requested while the wizard was open
                    Console.WriteLine();
                    Console.WriteLine("[OK] Application environment (.env) created");
                    Console.WriteLine("[OK] Installation lock (storage/installed.lock) created");
                    try { Health(); Console.WriteLine("[OK] Database connection verified"); }
                    catch (Exception ex) { throw new StartupException("health check", "Initialization completed, but the application health check failed: " + ex.Message, null, null); }
                }
                ShowRunning();
                while (IsMariaAlive() && IsApacheAlive()) { if (!File.Exists(State)) return 0; Thread.Sleep(1000); }
                throw new StartupException("runtime monitor", "A runtime child process exited unexpectedly.", null, null);
            }
        } catch (StartupException ex) { Log("ERROR " + ex.Message); ShowFailure(ex); WaitForKey(); StopOwned(); return 1; }
        catch (Exception ex) { Log("ERROR " + ex.Message); ShowFailure(new StartupException("startup", ex.Message, null, null)); WaitForKey(); StopOwned(); return 1; }
    }

    static string Runtime { get { return Path.Combine(Root, "runtime"); } }
    static string App { get { return Path.Combine(Root, "application", "eaes_exam_system"); } }
    static string Config { get { return Path.Combine(Root, "config"); } }
    static int Port(string name, int fallback) {
        string file = Path.Combine(Config, "ports.json"); string json = File.ReadAllText(file);
        Match m = Regex.Match(json, "\\\"" + name + "\\\"\\s*:\\s*(\\d+)"); return m.Success ? Int32.Parse(m.Groups[1].Value) : fallback;
    }
    static int ApachePort() { return Port("apache", 8088); }
    static int MariaPort() { return Port("mariadb", 3308); }
    static string U(string p) { return p.Replace('\\', '/'); }

    // ------------------------------------------------------------------ state

    // STATE 2 (initialized): both .env and storage/installed.lock exist.
    // STATE 1 (first run): at least one of them is missing — a legitimate fresh-install state.
    static bool IsInitialized()
    {
        return File.Exists(Path.Combine(App, ".env"))
            && File.Exists(Path.Combine(App, "storage", "installed.lock"));
    }

    // ------------------------------------------------------------------ validation

    static bool Found(string label, string relative)
    {
        string abs = Path.Combine(Root, relative);
        if (File.Exists(abs)) { Console.WriteLine("[FOUND] " + label + ": " + abs); return true; }
        Console.WriteLine("[MISSING] " + abs);
        return false;
    }

    static void Validate()
    {
        Console.WriteLine();
        Console.WriteLine("AXUMERA SERVER");
        Console.WriteLine("==============");
        Console.WriteLine();
        Console.WriteLine("Installation:");
        Console.WriteLine(Root);
        Console.WriteLine();
        Console.WriteLine("Runtime:");
        Console.WriteLine(Runtime);
        Console.WriteLine();
        Console.WriteLine("Runtime validation:");
        Console.WriteLine();
        // A. RUNTIME DEPENDENCIES — always mandatory.  These are the physical files the server
        //    cannot operate without.  Their absence is a genuine runtime failure.
        bool ok = true;
        ok &= Found("Apache", "runtime\\apache\\bin\\httpd.exe");
        ok &= Found("PHP", "runtime\\php\\php.exe");
        ok &= Found("PHP Apache module", "runtime\\php\\php8apache2_4.dll");
        ok &= Found("PHP PDO MySQL extension", "runtime\\php\\ext\\php_pdo_mysql.dll");
        ok &= Found("MariaDB", "runtime\\mariadb\\bin\\mysqld.exe");
        ok &= Found("MariaDB admin client", "runtime\\mariadb\\bin\\mysqladmin.exe");
        ok &= Found("Port configuration", "config\\ports.json");
        ok &= Found("Application health endpoint", "application\\eaes_exam_system\\health.php");
        ok &= Found("Application installer", "application\\eaes_exam_system\\installer\\install.php");
        if (setupMode) {
            // B. INSTALLATION STATE — first-run / setup mode.  A missing .env or installed.lock is
            //    EXPECTED here and must never be reported as a missing runtime file.
            string initTool = MariaInitTool();
            if (initTool == null) { Console.WriteLine("[MISSING] MariaDB data initializer: " + Path.Combine(Runtime, "mariadb", "bin", "mysql_install_db.exe") + " (or mariadb-install-db.exe)"); ok = false; }
            else Console.WriteLine("[FOUND] MariaDB data initializer: " + initTool);
            if (explicitSetup && (File.Exists(Path.Combine(App, ".env")) || File.Exists(Path.Combine(App, "storage", "installed.lock")))) {
                Console.WriteLine("[REFUSED] This installation is already initialized; the setup command is refused.");
                Console.WriteLine("Use: AxumeraServer.exe start");
                ok = false;
            }
            if (ok) {
                string data = Path.Combine(Root, "data", "mariadb");
                bool hasData = Directory.Exists(data) && Directory.GetFileSystemEntries(data).Length != 0;
                Console.WriteLine(hasData
                    ? "[INFO] MariaDB data directory already exists; it will be reused without re-initialization."
                    : "[INFO] MariaDB data directory will be initialized for this first run.");
            }
            if (ok) {
                Console.WriteLine();
                Console.WriteLine("==================================================");
                Console.WriteLine("            AXUMERA FIRST-RUN SETUP");
                Console.WriteLine("==================================================");
                Console.WriteLine();
                Console.WriteLine("[OK] Apache runtime found");
                Console.WriteLine("[OK] PHP runtime found");
                Console.WriteLine("[OK] MariaDB runtime found");
                Console.WriteLine("[OK] Application files found");
                Console.WriteLine();
                if (File.Exists(Path.Combine(App, ".env")) || File.Exists(Path.Combine(App, "storage", "installed.lock")))
                    Console.WriteLine("[INFO] A previous setup attempt was interrupted; the wizard will resume it.");
                else {
                    Console.WriteLine("[INFO] Fresh installation detected.");
                    Console.WriteLine("[INFO] Application initialization is required.");
                }
                Console.WriteLine();
                Console.WriteLine("Starting first-run setup...");
                Console.WriteLine();
            }
        } else {
            // Normal mode: the installation must already be initialized.
            ok &= Found("Application environment (.env)", "application\\eaes_exam_system\\.env");
            ok &= Found("Installation lock", "application\\eaes_exam_system\\storage\\installed.lock");
            if (!ok) Console.WriteLine("The application is not fully initialized. Run AxumeraServer.exe start to resume first-run setup.");
        }
        if (!ok) throw new StartupException("runtime validation", "One or more required runtime files are missing (see the [MISSING] lines above), or a setup precondition was refused. Startup was stopped.", null, null);
    }

    static string MariaInitTool()
    {
        string a = Path.Combine(Runtime, "mariadb", "bin", "mysql_install_db.exe");
        if (File.Exists(a)) return a;
        string b = Path.Combine(Runtime, "mariadb", "bin", "mariadb-install-db.exe");
        if (File.Exists(b)) return b;
        return null;
    }

    // --------------------------------------------------------------- configuration

    static void WriteRuntimeConfiguration() {
        string r=U(Root), runtime=U(Runtime), app=U(App), logs=U(Logs), data=U(Path.Combine(Root,"data"));
        Directory.CreateDirectory(Logs); Directory.CreateDirectory(Path.Combine(Runtime,"apache","logs")); Directory.CreateDirectory(Path.Combine(data,"tmp")); Directory.CreateDirectory(Path.Combine(App,"storage","sessions"));
        File.WriteAllText(Path.Combine(Config,"axumera-my.ini"), "[client]\nhost=127.0.0.1\nport="+MariaPort()+"\n\n[mysqld]\nbasedir=\""+runtime+"/mariadb\"\ndatadir=\""+data+"/mariadb\"\ntmpdir=\""+data+"/tmp\"\nport="+MariaPort()+"\nbind-address=127.0.0.1\ncharacter-set-server=utf8mb4\ncollation-server=utf8mb4_general_ci\ndefault-storage-engine=InnoDB\nlog_error=\""+logs+"/mariadb-error.log\"\npid-file=\""+data+"/mariadb/axumera-mariadb.pid\"\n", Encoding.ASCII);
        File.WriteAllText(Path.Combine(Runtime,"php","php.ini"), "[PHP]\nextension_dir=\""+runtime+"/php/ext\"\nextension=pdo_mysql\nextension=mbstring\nextension=openssl\nextension=fileinfo\ndate.timezone=Africa/Addis_Ababa\ndisplay_errors=Off\nlog_errors=On\nerror_log=\""+logs+"/php-runtime-error.log\"\nsession.save_path=\""+app+"/storage/sessions\"\nsession.use_strict_mode=1\nsession.cookie_httponly=1\nupload_tmp_dir=\""+data+"/tmp\"\nupload_max_filesize=40M\npost_max_size=40M\nexpose_php=Off\n", Encoding.ASCII);
        File.WriteAllText(Path.Combine(Runtime,"apache","conf","axumera-httpd.conf"), "ServerRoot \""+runtime+"/apache\"\nListen "+ApachePort()+"\nLoadModule authn_core_module modules/mod_authn_core.so\nLoadModule authz_core_module modules/mod_authz_core.so\nLoadModule authz_host_module modules/mod_authz_host.so\nLoadModule mime_module modules/mod_mime.so\nLoadModule dir_module modules/mod_dir.so\nLoadModule alias_module modules/mod_alias.so\nLoadModule rewrite_module modules/mod_rewrite.so\nLoadModule headers_module modules/mod_headers.so\nLoadModule log_config_module modules/mod_log_config.so\nLoadFile \""+runtime+"/php/php8ts.dll\"\nLoadFile \""+runtime+"/php/libpq.dll\"\nLoadFile \""+runtime+"/php/libsqlite3.dll\"\nLoadModule php_module \""+runtime+"/php/php8apache2_4.dll\"\nPHPIniDir \""+runtime+"/php\"\nServerName 127.0.0.1:"+ApachePort()+"\nPidFile \""+logs+"/apache.pid\"\nDocumentRoot \""+app+"\"\n<Directory \""+app+"\">\nOptions FollowSymLinks\nAllowOverride All\nRequire all granted\n</Directory>\nAlias /eaes_exam_system \""+app+"\"\n<Directory \""+app+"/app\">\nRequire all denied\n</Directory>\n<Directory \""+app+"/database\">\nRequire all denied\n</Directory>\n<Directory \""+app+"/storage\">\nRequire all denied\n</Directory>\n<FilesMatch \"^\\\\.env\">\nRequire all denied\n</FilesMatch>\n<FilesMatch \"\\.php$\">\nSetHandler application/x-httpd-php\n</FilesMatch>\nDirectoryIndex index.php index.html\nErrorLog \""+logs+"/apache-error.log\"\nLogFormat \"%h %l %u %t \\\"%r\\\" %>s %b \\\"%{Referer}i\\\" \\\"%{User-Agent}i\\\"\" combined\nCustomLog \""+logs+"/apache-access.log\" combined\n", Encoding.ASCII);
    }

    // --------------------------------------------------------------------- startup

    static Process Launch(string file, string args) { return Process.Start(new ProcessStartInfo(file,args) { UseShellExecute=false, CreateNoWindow=true, WorkingDirectory=Root }); }

    static void Start()
    {
        if (setupMode) InitializeMariaData();
        string mariaExe = Path.Combine(Runtime,"mariadb","bin","mysqld.exe");
        string mariaCmd = "--defaults-file=\""+Path.Combine(Config,"axumera-my.ini")+"\"";
        maria = Launch(mariaExe, mariaCmd);
        try { WaitForMaria(); } catch (Exception ex) { throw new StartupException("MariaDB", ex.Message, mariaExe, mariaCmd); }
        Console.WriteLine("[OK] MariaDB started (mysqld.exe)");

        string apacheExe = Path.Combine(Runtime,"apache","bin","httpd.exe");
        string apacheCmd = "-f \""+Path.Combine(Runtime,"apache","conf","axumera-httpd.conf")+"\"";
        apache = Launch(apacheExe, apacheCmd);
        apache = ProcessFromPidFile(Path.Combine(Logs,"apache.pid"), apache);
        try { WaitForApachePort(); } catch (Exception ex) { throw new StartupException("Apache", ex.Message, apacheExe, apacheCmd); }
        Console.WriteLine("[OK] Apache started (httpd.exe)");
        Console.WriteLine("[OK] HTTP port "+ApachePort()+" is listening");

        if (setupMode) { try { SetupHealth(); } catch (Exception ex) { throw new StartupException("setup wizard", ex.Message, null, null); } Console.WriteLine("[OK] Setup wizard reachable"); }
        else { try { Health(); } catch (Exception ex) { throw new StartupException("health check", ex.Message, null, null); } Console.WriteLine("[OK] Health check passed"); }

        File.WriteAllText(State, maria.Id+"\n"+apache.Id+"\n", Encoding.ASCII);
        Log("READY maria="+maria.Id+" apache="+apache.Id+(setupMode ? " setup" : ""));
    }

    // Initializes the private MariaDB data directory with the bundled mysql_install_db.exe
    // (or mariadb-install-db.exe).  An existing, non-empty data directory is never re-initialized:
    // it is reused as-is, which also lets an interrupted first-run setup resume safely.
    static void InitializeMariaData() {
        string data=Path.Combine(Root,"data","mariadb");
        if (Directory.Exists(data) && Directory.GetFileSystemEntries(data).Length!=0) { Log("MariaDB data directory reused (non-empty); initialization skipped."); return; }
        Directory.CreateDirectory(data);
        string tool=MariaInitTool();
        if (tool==null) throw new StartupException("MariaDB", "MariaDB data initializer (mysql_install_db.exe or mariadb-install-db.exe) is missing; a fresh setup cannot initialize the data directory.", null, null);
        string cmd="--datadir=\""+data+"\" --port="+MariaPort()+" --silent";
        Process p=Launch(tool, cmd); p.WaitForExit();
        if(p.ExitCode!=0) throw new StartupException("MariaDB", "MariaDB data initialization failed (exit code "+p.ExitCode+").", tool, cmd);
        Console.WriteLine("[OK] MariaDB data directory initialized");
    }

    static void WaitForMaria() {
        string admin=Path.Combine(Runtime,"mariadb","bin","mysqladmin.exe");
        for(int i=0;i<30;i++) { Process p=Launch(admin, "--protocol=tcp -h 127.0.0.1 -P "+MariaPort()+" ping --silent"); p.WaitForExit(3000); if(p.ExitCode==0)return; Thread.Sleep(1000); }
        throw new InvalidOperationException("MariaDB did not become ready; see logs/mariadb-error.log.");
    }

    static void WaitForApachePort() {
        for(int i=0;i<30;i++) { if(IsApacheAlive())return; Thread.Sleep(1000); }
        throw new InvalidOperationException("Apache did not start listening on port "+ApachePort()+"; see logs/apache-error.log.");
    }

    static void Health() {
        try {
            var req=(HttpWebRequest)WebRequest.Create("http://127.0.0.1:"+ApachePort()+"/health.php"); req.Timeout=15000;
            using(var res=(HttpWebResponse)req.GetResponse()) { if(res.StatusCode!=HttpStatusCode.OK) throw new Exception(); }
        } catch { throw new InvalidOperationException("Axumera health check failed; see runtime logs."); }
    }

    static void SetupHealth() {
        try {
            var req=(HttpWebRequest)WebRequest.Create("http://127.0.0.1:"+ApachePort()+"/installer/install.php"); req.Timeout=15000;
            using(var res=(HttpWebResponse)req.GetResponse()) { if(res.StatusCode!=HttpStatusCode.OK) throw new Exception(); }
        } catch { throw new InvalidOperationException("Axumera setup page is unavailable; see runtime logs."); }
    }

    // Blocks while the browser-based setup wizard runs.  The wizard (installer/install.php) creates
    // the database, writes .env, creates the first administrator, and writes storage/installed.lock.
    // Returns true once the installation is initialized, or false if `stop` was requested meanwhile.
    static bool WaitForInitialization()
    {
        Console.WriteLine();
        Console.WriteLine("The setup wizard will open in your browser. Complete the wizard to");
        Console.WriteLine("initialize the database and create the first administrator account.");
        Console.WriteLine();
        Console.WriteLine("Setup wizard:");
        Console.WriteLine("http://127.0.0.1:"+ApachePort()+"/installer/install.php");
        Console.WriteLine();
        OpenSetupBrowser();
        Console.WriteLine("Waiting for initialization to complete...");
        int ticks = 0;
        while (IsMariaAlive() && IsApacheAlive()) {
            if (!File.Exists(State)) return false; // stop requested while the wizard was open
            if (IsInitialized()) return true;
            Thread.Sleep(3000);
            if (++ticks % 20 == 0) Console.WriteLine("[INFO] Still waiting for the setup wizard to complete...");
        }
        throw new StartupException("runtime monitor", "A runtime child process exited during first-run setup.", null, null);
    }

    static void OpenSetupBrowser() { try { Process.Start(new ProcessStartInfo("http://127.0.0.1:"+ApachePort()+"/installer/install.php") { UseShellExecute=true }); } catch (Exception ex) { Log("Setup browser launch failed: "+ex.Message); } }
    static Process ProcessFromPidFile(string file, Process fallback) { try { for(int i=0;i<10;i++) { if(File.Exists(file)) { int id; if(Int32.TryParse(File.ReadAllText(file).Trim(),out id)) return Process.GetProcessById(id); } Thread.Sleep(200); } } catch {} return fallback; }
    static bool IsRunning(Process process) { try { return process!=null && !process.HasExited; } catch { return false; } }
    static bool IsApacheAlive() { try { using(TcpClient client=new TcpClient()) { IAsyncResult result=client.BeginConnect("127.0.0.1",ApachePort(),null,null); if(!result.AsyncWaitHandle.WaitOne(1000)) return false; client.EndConnect(result); return true; } } catch { return false; } }
    static bool IsMariaAlive() { try { Process p=Launch(Path.Combine(Runtime,"mariadb","bin","mysqladmin.exe"), "--protocol=tcp -h 127.0.0.1 -P "+MariaPort()+" ping --silent"); p.WaitForExit(3000); return p.ExitCode==0; } catch { return false; } }
    static bool IsLiveServer() {
        if(File.Exists(State)) { string[] ids=File.ReadAllLines(State); for(int i=0;i<ids.Length && i<2;i++) { int id; if(Int32.TryParse(ids[i],out id)) { try { Process p=Process.GetProcessById(id); if(!p.HasExited) return true; } catch {} } } }
        return IsApacheAlive() || IsMariaAlive();
    }
    static int Status() {
        bool a=IsApacheAlive(), m=IsMariaAlive();
        if(a||m) { Console.WriteLine("RUNNING (Apache="+(a?"yes":"no")+", MariaDB="+(m?"yes":"no")+")"); return 0; }
        Console.WriteLine("STOPPED"); return 1;
    }

    // ------------------------------------------------------------------------ stop

    static void StopRecorded() {
        int mariaId=0, apacheId=0;
        if(File.Exists(State)) { string[] ids=File.ReadAllLines(State); if(ids.Length>0) Int32.TryParse(ids[0],out mariaId); if(ids.Length>1) Int32.TryParse(ids[1],out apacheId); }
        StopProcess(apacheId);
        try { Launch(Path.Combine(Runtime,"mariadb","bin","mysqladmin.exe"), "--protocol=tcp -h 127.0.0.1 -P "+MariaPort()+" shutdown").WaitForExit(5000); } catch {}
        StopProcess(mariaId); try { File.Delete(State); } catch {}
        Console.WriteLine("Axumera stop requested.");
    }
    static void StopProcess(int id) { if(id==0) return; try { Process p=Process.GetProcessById(id); if(!p.HasExited) p.Kill(); } catch {} }
    static void StopOwned() { try { if(IsRunning(apache)) apache.Kill(); } catch {} try { Launch(Path.Combine(Runtime,"mariadb","bin","mysqladmin.exe"), "--protocol=tcp -h 127.0.0.1 -P "+MariaPort()+" shutdown").WaitForExit(5000); } catch {} try { if(IsRunning(maria)) maria.Kill(); } catch {} try { if(File.Exists(State)) File.Delete(State); } catch {} }
    static void Log(string text) { try { File.AppendAllText(Path.Combine(Logs,"axumera-server.log"), DateTime.UtcNow.ToString("o")+" "+text+Environment.NewLine); } catch {} }

    // --------------------------------------------------------------------- screens

    static string VersionText() { try { string v=File.ReadAllText(Path.Combine(App,"VERSION")).Trim(); if(v.Length>0) return "AXE "+v; } catch {} return "AXE 1.0"; }
    static string LanIp() { try { foreach(IPAddress a in Dns.GetHostAddresses(Dns.GetHostName())) if(a.AddressFamily==AddressFamily.InterNetwork && !IPAddress.IsLoopback(a)) return a.ToString(); } catch {} return "127.0.0.1"; }

    static void ShowRunning()
    {
        Console.WriteLine();
        Console.WriteLine("==================================================");
        Console.WriteLine("              AXUMERA SERVER");
        Console.WriteLine("==================================================");
        Console.WriteLine();
        Console.WriteLine("Version: "+VersionText());
        Console.WriteLine();
        Console.WriteLine("Installation:");
        Console.WriteLine(Root);
        Console.WriteLine();
        Console.WriteLine("[OK] Apache runtime found");
        Console.WriteLine("[OK] PHP runtime found");
        Console.WriteLine("[OK] MariaDB runtime found");
        Console.WriteLine();
        Console.WriteLine("[OK] MariaDB started");
        Console.WriteLine("[OK] Apache started");
        Console.WriteLine("[OK] HTTP port "+ApachePort()+" is listening");
        Console.WriteLine("[OK] Health check passed");
        Console.WriteLine();
        Console.WriteLine("==================================================");
        Console.WriteLine("           AXUMERA SERVER IS RUNNING");
        Console.WriteLine("==================================================");
        Console.WriteLine();
        Console.WriteLine("Local:");
        Console.WriteLine("http://127.0.0.1:"+ApachePort());
        Console.WriteLine();
        Console.WriteLine("LAN:");
        Console.WriteLine("http://"+LanIp()+":"+ApachePort());
        Console.WriteLine();
        Console.WriteLine("Status:");
        Console.WriteLine("RUNNING");
        Console.WriteLine();
        Console.WriteLine("The server is ready for administrator and");
        Console.WriteLine("student connections.");
        Console.WriteLine();
        Console.WriteLine("==================================================");
    }

    static void ShowFailure(StartupException ex)
    {
        Console.WriteLine();
        Console.WriteLine("==================================================");
        Console.WriteLine("        AXUMERA SERVER STARTUP FAILED");
        Console.WriteLine("==================================================");
        Console.WriteLine();
        bool runtimeOk = ex.Stage != "runtime validation" && ex.Stage != "startup";
        Console.WriteLine(runtimeOk ? "[OK] Runtime validation" : "[FAILED] Runtime validation");
        // Only a service-stage failure (MariaDB/Apache/health check/...) gets its own FAILED line.
        if (runtimeOk && !String.IsNullOrEmpty(ex.Stage)) { Console.WriteLine(); Console.WriteLine("[FAILED] "+ex.Stage); }
        Console.WriteLine();
        Console.WriteLine("Reason:");
        Console.WriteLine(ex.Message);
        if (!String.IsNullOrEmpty(ex.Executable)) { Console.WriteLine(); Console.WriteLine("Executable:"); Console.WriteLine(ex.Executable); }
        if (!String.IsNullOrEmpty(ex.Command)) { Console.WriteLine(); Console.WriteLine("Command:"); Console.WriteLine(ex.Command); }
        Console.WriteLine();
        Console.WriteLine("==================================================");
    }

    static void ShowAlreadyRunning()
    {
        Console.WriteLine();
        Console.WriteLine("==================================================");
        Console.WriteLine("       AXUMERA SERVER IS ALREADY RUNNING");
        Console.WriteLine("==================================================");
        Console.WriteLine();
        Console.WriteLine("Apache: "+(IsApacheAlive()?"RUNNING":"not responding"));
        Console.WriteLine("MariaDB: "+(IsMariaAlive()?"RUNNING":"not responding"));
        Console.WriteLine("HTTP: "+ApachePort());
        Console.WriteLine("LAN IP: "+LanIp());
        Console.WriteLine();
        Console.WriteLine("No duplicate instances were started.");
        Console.WriteLine("==================================================");
    }

    static void WaitForKey() { try { if(!Console.IsInputRedirected) { Console.WriteLine(); Console.WriteLine("Press any key to close this window..."); Console.ReadKey(true); } } catch {} }

    // A stable, cross-process mutex name.  string.GetHashCode() is randomized per process in
    // .NET Framework, so it can never identify an already-running controller reliably.
    static string MutexName()
    {
        using (System.Security.Cryptography.SHA1 sha = System.Security.Cryptography.SHA1.Create())
        {
            byte[] h = sha.ComputeHash(Encoding.UTF8.GetBytes(Root));
            StringBuilder sb = new StringBuilder();
            foreach (byte b in h) sb.Append(b.ToString("x2"));
            return "Local\\AxumeraServer-" + sb.ToString();
        }
    }
}
