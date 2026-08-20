using Axumera.Core.Branding;
using Axumera.Core.Versioning;
using Xunit;

namespace Axumera.Core.Tests;

public class BrandingTests
{
    [Fact]
    public void Official_palette_is_exact()
    {
        Assert.Equal("#D3A029", AxumeraBrand.GoldHex);
        Assert.Equal("#0C2036", AxumeraBrand.DeepNavyHex);
        Assert.Equal("#FFFFFF", AxumeraBrand.WhiteHex);
        Assert.Equal(unchecked((int)0xFFD3A029), AxumeraBrand.GoldArgb);
        Assert.Equal(unchecked((int)0xFF0C2036), AxumeraBrand.DeepNavyArgb);
        Assert.Equal(unchecked((int)0xFFFFFFFF), AxumeraBrand.WhiteArgb);
    }

    [Fact]
    public void Copyright_line_is_exact()
    {
        Assert.Equal("© 2026 Axumera Technologies. All rights reserved.", AxumeraBrand.CopyrightLine);
    }

    [Fact]
    public void Product_display_name_never_doubles_the_family_prefix()
    {
        Assert.Equal("Axumera Control Panel — Axumera 2.0", AxumeraBrand.ProductDisplayName("Axumera Control Panel"));
        Assert.Equal("Axumera Student — Axumera 2.0", AxumeraBrand.ProductDisplayName("Axumera Student"));
        Assert.Equal("Axumera Server — Axumera 2.0", AxumeraBrand.ProductDisplayName("Server"));
    }

    [Fact]
    public void Version_is_production_identity()
    {
        Assert.Equal("2.0.0", ProductVersion.Version);
        Assert.Contains("Axumera 2.0 · Version 2.0.0", ProductVersion.FullLabel, StringComparison.Ordinal);
        Assert.DoesNotContain("DEVELOPMENT", ProductVersion.FullLabel, StringComparison.Ordinal);
        Assert.DoesNotContain("Phase", ProductVersion.FullLabel, StringComparison.Ordinal);
    }
}
