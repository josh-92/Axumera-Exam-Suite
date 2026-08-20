using System.Reflection;
using Axumera.Ui;

namespace Axumera.Student;

/// <summary>
/// Branded confirmation for leaving an in-progress exam. The student must
/// explicitly confirm that exiting is recorded as an integrity event — the
/// shell never silently closes during an exam.
/// </summary>
public sealed class ExamExitConfirmDialog : Form
{
    public ExamExitConfirmDialog(string title, string message)
    {
        Text = title;
        Icon = EmbeddedAssets.LoadIcon(GetType().Assembly, "Axumera.Student.Resources.app.ico");
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        StartPosition = FormStartPosition.CenterParent;
        ClientSize = new Size(480, 196);
        Theme.Apply(this);

        Controls.Add(new Panel { Dock = DockStyle.Top, Height = 4, BackColor = Theme.Gold });

        var heading = new Label
        {
            Text = title,
            Font = Theme.SectionFont,
            ForeColor = Theme.DeepNavy,
            Location = new Point(26, 18),
            Size = new Size(430, 22),
        };
        Controls.Add(heading);

        var body = new Label
        {
            Text = message,
            Font = Theme.BodyFont,
            ForeColor = Theme.Muted,
            Location = new Point(26, 50),
            Size = new Size(430, 92),
        };
        Controls.Add(body);

        var cancel = new Button
        {
            Text = "Cancel",
            Font = Theme.CaptionFont,
            BackColor = Theme.LightGray,
            ForeColor = Theme.DeepNavy,
            FlatStyle = FlatStyle.Flat,
            Size = new Size(110, 32),
            Location = new Point(210, 150),
            DialogResult = DialogResult.Cancel,
        };
        var exit = new Button
        {
            Text = "Exit Exam",
            Font = Theme.CaptionFont,
            BackColor = Theme.Gold,
            ForeColor = Theme.White,
            FlatStyle = FlatStyle.Flat,
            Size = new Size(130, 32),
            Location = new Point(326, 150),
            DialogResult = DialogResult.OK,
        };
        Controls.Add(cancel);
        Controls.Add(exit);
        AcceptButton = cancel;
        CancelButton = cancel;
    }
}
