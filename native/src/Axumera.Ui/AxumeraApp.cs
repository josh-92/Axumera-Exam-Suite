namespace Axumera.Ui;

/// <summary>
/// Consistent startup sequence for every native application:
/// splash (logo + gold indicator) → main form.
/// </summary>
public static class AxumeraApp
{
    public static void Run(string applicationKey, string applicationName, string splashSubtitle, Func<StartupTelemetry, Form> createMainForm)
    {
        // Explicit WinForms configuration (library-safe; no generated ApplicationConfiguration dependency).
        Application.SetHighDpiMode(HighDpiMode.SystemAware);
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        using var telemetry = StartupTelemetry.Create(applicationKey);

        var splash = new AxumeraSplashForm(applicationName, splashSubtitle);
        splash.Show();
        Application.DoEvents();
        telemetry.Mark("splash-shown");

        // Brief branded pause so the splash is perceivable.
        Thread.Sleep(1100);

        var main = createMainForm(telemetry);
        main.Shown += (_, _) => telemetry.Mark("main-form-shown");
        main.FormClosed += (_, _) => telemetry.Mark("form-closed");

        splash.Close();
        Application.Run(main);
        telemetry.Mark("process-exited");
    }
}
