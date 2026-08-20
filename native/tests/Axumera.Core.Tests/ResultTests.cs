using Axumera.Core.Common;
using Xunit;

namespace Axumera.Core.Tests;

public class ResultTests
{
    [Fact]
    public void Ok_carries_value_and_success()
    {
        var result = Result<int>.Ok(42);
        Assert.True(result.Succeeded);
        Assert.Equal(42, result.Value);
    }

    [Fact]
    public void Fail_carries_error_and_no_value()
    {
        var result = Result<int>.Fail("E1", "boom");
        Assert.False(result.Succeeded);
        Assert.Equal("E1", result.ErrorCode);
        Assert.Equal("boom", result.ErrorMessage);
    }

    [Fact]
    public void Generic_fail_maps_to_non_generic()
    {
        var result = Result<int>.Fail("E1", "boom").ToResult();
        Assert.False(result.Succeeded);
        Assert.Equal("E1", result.ErrorCode);
    }

    [Fact]
    public void Plain_result_ok_and_fail()
    {
        Assert.True(Result.Ok().Succeeded);
        Assert.False(Result.Fail("E2", "nope").Succeeded);
    }
}
