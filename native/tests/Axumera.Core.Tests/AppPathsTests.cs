using Axumera.Core.Paths;
using Xunit;

namespace Axumera.Core.Tests;

public class AppPathsTests
{
    [Fact]
    public void Data_directory_is_isolated_under_local_app_data()
    {
        var root = Path.GetFullPath(AppPaths.BaseDataDirectory);
        var local = Path.GetFullPath(System.Environment.GetFolderPath(System.Environment.SpecialFolder.LocalApplicationData));

        Assert.StartsWith(local + Path.DirectorySeparatorChar, root, StringComparison.OrdinalIgnoreCase);
        Assert.EndsWith("Axumera 2.0", root, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Webview_user_data_folder_is_per_application()
    {
        var controlPanel = AppPaths.WebView2UserDataDirectory("ControlPanel");
        var student = AppPaths.WebView2UserDataDirectory("Student");

        Assert.NotEqual(controlPanel, student);
        Assert.StartsWith(AppPaths.WebView2RootDirectory, controlPanel, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void Paths_never_touch_the_production_install_directory()
    {
        Assert.DoesNotContain("Program Files", AppPaths.BaseDataDirectory, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("ProgramData", AppPaths.BaseDataDirectory, StringComparison.OrdinalIgnoreCase);
    }
}
