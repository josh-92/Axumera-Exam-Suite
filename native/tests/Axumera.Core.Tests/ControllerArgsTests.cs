using Axumera.Core.Server;
using Xunit;

namespace Axumera.Core.Tests;

public class ControllerArgsTests
{
    [Fact]
    public void Equals_form_returns_value()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(
            new[] { "--headless", "--runtime-root=C:\\Axumera\\prod", "start" }, out var present);

        Assert.True(present);
        Assert.Equal("C:\\Axumera\\prod", value);
    }

    [Fact]
    public void Space_form_returns_value()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(
            new[] { "--headless", "--runtime-root", "C:\\Axumera\\prod", "start" }, out var present);

        Assert.True(present);
        Assert.Equal("C:\\Axumera\\prod", value);
    }

    [Fact]
    public void Quoted_value_is_trimmed()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(
            new[] { "--runtime-root=\"C:\\Axumera\\prod\"", "start" }, out var present);

        Assert.True(present);
        Assert.Equal("C:\\Axumera\\prod", value);
    }

    [Fact]
    public void Absent_argument_reports_not_present()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(new[] { "--headless", "start" }, out var present);

        Assert.False(present);
        Assert.Null(value);
    }

    [Fact]
    public void Missing_value_reports_present_with_null()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(new[] { "--runtime-root" }, out var present);

        Assert.True(present);
        Assert.Null(value);
    }

    [Fact]
    public void Space_form_takes_next_arg_even_when_it_looks_like_a_command()
    {
        // The parser is positional: the token after --runtime-root is the value
        // ("start" here). Program.ResolveRuntimeRoot then rejects it via the
        // directory existence check, so a mistyped command can never silently
        // fall back to the config file.
        var value = ControllerArgs.TryGetRuntimeRoot(new[] { "--runtime-root", "start" }, out var present);

        Assert.True(present);
        Assert.Equal("start", value);
    }

    [Fact]
    public void Empty_value_reports_present_with_null()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(new[] { "--runtime-root=   " }, out var present);

        Assert.True(present);
        Assert.Null(value);
    }

    [Fact]
    public void Case_insensitive_flag_name()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(
            new[] { "--RUNTIME-ROOT", "C:\\Axumera\\prod" }, out var present);

        Assert.True(present);
        Assert.Equal("C:\\Axumera\\prod", value);
    }

    [Fact]
    public void Null_args_reports_not_present()
    {
        var value = ControllerArgs.TryGetRuntimeRoot(null!, out var present);

        Assert.False(present);
        Assert.Null(value);
    }
}
