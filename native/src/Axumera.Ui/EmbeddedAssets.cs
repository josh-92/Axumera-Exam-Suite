using System.Drawing;
using System.Reflection;

namespace Axumera.Ui;

/// <summary>Loads the official branding assets embedded in each application.</summary>
public static class EmbeddedAssets
{
    public static Image LoadImage(Assembly assembly, string resourceName)
    {
        using var stream = assembly.GetManifestResourceStream(resourceName)
            ?? throw new InvalidOperationException($"Embedded resource not found: {resourceName}");
        var copy = new MemoryStream();
        stream.CopyTo(copy);
        copy.Position = 0;
        return Image.FromStream(copy);
    }

    public static Icon LoadIcon(Assembly assembly, string resourceName)
    {
        using var stream = assembly.GetManifestResourceStream(resourceName)
            ?? throw new InvalidOperationException($"Embedded resource not found: {resourceName}");
        var copy = new MemoryStream();
        stream.CopyTo(copy);
        copy.Position = 0;
        return new Icon(copy);
    }
}
