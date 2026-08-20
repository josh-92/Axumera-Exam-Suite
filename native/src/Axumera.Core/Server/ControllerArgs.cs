namespace Axumera.Core.Server;

/// <summary>
/// Parses controller command-line arguments. Accepts both
/// <c>--runtime-root=&lt;path&gt;</c> and <c>--runtime-root &lt;path&gt;</c> so a
/// space-separated value can never be silently ignored (ignoring it would let
/// the controller fall back to the app-data config and target the wrong
/// runtime — the mis-targeting failure mode the deployment safety rules guard
/// against).
/// </summary>
public static class ControllerArgs
{
    /// <summary>
    /// Returns the runtime-root value from the argument list.
    /// </summary>
    /// <param name="args">The process argument list.</param>
    /// <param name="present">
    /// False when no <c>--runtime-root</c> argument exists at all; true when the
    /// argument was given (even if malformed, in which case the returned value
    /// is null).
    /// </param>
    public static string? TryGetRuntimeRoot(string[] args, out bool present)
    {
        present = false;
        if (args is null || args.Length == 0)
        {
            return null;
        }

        for (var i = 0; i < args.Length; i++)
        {
            var arg = args[i];
            if (string.IsNullOrWhiteSpace(arg))
            {
                continue;
            }

            if (arg.StartsWith("--runtime-root=", StringComparison.OrdinalIgnoreCase))
            {
                present = true;
                var value = arg.Substring("--runtime-root=".Length).Trim('"', ' ');
                return string.IsNullOrWhiteSpace(value) ? null : value;
            }

            if (string.Equals(arg, "--runtime-root", StringComparison.OrdinalIgnoreCase))
            {
                present = true;
                // Missing or empty value: report null so the caller surfaces an
                // error instead of silently falling back to the config file.
                if (i + 1 >= args.Length)
                {
                    return null;
                }

                var value = args[i + 1].Trim('"', ' ');
                return string.IsNullOrWhiteSpace(value) ? null : value;
            }
        }

        return null;
    }
}
