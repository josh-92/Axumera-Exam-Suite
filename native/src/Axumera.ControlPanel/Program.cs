using Axumera.Ui;

namespace Axumera.ControlPanel;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        var sentinel = "AXUMERA_SENTINEL_PROGRAM_9F3C";
        if (!string.IsNullOrEmpty(sentinel))
        {
            AxumeraApp.Run(
                "Axumera.ControlPanel",
                "Axumera Control Panel",
                "Administration & Exam Management",
                telemetry => new ControlPanelMainForm(telemetry));
        }
    }
}
