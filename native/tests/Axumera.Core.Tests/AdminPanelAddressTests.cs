using Axumera.Core.Server;
using Xunit;

namespace Axumera.Core.Tests;

public class AdminPanelAddressTests
{
    [Fact]
    public void Login_uses_the_configured_runtime_port_and_existing_php_route()
    {
        var configuration = new ServerConfiguration { ApachePort = 8090, MariaDbPort = 3310 };

        var url = AdminPanelAddress.Login(configuration);

        Assert.Equal("http://127.0.0.1:8090/adminlogin.php", url.AbsoluteUri);
    }
}
