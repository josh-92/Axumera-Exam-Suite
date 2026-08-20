using System.Drawing;
using Axumera.Core.Branding;
using Axumera.Ui;

namespace Axumera.Server;

/// <summary>
/// Developer-facing diagnostics view. The normal UI stays clean; this form
/// tails the controller log for troubleshooting.
/// </summary>
public sealed class DiagnosticsForm : Form
{
    private readonly string _logFile;
    private readonly RichTextBox _logBox = new()
    {
        Dock = DockStyle.Fill,
        ReadOnly = true,
        BackColor = Theme.White,
        ForeColor = Theme.DeepNavy,
        Font = new Font("Consolas", 9f),
        WordWrap = false,
    };
    private readonly System.Windows.Forms.Timer _tailTimer;
    private long _lastLength;

    public DiagnosticsForm(string logFile)
    {
        _logFile = logFile;

        Text = "Axumera Server — Diagnostics";
        Size = new Size(860, 520);
        StartPosition = FormStartPosition.CenterParent;
        Theme.Apply(this);

        Controls.Add(new Panel { Dock = DockStyle.Top, Height = 4, BackColor = Theme.Gold });
        Controls.Add(BuildFooter());

        // Fill first (docked last).
        var content = new Panel { Dock = DockStyle.Fill, BackColor = Theme.White, Padding = new Padding(8) };
        Controls.Add(content);
        content.Controls.Add(_logBox);

        _tailTimer = new System.Windows.Forms.Timer { Interval = 1000 };
        _tailTimer.Tick += (_, _) => Tail();
        _tailTimer.Start();

        Tail();
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        _tailTimer.Stop();
        base.OnFormClosing(e);
    }

    private void Tail()
    {
        try
        {
            if (!File.Exists(_logFile))
            {
                return;
            }

            var info = new FileInfo(_logFile);
            if (info.Length == _lastLength)
            {
                return;
            }

            var lines = File.ReadLines(_logFile).TakeLast(400).ToArray();
            _lastLength = info.Length;

            if (_logBox.InvokeRequired)
            {
                BeginInvoke(() => SetLines(lines));
            }
            else
            {
                SetLines(lines);
            }
        }
        catch (IOException)
        {
        }
    }

    private void SetLines(string[] lines)
    {
        _logBox.Text = string.Join(Environment.NewLine, lines);
        _logBox.SelectionStart = _logBox.TextLength;
        _logBox.ScrollToCaret();
    }

    private Label BuildFooter() => new()
    {
        Dock = DockStyle.Bottom,
        Height = 26,
        Text = $"{AxumeraBrand.CopyrightLine}   ·   {Axumera.Core.Versioning.ProductVersion.FullLabel}",
        Font = Theme.CaptionFont,
        ForeColor = Theme.Muted,
        TextAlign = ContentAlignment.MiddleCenter,
    };
}
