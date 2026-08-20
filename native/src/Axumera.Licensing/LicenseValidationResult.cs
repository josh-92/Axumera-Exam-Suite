namespace Axumera.Licensing;

/// <summary>Outcome of a license validation pass.</summary>
public sealed record LicenseValidationResult
{
    public LicenseState State { get; init; }

    public string Reason { get; init; } = string.Empty;

    public DateTimeOffset CheckedUtc { get; init; } = DateTimeOffset.UtcNow;

    public bool IsValid => State == LicenseState.Active;
}
