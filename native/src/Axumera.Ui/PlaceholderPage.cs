using System.Reflection;
using Axumera.Core.Versioning;

namespace Axumera.Ui;

/// <summary>
/// Renders the safe development placeholder page (embedded template with
/// tokens applied). This page is the only content loaded in Phase 1 — it is
/// NOT the production admin panel or exam UI.
/// </summary>
public static class PlaceholderPage
{
    private const string TemplateResource = "Axumera.Ui.Resources.SafeTestPage.html";

    public static string Build(string applicationName, string applicationKey)
    {
        using var stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(TemplateResource)
            ?? throw new InvalidOperationException($"Embedded resource not found: {TemplateResource}");
        using var reader = new StreamReader(stream);
        var template = reader.ReadToEnd();

        return template
            .Replace("{{APP_NAME}}", applicationName)
            .Replace("{{APP_KEY}}", applicationKey)
            .Replace("{{VERSION}}", ProductVersion.FullLabel);
    }
}
