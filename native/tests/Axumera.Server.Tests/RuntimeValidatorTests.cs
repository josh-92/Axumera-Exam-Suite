using Axumera.Core.Server;
using Xunit;

namespace Axumera.Server.Tests;

public class RuntimeValidatorTests : IDisposable
{
    private readonly string _root;

    public RuntimeValidatorTests()
    {
        _root = Path.Combine(Path.GetTempPath(), "axumera-validate-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path.Combine(_root, "config"));
        File.WriteAllText(Path.Combine(_root, "config", "ports.json"), "{}");
    }

    public void Dispose()
    {
        try
        {
            Directory.Delete(_root, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    private ServerConfiguration Config => ServerConfiguration.Load(_root).Value!;

    [Fact]
    public void Empty_root_reports_fatal_missing_files()
    {
        var issues = RuntimeValidator.Validate(Config);

        Assert.NotEmpty(issues);
        Assert.Contains(issues, i => i.Fatal && i.Label == "Apache httpd");
        Assert.Contains(issues, i => i.Fatal && i.Label == "MariaDB mysqld");
        Assert.Contains(issues, i => i.Fatal && i.Label == "Health endpoint");
    }

    [Fact]
    public void Complete_layout_reports_no_fatal_issues()
    {
        CreateFile("runtime/apache/bin/httpd.exe");
        CreateFile("runtime/php/php.exe");
        CreateFile("runtime/php/php8apache2_4.dll");
        CreateFile("runtime/php/ext/php_pdo_mysql.dll");
        CreateFile("runtime/mariadb/bin/mysqld.exe");
        CreateFile("runtime/mariadb/bin/mysqladmin.exe");
        CreateFile("runtime/mariadb/bin/mysql.exe");
        CreateFile("config/ports.json");
        CreateFile("application/eaes_exam_system/health.php");
        CreateFile("application/eaes_exam_system/.env");

        var issues = RuntimeValidator.Validate(Config);

        Assert.DoesNotContain(issues, i => i.Fatal);
    }

    [Fact]
    public void Missing_installation_lock_is_non_fatal()
    {
        CreateFile("runtime/apache/bin/httpd.exe");
        CreateFile("runtime/php/php.exe");
        CreateFile("runtime/php/php8apache2_4.dll");
        CreateFile("runtime/php/ext/php_pdo_mysql.dll");
        CreateFile("runtime/mariadb/bin/mysqld.exe");
        CreateFile("runtime/mariadb/bin/mysqladmin.exe");
        CreateFile("runtime/mariadb/bin/mysql.exe");
        CreateFile("config/ports.json");
        CreateFile("application/eaes_exam_system/health.php");
        CreateFile("application/eaes_exam_system/.env");

        var issues = RuntimeValidator.Validate(Config);

        Assert.Contains(issues, i => !i.Fatal && i.Label == "Installation lock");
        Assert.DoesNotContain(issues, i => i.Fatal);
    }

    private void CreateFile(string relative)
    {
        var full = Path.Combine(_root, relative);
        Directory.CreateDirectory(Path.GetDirectoryName(full)!);
        File.WriteAllText(full, "x");
    }
}
