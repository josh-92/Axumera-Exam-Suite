using System.Drawing;
using System.Reflection;
using Axumera.Core.Branding;
using Axumera.Core.Versioning;

namespace Axumera.Ui;

/// <summary>
/// Consistent Axumera startup experience: white background, official logo,
/// deep-navy text, gold accent, clean loading indicator. Implemented natively
/// (no static browser page required).
/// </summary>
public sealed class AxumeraSplashForm : Form
{
    private readonly GoldProgressBar _bar;
    private readonly System.Windows.Forms.Timer _timer;

    public AxumeraSplashForm(string applicationName, string subtitle)
    {
        Text = AxumeraBrand.ProductDisplayName(applicationName);
        FormBorderStyle = FormBorderStyle.None;
        StartPosition = FormStartPosition.CenterScreen;
        Size = new Size(560, 300);
        ShowInTaskbar = false;
        Theme.Apply(this);

        Controls.Add(new Panel { Dock = DockStyle.Top, Height = 6, BackColor = Theme.Gold });

        var logo = new PictureBox
        {
            SizeMode = PictureBoxSizeMode.Zoom,
            Size = new Size(320, 84),
            Location = new Point((ClientSize.Width - 320) / 2, 36),
            BackColor = Theme.White,
            Image = EmbeddedAssets.LoadImage(Assembly.GetExecutingAssembly(), "Axumera.Ui.Resources.symbol.png"),
        };
        Controls.Add(logo);

        var title = new Label
        {
            Text = applicationName,
            Font = Theme.TitleFont,
            ForeColor = Theme.DeepNavy,
            BackColor = Theme.White,
            TextAlign = ContentAlignment.MiddleCenter,
            Location = new Point(0, 130),
            Size = new Size(ClientSize.Width, 36),
        };
        Controls.Add(title);

        var sub = new Label
        {
            Text = subtitle,
            Font = Theme.SubtitleFont,
            ForeColor = Theme.Muted,
            BackColor = Theme.White,
            TextAlign = ContentAlignment.MiddleCenter,
            Location = new Point(0, 168),
            Size = new Size(ClientSize.Width, 24),
        };
        Controls.Add(sub);

        _bar = new GoldProgressBar
        {
            Location = new Point(90, 222),
            Size = new Size(ClientSize.Width - 180, 8),
        };
        Controls.Add(_bar);

        var footer = new Label
        {
            Text = Axumera.Core.Versioning.ProductVersion.FullLabel,
            Font = Theme.CaptionFont,
            ForeColor = Theme.Muted,
            BackColor = Theme.White,
            TextAlign = ContentAlignment.MiddleCenter,
            Location = new Point(0, ClientSize.Height - 28),
            Size = new Size(ClientSize.Width, 20),
        };
        Controls.Add(footer);

        _timer = new System.Windows.Forms.Timer { Interval = 30 };
        _timer.Tick += (_, _) =>
        {
            _bar.Progress += 0.02f;
            if (_bar.Progress >= 1f)
            {
                _timer.Stop();
            }
        };
        _timer.Start();
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _timer.Dispose();
        }

        base.Dispose(disposing);
    }
}
