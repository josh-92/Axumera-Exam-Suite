using Axumera.Ui;

namespace Axumera.Student;

internal static class Program
{
    [STAThread]
    private static void Main()
    {
        AxumeraApp.Run(
            "Axumera.Student",
            "Axumera Student",
            "Starting Axumera Exam…",
            telemetry => new StudentMainForm(telemetry));
    }
}
