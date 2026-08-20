using Xunit;

namespace Axumera.Licensing.Tests;

public class LicenseStoreRoundTripTests
{
    // Dev-only fake store. Lives in the test project so a bypass can never ship in the library.
    private sealed class MemoryStore : ILicenseStore
    {
        public LicenseInfo? Stored { get; private set; }

        public LicenseInfo? Load() => Stored;

        public void Save(LicenseInfo license) => Stored = license;

        public void Clear() => Stored = null;
    }

    [Fact]
    public void Store_round_trip_preserves_license()
    {
        var store = new MemoryStore();
        var license = new LicenseInfo
        {
            LicenseId = "L1",
            Product = "Axumera 2.0",
            LicensedTo = "Test School",
            MachineId = "M1",
            State = LicenseState.Active,
            IssuedUtc = DateTimeOffset.UtcNow.AddDays(-5),
        };

        store.Save(license);
        var loaded = store.Load();

        Assert.NotNull(loaded);
        Assert.Equal("L1", loaded!.LicenseId);
        Assert.Equal("Axumera 2.0", loaded.Product);
        Assert.Equal(LicenseState.Active, loaded.State);

        store.Clear();
        Assert.Null(store.Load());
    }
}
