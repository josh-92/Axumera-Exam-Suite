namespace Axumera.Licensing;

/// <summary>Overall license state. Phase 1 defines the vocabulary only; no enforcement.</summary>
public enum LicenseState
{
    /// <summary>No license record present.</summary>
    NotActivated = 0,

    /// <summary>License valid and within its validity window.</summary>
    Active = 1,

    /// <summary>License expired (validity window passed).</summary>
    Expired = 2,

    /// <summary>License record failed validation.</summary>
    Invalid = 3,

    /// <summary>License explicitly revoked.</summary>
    Revoked = 4,

    /// <summary>Time-limited evaluation mode.</summary>
    Evaluation = 5,
}

/// <summary>Activation lifecycle state.</summary>
public enum ActivationState
{
    /// <summary>No activation attempt recorded.</summary>
    PendingActivation = 0,

    /// <summary>Activated and bound to this machine.</summary>
    Activated = 1,

    /// <summary>License record is bound to a different machine.</summary>
    MachineMismatch = 2,

    /// <summary>Previously activated, now deactivated.</summary>
    Deactivated = 3,
}
