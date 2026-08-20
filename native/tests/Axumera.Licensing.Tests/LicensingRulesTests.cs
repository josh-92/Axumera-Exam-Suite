using Xunit;

namespace Axumera.Licensing.Tests;

public class LicensingRulesTests
{
    private static LicenseInfo License(LicenseState state, DateTimeOffset? expires = null, string id = "LIC-1") =>
        new()
        {
            LicenseId = id,
            Product = "Axumera 2.0",
            LicensedTo = "Test School",
            MachineId = "ABC123",
            IssuedUtc = DateTimeOffset.UtcNow.AddDays(-10),
            ExpiresUtc = expires,
            State = state,
        };

    private static readonly DateTimeOffset Now = DateTimeOffset.UtcNow;

    [Fact]
    public void Active_unexpired_is_valid()
    {
        var result = LicensingRules.Validate(License(LicenseState.Active, Now.AddDays(30)), Now);

        Assert.True(result.IsValid);
        Assert.Equal(LicenseState.Active, result.State);
    }

    [Fact]
    public void Active_past_expiry_becomes_expired()
    {
        var result = LicensingRules.Validate(License(LicenseState.Active, Now.AddDays(-1)), Now);

        Assert.False(result.IsValid);
        Assert.Equal(LicenseState.Expired, result.State);
    }

    [Fact]
    public void Revoked_stays_revoked_even_before_expiry()
    {
        var result = LicensingRules.Validate(License(LicenseState.Revoked, Now.AddDays(30)), Now);

        Assert.Equal(LicenseState.Revoked, result.State);
    }

    [Fact]
    public void Missing_license_id_is_invalid()
    {
        var result = LicensingRules.Validate(License(LicenseState.Active, Now.AddDays(30), id: ""), Now);

        Assert.Equal(LicenseState.Invalid, result.State);
    }

    [Fact]
    public void Evaluation_state_passes_through()
    {
        var result = LicensingRules.Validate(License(LicenseState.Evaluation, Now.AddDays(14)), Now);

        Assert.Equal(LicenseState.Evaluation, result.State);
    }

    [Fact]
    public void Not_activated_is_reported()
    {
        var result = LicensingRules.Validate(License(LicenseState.NotActivated, Now.AddDays(30)), Now);

        Assert.Equal(LicenseState.NotActivated, result.State);
    }
}
