namespace Axumera.Licensing;

/// <summary>Persistence contract for license records (Phase 2+: file store, registry, etc.).</summary>
public interface ILicenseStore
{
    LicenseInfo? Load();

    void Save(LicenseInfo license);

    void Clear();
}

/// <summary>Validation contract. Phase 1 defines the shape; enforcement lands in Phase 2+.</summary>
public interface ILicenseValidator
{
    LicenseValidationResult Validate(LicenseInfo license, DateTimeOffset? nowUtc = null);
}

/// <summary>Reads the current license for this product/application.</summary>
public interface ILicenseProvider
{
    LicenseInfo? GetCurrent();

    Task<LicenseInfo?> GetCurrentAsync(CancellationToken cancellationToken = default);
}

/// <summary>
/// Contract for producing this machine's fingerprint. Phase 1 ships only the
/// contract and pure helpers; a real (non-production-touching) provider is Phase 2+.
/// </summary>
public interface IMachineIdProvider
{
    Task<string> GetMachineIdAsync(CancellationToken cancellationToken = default);
}
