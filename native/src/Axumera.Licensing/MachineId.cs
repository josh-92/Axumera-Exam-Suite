using System.Security.Cryptography;
using System.Text;

namespace Axumera.Licensing;

/// <summary>
/// Machine-fingerprint helpers. Phase 1 ships the pure normalization/comparison
/// rules only; the actual hardware-bound fingerprint provider is a Phase 2+
/// contract (see <see cref="IMachineIdProvider"/>). No production machine is read here.
/// </summary>
public static class MachineId
{
    /// <summary>Canonical fingerprint form: uppercase, trimmed, no separators.</summary>
    public static string Normalize(string machineId)
    {
        if (string.IsNullOrWhiteSpace(machineId))
        {
            return string.Empty;
        }

        return new string(machineId.Where(char.IsLetterOrDigit).ToArray()).ToUpperInvariant();
    }

    /// <summary>Case- and separator-insensitive comparison of two fingerprints.</summary>
    public static bool SameMachine(string left, string right)
    {
        var a = Normalize(left);
        var b = Normalize(right);
        return a.Length > 0 && a == b;
    }

    /// <summary>One-way fingerprint of arbitrary bytes (used by future providers).</summary>
    public static string Fingerprint(ReadOnlySpan<byte> material)
    {
        var hash = SHA256.HashData(material);
        return Convert.ToHexString(hash);
    }

    public static string FingerprintUtf8(string material) => Fingerprint(Encoding.UTF8.GetBytes(material));
}
