using System.Drawing;
using Axumera.Core.Branding;

namespace Axumera.Ui;

/// <summary>
/// Light theme for all native applications. Fixed light palette (official
/// Axumera colors) — no dark mode, no OS theme switching.
/// </summary>
public static class Theme
{
    public static readonly Color Gold = Color.FromArgb(AxumeraBrand.GoldArgb);
    public static readonly Color DeepNavy = Color.FromArgb(AxumeraBrand.DeepNavyArgb);
    public static readonly Color White = Color.FromArgb(AxumeraBrand.WhiteArgb);
    public static readonly Color LightGray = Color.FromArgb(AxumeraBrand.LightGrayArgb);
    public static readonly Color BorderGray = Color.FromArgb(AxumeraBrand.BorderGrayArgb);
    public static readonly Color Muted = Color.FromArgb(AxumeraBrand.MutedArgb);

    public static readonly Font TitleFont = new("Segoe UI", 15f, FontStyle.Bold);
    public static readonly Font SubtitleFont = new("Segoe UI", 9f);
    public static readonly Font SectionFont = new("Segoe UI", 10.5f, FontStyle.Bold);
    public static readonly Font BodyFont = new("Segoe UI", 9.5f);
    public static readonly Font CaptionFont = new("Segoe UI", 8f);

    public static void Apply(Form form)
    {
        form.BackColor = White;
        form.ForeColor = DeepNavy;
    }
}
