namespace Axumera.Licensing;

/// <summary>
/// License record model. Deliberately carries NO secret material: no private
/// keys, no signatures, no credentials. Only identity and state facts.
/// </summary>
public sealed record LicenseInfo
{
    public string LicenseId { get; init; } = string.Empty;

    public string Product { get; init; } = string.Empty;

    public string? Edition { get; init; }

    public string LicensedTo { get; init; } = string.Empty;

    /// <summary>Machine fingerprint this license is bound to (see <see cref="MachineId"/>).</summary>
    public string MachineId { get; init; } = string.Empty;

    public DateTimeOffset IssuedUtc { get; init; }

    public DateTimeOffset? ExpiresUtc { get; init; }

    public LicenseState State { get; init; } = LicenseState.NotActivated;

    public ActivationState ActivationState { get; init; } = ActivationState.PendingActivation;
}
