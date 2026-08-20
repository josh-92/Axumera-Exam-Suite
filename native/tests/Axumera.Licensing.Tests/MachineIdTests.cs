using Xunit;

namespace Axumera.Licensing.Tests;

public class MachineIdTests
{
    [Fact]
    public void Normalize_uppercases_and_strips_separators()
    {
        Assert.Equal("ABC123", MachineId.Normalize(" abc-123 "));
        Assert.Equal(string.Empty, MachineId.Normalize("   "));
        Assert.Equal(string.Empty, MachineId.Normalize(""));
    }

    [Fact]
    public void SameMachine_is_case_and_separator_insensitive()
    {
        Assert.True(MachineId.SameMachine("abc-1234", "ABC 1234"));
        Assert.True(MachineId.SameMachine("ABC1234", "abc1234"));
        Assert.False(MachineId.SameMachine("abc-1234", "abc-9999"));
    }

    [Fact]
    public void Fingerprint_is_deterministic_and_sha256_sized()
    {
        var a = MachineId.FingerprintUtf8("machine-material");
        var b = MachineId.FingerprintUtf8("machine-material");

        Assert.Equal(a, b);
        Assert.Equal(64, a.Length); // SHA-256 hex
        Assert.NotEqual(a, MachineId.FingerprintUtf8("machine-material-2"));
    }
}
