using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text.RegularExpressions;
using System.Windows.Forms;

internal static class AxumeraAdmin
{
    static readonly string Root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\');
    [STAThread] static void Main()
    {
        string url = "http://127.0.0.1:" + Port() + "/adminlogin.php";
        if (!Available(url) && MessageBox.Show("The Axumera server is not running. Start it now?", "Axumera Admin", MessageBoxButtons.YesNo, MessageBoxIcon.Question) == DialogResult.Yes)
        {
            Process.Start(new ProcessStartInfo(Path.Combine(Root, "AxumeraServer.exe"), "start") { UseShellExecute = true });
            for (int i = 0; i < 30 && !Available(url); i++) System.Threading.Thread.Sleep(1000);
        }
        if (!Available(url)) { MessageBox.Show("The Axumera server did not become ready. Open the server controller and check logs.", "Axumera Admin", MessageBoxButtons.OK, MessageBoxIcon.Error); return; }
        Process.Start(new ProcessStartInfo(url) { UseShellExecute = true });
    }
    static int Port() { try { Match m = Regex.Match(File.ReadAllText(Path.Combine(Root, "config", "ports.json")), "\\\"apache\\\"\\s*:\\s*(\\d+)"); if (m.Success) return Int32.Parse(m.Groups[1].Value); } catch {} return 8088; }
    static bool Available(string url) { try { var r=(HttpWebRequest)WebRequest.Create(url.Replace("adminlogin.php", "health.php")); r.Timeout=1500; using(var x=(HttpWebResponse)r.GetResponse()) return x.StatusCode==HttpStatusCode.OK; } catch { return false; } }
}
