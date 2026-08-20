using System.Reflection;
using Axumera.Core.Branding;
using Axumera.Core.Student;
using Axumera.Ui;

namespace Axumera.Student;

/// <summary>
/// Branded "connect to the school server" dialog for the Student client.
/// NOT a browser URL bar: the student enters only the server address (and the
/// port, defaulted from the configured runtime) and the application builds the
/// Axumera URL internally. Only the address/port are persisted — never
/// passwords.
/// </summary>
public sealed class ServerEntryDialog : Form
{
    private readonly TextBox _addressBox = new()
    {
        Font = Theme.BodyFont,
        Location = new Point(24, 74),
        Size = new Size(360, 26),
    };
    private readonly TextBox _portBox = new()
    {
        Font = Theme.BodyFont,
        Location = new Point(400, 74),
        Size = new Size(64, 26),
    };
    private readonly Button _connectButton = new()
    {
        Text = "CONNECT",
        Font = Theme.CaptionFont,
        BackColor = Theme.Gold,
        ForeColor = Theme.White,
        FlatStyle = FlatStyle.Flat,
        Size = new Size(120, 32),
        Location = new Point(344, 122),
    };
    private readonly Button _cancelButton = new()
    {
        Text = "Cancel",
        Font = Theme.CaptionFont,
        BackColor = Theme.LightGray,
        ForeColor = Theme.DeepNavy,
        FlatStyle = FlatStyle.Flat,
        Size = new Size(90, 32),
        Location = new Point(470, 122),
    };

    public string ServerAddress { get; private set; } = string.Empty;
    public int ApachePort { get; private set; } = StudentClientConfiguration.DevApachePort;

    public ServerEntryDialog(string? initialAddress, int initialPort)
    {
        Text = "Connect to Axumera Server";
        Icon = EmbeddedAssets.LoadIcon(GetType().Assembly, "Axumera.Student.Resources.app.ico");
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        StartPosition = FormStartPosition.CenterParent;
        ClientSize = new Size(590, 172);
        Theme.Apply(this);

        Controls.Add(new Panel { Dock = DockStyle.Top, Height = 4, BackColor = Theme.Gold });

        var title = new Label
        {
            Text = "School Server Connection",
            Font = Theme.SectionFont,
            ForeColor = Theme.DeepNavy,
            Location = new Point(22, 16),
            Size = new Size(300, 22),
        };
        Controls.Add(title);

        var hint = new Label
        {
            Text = "Enter the address of the Axumera school server (e.g. 192.168.1.11).",
            Font = Theme.CaptionFont,
            ForeColor = Theme.Muted,
            Location = new Point(24, 44),
            Size = new Size(520, 18),
        };
        Controls.Add(hint);

        var addressLabel = new Label
        {
            Text = "Server address",
            Font = Theme.CaptionFont,
            ForeColor = Theme.DeepNavy,
            Location = new Point(24, 56),
            Size = new Size(200, 14),
        };
        Controls.Add(addressLabel);

        var portLabel = new Label
        {
            Text = "Port",
            Font = Theme.CaptionFont,
            ForeColor = Theme.DeepNavy,
            Location = new Point(400, 56),
            Size = new Size(60, 14),
        };
        Controls.Add(portLabel);

        _addressBox.Text = initialAddress ?? string.Empty;
        _portBox.Text = initialPort.ToString();
        Controls.Add(_addressBox);
        Controls.Add(_portBox);

        _connectButton.Click += (_, _) => TryConnect();
        _cancelButton.Click += (_, _) => { DialogResult = DialogResult.Cancel; Close(); };
        Controls.Add(_connectButton);
        Controls.Add(_cancelButton);

        AcceptButton = _connectButton;
        CancelButton = _cancelButton;
    }

    private void TryConnect()
    {
        var normalized = StudentClientConfiguration.NormalizeAddress(_addressBox.Text);
        if (normalized.Length == 0)
        {
            MessageBox.Show(
                "Enter the school server address (IP address or host name), e.g. 192.168.1.11.",
                "Axumera Student",
                MessageBoxButtons.OK,
                MessageBoxIcon.Warning);
            _addressBox.Focus();
            return;
        }

        if (!int.TryParse(_portBox.Text.Trim(), out var port) || port is <= 0 or > 65535)
        {
            MessageBox.Show(
                "Enter a valid server port (1–65535).",
                "Axumera Student",
                MessageBoxButtons.OK,
                MessageBoxIcon.Warning);
            _portBox.Focus();
            return;
        }

        ServerAddress = normalized;
        ApachePort = port;
        DialogResult = DialogResult.OK;
        Close();
    }
}
