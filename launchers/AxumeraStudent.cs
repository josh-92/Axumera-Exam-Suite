using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text.RegularExpressions;
using System.Windows.Forms;

internal static class AxumeraStudent
{
    static string ConfigPath { get { string d=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Axumera Student"); Directory.CreateDirectory(d); return Path.Combine(d,"client.json"); } }
    [STAThread] static void Main()
    {
        Application.EnableVisualStyles();
        string server = LoadServer();
        while (true) {
            using (Form form = new Form { Text="Axumera Student", Width=430, Height=185, StartPosition=FormStartPosition.CenterScreen, FormBorderStyle=FormBorderStyle.FixedDialog, MaximizeBox=false }) {
                form.Controls.Add(new Label { Text="School server address", Left=20, Top=22, Width=360 });
                TextBox input = new TextBox { Left=20, Top=48, Width=370, Text=server };
                Button connect = new Button { Text="Connect", Left=290, Top=86, Width=100, DialogResult=DialogResult.OK };
                form.Controls.Add(input); form.Controls.Add(connect); form.AcceptButton=connect;
                if(form.ShowDialog()!=DialogResult.OK) return;
                server=Normalize(input.Text); if(server.Length==0) { MessageBox.Show("Enter the school server IP address or name.","Axumera Student"); continue; }
            }
            string root="http://"+server+":8088/";
            if (!Available(root+"health.php")) { MessageBox.Show("Cannot reach the Axumera school server. Check the address and network connection.","Axumera Student",MessageBoxButtons.OK,MessageBoxIcon.Warning); continue; }
            File.WriteAllText(ConfigPath, "{\"server\":\""+server.Replace("\\","\\\\").Replace("\"","\\\"")+"\"}");
            Process.Start(new ProcessStartInfo(root+"slogin.php") { UseShellExecute=true }); return;
        }
    }
    static string LoadServer() { try { Match m=Regex.Match(File.ReadAllText(ConfigPath), "\\\"server\\\"\\s*:\\s*\\\"([^\\\"]+)\\\""); return m.Success ? m.Groups[1].Value : "axumera"; } catch { return "axumera"; } }
    static string Normalize(string value) { value=value.Trim(); if(value.StartsWith("http://",StringComparison.OrdinalIgnoreCase)) value=value.Substring(7); if(value.StartsWith("https://",StringComparison.OrdinalIgnoreCase)) value=value.Substring(8); int slash=value.IndexOf('/'); return (slash>=0?value.Substring(0,slash):value).Trim(); }
    static bool Available(string url) { try { var r=(HttpWebRequest)WebRequest.Create(url); r.Timeout=2500; using(var x=(HttpWebResponse)r.GetResponse()) return x.StatusCode==HttpStatusCode.OK; } catch { return false; } }
}
