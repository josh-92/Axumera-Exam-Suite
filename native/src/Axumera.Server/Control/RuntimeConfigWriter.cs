using System.Text;
using Axumera.Core.Server;

namespace Axumera.Server.Control;

/// <summary>
/// Generates the three runtime configuration files exactly as the proven
/// production controller does, parameterized from the validated
/// <see cref="ServerConfiguration"/>. The templates (modules, PHP handler,
/// security denials, log formats, session settings) are preserved verbatim.
/// </summary>
public static class RuntimeConfigWriter
{
    public static void Write(ServerConfiguration config, Action<string>? log = null)
    {
        string root = config.InstallRoot.Replace('\\', '/');
        string runtime = config.RuntimeRoot.Replace('\\', '/');
        string app = config.ApplicationRoot.Replace('\\', '/');
        string logs = config.LogsRoot.Replace('\\', '/');
        string data = config.DataRoot.Replace('\\', '/');

        Directory.CreateDirectory(config.LogsRoot);
        Directory.CreateDirectory(Path.Combine(config.RuntimeRoot, "apache", "logs"));
        Directory.CreateDirectory(Path.Combine(data, "tmp"));
        Directory.CreateDirectory(Path.Combine(config.ApplicationRoot, "storage", "sessions"));

        string mariaIni =
            "[client]\nhost=127.0.0.1\nport=" + config.MariaDbPort + "\n\n" +
            "[mysqld]\n" +
            "basedir=\"" + runtime + "/mariadb\"\n" +
            "datadir=\"" + data + "/mariadb\"\n" +
            "tmpdir=\"" + data + "/tmp\"\n" +
            "port=" + config.MariaDbPort + "\n" +
            "bind-address=127.0.0.1\n" +
            "character-set-server=utf8mb4\n" +
            "collation-server=utf8mb4_general_ci\n" +
            "default-storage-engine=InnoDB\n" +
            "log_error=\"" + logs + "/mariadb-error.log\"\n" +
            "pid-file=\"" + data + "/mariadb/axumera-mariadb.pid\"\n";

        string phpIni =
            "[PHP]\n" +
            "extension_dir=\"" + runtime + "/php/ext\"\n" +
            "extension=pdo_mysql\n" +
            "extension=mbstring\n" +
            "extension=openssl\n" +
            "extension=fileinfo\n" +
            "date.timezone=Africa/Addis_Ababa\n" +
            "display_errors=Off\n" +
            "log_errors=On\n" +
            "error_log=\"" + logs + "/php-runtime-error.log\"\n" +
            "session.save_path=\"" + app + "/storage/sessions\"\n" +
            "session.use_strict_mode=1\n" +
            "session.cookie_httponly=1\n" +
            "upload_tmp_dir=\"" + data + "/tmp\"\n" +
            "upload_max_filesize=40M\n" +
            "post_max_size=40M\n" +
            "expose_php=Off\n";

        string httpdConf =
            "ServerRoot \"" + runtime + "/apache\"\n" +
            "Listen " + config.BindAddress + ":" + config.ApachePort + "\n" +
            "LoadModule authn_core_module modules/mod_authn_core.so\n" +
            "LoadModule authz_core_module modules/mod_authz_core.so\n" +
            "LoadModule authz_host_module modules/mod_authz_host.so\n" +
            "LoadModule mime_module modules/mod_mime.so\n" +
            "LoadModule dir_module modules/mod_dir.so\n" +
            "LoadModule alias_module modules/mod_alias.so\n" +
            "LoadModule rewrite_module modules/mod_rewrite.so\n" +
            "LoadModule headers_module modules/mod_headers.so\n" +
            "LoadModule log_config_module modules/mod_log_config.so\n" +
            "LoadFile \"" + runtime + "/php/php8ts.dll\"\n" +
            "LoadFile \"" + runtime + "/php/libpq.dll\"\n" +
            "LoadFile \"" + runtime + "/php/libsqlite3.dll\"\n" +
            "LoadModule php_module \"" + runtime + "/php/php8apache2_4.dll\"\n" +
            "PHPIniDir \"" + runtime + "/php\"\n" +
            "ServerName 127.0.0.1:" + config.ApachePort + "\n" +
            "PidFile \"" + logs + "/apache.pid\"\n" +
            "DocumentRoot \"" + app + "\"\n" +
            "<Directory \"" + app + "\">\n" +
            "Options FollowSymLinks\n" +
            "AllowOverride All\n" +
            "Require all granted\n" +
            "</Directory>\n" +
            "Alias /eaes_exam_system \"" + app + "\"\n" +
            "<Directory \"" + app + "/app\">\n" +
            "Require all denied\n" +
            "</Directory>\n" +
            "<Directory \"" + app + "/database\">\n" +
            "Require all denied\n" +
            "</Directory>\n" +
            "<Directory \"" + app + "/storage\">\n" +
            "Require all denied\n" +
            "</Directory>\n" +
            "<FilesMatch \"^\\.env\">\n" +
            "Require all denied\n" +
            "</FilesMatch>\n" +
            "<FilesMatch \"\\.php$\">\n" +
            "SetHandler application/x-httpd-php\n" +
            "</FilesMatch>\n" +
            "DirectoryIndex index.php index.html\n" +
            "ErrorLog \"" + logs + "/apache-error.log\"\n" +
            "LogFormat \"%h %l %u %t \\\"%r\\\" %>s %b \\\"%{Referer}i\\\" \\\"%{User-Agent}i\\\"\" combined\n" +
            "CustomLog \"" + logs + "/apache-access.log\" combined\n";

        File.WriteAllText(config.MariaIniFile, mariaIni, Encoding.ASCII);
        File.WriteAllText(config.PhpIniFile, phpIni, Encoding.ASCII);
        File.WriteAllText(config.ApacheConfigFile, httpdConf, Encoding.ASCII);

        log?.Invoke($"Runtime configuration written (MariaDB {config.MariaDbPort}, Apache {config.ApachePort} on {config.BindAddress}).");
    }
}
