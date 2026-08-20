namespace Axumera.Licensing;

/// <summary>
/// Pure license-state rules. No I/O, no keys, no enforcement — only the
/// deterministic state derivation that the future native validator will use.
/// </summary>
public static class LicensingRules
{
    public static bool IsExpired(LicenseInfo license, DateTimeOffset nowUtc) =>
        license.ExpiresUtc is { } expires && nowUtc > expires;

    /// <summary>
    /// Derive the effective state from a stored record and the current time.
    /// A record marked Active that has passed its expiry window becomes Expired;
    /// any other stored state passes through unchanged.
    /// </summary>
    public static LicenseState EffectiveState(LicenseInfo license, DateTimeOffset nowUtc)
    {
        if (IsExpired(license, nowUtc))
        {
            return LicenseState.Expired;
        }

        return license.State;
    }

    public static LicenseValidationResult Validate(LicenseInfo license, DateTimeOffset nowUtc)
    {
        if (string.IsNullOrWhiteSpace(license.LicenseId))
        {
            return new LicenseValidationResult { State = LicenseState.Invalid, Reason = "Missing license id." };
        }

        var state = EffectiveState(license, nowUtc);
        return state switch
        {
            LicenseState.Active => new LicenseValidationResult { State = state, Reason = "License is active." },
            LicenseState.Evaluation => new LicenseValidationResult { State = state, Reason = "Evaluation mode." },
            LicenseState.Expired => new LicenseValidationResult { State = state, Reason = "License has expired." },
            LicenseState.Revoked => new LicenseValidationResult { State = state, Reason = "License was revoked." },
            LicenseState.Invalid => new LicenseValidationResult { State = state, Reason = "License record is invalid." },
            _ => new LicenseValidationResult { State = LicenseState.NotActivated, Reason = "License not activated." },
        };
    }
}
