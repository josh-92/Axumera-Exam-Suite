namespace Axumera.Core.Server;

/// <summary>A runtime file that failed validation.</summary>
public sealed record ValidationIssue(string Label, string Path, bool Fatal);

/// <summary>
/// Validates that every executable/configuration the controller needs exists
/// under the configured runtime root, before any process is started. Fatal
/// issues block startup; non-fatal issues are reported but do not block.
/// </summary>
public static class RuntimeValidator
{
    public static IReadOnlyList<ValidationIssue> Validate(ServerConfiguration config)
    {
        var issues = new List<ValidationIssue>();

        Check(issues, "Apache httpd", config.ApacheExe, fatal: true);
        Check(issues, "PHP", config.PhpExe, fatal: true);
        Check(issues, "PHP Apache module", Path.Combine(config.RuntimeRoot, "php", "php8apache2_4.dll"), fatal: true);
        Check(issues, "PHP PDO MySQL extension", Path.Combine(config.RuntimeRoot, "php", "ext", "php_pdo_mysql.dll"), fatal: true);
        Check(issues, "MariaDB mysqld", config.MySqldExe, fatal: true);
        Check(issues, "MariaDB admin client", config.MySqlAdminExe, fatal: true);
        Check(issues, "MariaDB client", config.MySqlExe, fatal: true);
        Check(issues, "Port configuration", config.PortsFile, fatal: true);
        Check(issues, "Health endpoint", Path.Combine(config.ApplicationRoot, "health.php"), fatal: true);
        Check(issues, "Application environment (.env)", Path.Combine(config.ApplicationRoot, ".env"), fatal: true);
        Check(issues, "Installation lock", Path.Combine(config.ApplicationRoot, "storage", "installed.lock"), fatal: false);

        return issues;
    }

    private static void Check(List<ValidationIssue> issues, string label, string path, bool fatal)
    {
        if (!File.Exists(path))
        {
            issues.Add(new ValidationIssue(label, path, fatal));
        }
    }
}
