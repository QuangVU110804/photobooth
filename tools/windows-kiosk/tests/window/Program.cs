using System.Text.Json;
using Microsoft.Web.WebView2.WinForms;
using PhotoboothKiosk;

namespace KioskWindowChecks;

internal static class Program
{
    [STAThread]
    private static int Main(string[] args)
    {
        KioskLogicChecks.Run();
        Application.SetHighDpiMode(HighDpiMode.PerMonitorV2);
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        var options = KioskOptions.Parse(["--url", args[0]]);
        var folder = Path.Combine(Path.GetTempPath(), "photobooth-kiosk-window-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(folder);
        var passwordPath = Path.Combine(folder, "operator.json");
        const string correct = "test-only-passphrase";
        OperatorPassword.Save(passwordPath, correct, false);
        if (!OperatorPassword.TryLoad(passwordPath, out var password)) throw new Exception("Missing test credentials");

        var time = new ManualTime();
        using var form = new MainForm(options, password!, Path.Combine(folder, "browser"), new OperatorSession(time));
        var checks = 0;
        var verified = false;
        Exception? failure = null;
        void Check(bool result, string label)
        {
            if (!result) throw new InvalidOperationException(label);
            checks++;
            Console.WriteLine(label);
        }

        form.Shown += async (_, _) =>
        {
            try
            {
                var browser = form.Controls.OfType<WebView2>().Single();
                await WaitUntil(() => form.Controls.Find("RetryPanel", true).Single().Visible
                    && form.Controls.Find("Retry", true).Single().Enabled, "offline screen");
                Check(form.FormBorderStyle == FormBorderStyle.None, "Offline kiosk stays fullscreen");
                await File.WriteAllTextAsync(args[1], "start");
                await WaitUntil(() => File.Exists(args[2]), "fixture HTTP server started");
                ((Button)form.Controls.Find("Retry", true).Single()).PerformClick();
                await WaitUntil(() => browser.CoreWebView2 is not null && browser.Visible, "WebView2 connects after Retry");
                await WaitUntil(async () => await browser.ExecuteScriptAsync("document.querySelector('#kioskFrameSelector .hmu-frame-card') !== null") == "true", "single frame catalog");
                Check(browser.ZoomFactor == 1.0 && !browser.CoreWebView2.Settings.IsZoomControlEnabled
                    && !browser.CoreWebView2.Settings.IsPinchZoomEnabled, "WebView2 starts at locked 100% zoom");
                Check(form.Bounds == Screen.FromControl(form).Bounds, "Fullscreen follows the active monitor");
                Check(await browser.ExecuteScriptAsync("window.isSecureContext") == "true", "Loopback remains a secure browser context");
                Check(await browser.ExecuteScriptAsync("typeof navigator.mediaDevices?.getUserMedia === 'function'") == "true", "Camera API remains exposed");
                await browser.ExecuteScriptAsync("void navigator.mediaDevices.getUserMedia({video:true}).then(s=>{window.testCamera=s.getVideoTracks().length===1;s.getTracks().forEach(t=>t.stop())},()=>window.testCamera=false)");
                await WaitUntil(async () => await browser.ExecuteScriptAsync("typeof window.testCamera === 'boolean'") == "true", "fixture camera");
                Check(await browser.ExecuteScriptAsync("window.testCamera") == "true", "WebView2 captures a simulated camera stream");
                Check(await browser.ExecuteScriptAsync("!!document.createElement('canvas').getContext('webgl2')") == "true", "WebGL2 supported by the kiosk runtime");
                await browser.ExecuteScriptAsync("document.querySelector('[data-capture-mode=multi]').click()");
                await WaitUntil(async () => await browser.ExecuteScriptAsync("document.querySelector('#hmuMultiFrameSelector .hmu-frame-card') !== null") == "true", "multi frame catalog");
                await browser.ExecuteScriptAsync("void fetch('api/multishot.php?action=frames').then(r=>r.json()).then(r=>window.testKioskLayouts=r.frames.map(f=>f.layout).sort().join(','))");
                await WaitUntil(async () => await browser.ExecuteScriptAsync("typeof window.testKioskLayouts === 'string'") == "true", "five layouts returned");
                var frames = await browser.ExecuteScriptAsync("window.testKioskLayouts");
                Check(JsonSerializer.Deserialize<string>(frames) == "1x2,1x3,1x4,2x2,2x3", "All five Multi layouts load in WebView2");
                await browser.ExecuteScriptAsync("void fetch(document.querySelector('#hmuMultiFrameSelector img').src).then(r=>window.testKioskPreview=r.ok)");
                await WaitUntil(async () => await browser.ExecuteScriptAsync("window.testKioskPreview === true") == "true", "preview HTTP success");
                Check(true, "Multi preview API works in the kiosk");
                browser.CoreWebView2.Navigate(new Uri(options.Home, "../outside.html").AbsoluteUri);
                await Task.Delay(400);
                Check(browser.Source.AbsoluteUri == options.Home.AbsoluteUri, "Navigation outside the configured application path is cancelled");

                form.Activate();
                browser.Focus();
                await WaitUntil(() => Form.ActiveForm == form && browser.ContainsFocus, "foreground WebView2 focus");
                SendKeys.Send("{F11}");
                await Task.Delay(180);
                Check(form.FormBorderStyle == FormBorderStyle.None && browser.ZoomFactor == 1.0, "F11 leaves locked kiosk fullscreen");
                SendKeys.Send("^+{F12}");
                var shortcutPrompt = await FindWindow("OperatorPasswordDialog");
                ((Button)shortcutPrompt.Controls.Find("Cancel", true).Single()).PerformClick();
                await WaitUntil(() => shortcutPrompt.IsDisposed, "operator shortcut opens a cancellable password prompt");
                Check(form.Visible, "Operator shortcut opens the real password dialog from focused WebView2");

                form.Close();
                var prompt = await FindWindow("OperatorPasswordDialog");
                Check(form.Visible && form.FormBorderStyle == FormBorderStyle.None, "Close is deferred while the password dialog is active");
                ((Button)prompt.Controls.Find("Cancel", true).Single()).PerformClick();
                await WaitUntil(() => prompt.IsDisposed, "cancelled authentication closes the dialog");
                Check(form.Visible && browser.ZoomFactor == 1.0, "Cancelling close leaves the kiosk locked");

                form.Activate();
                browser.Focus();
                await WaitUntil(() => Form.ActiveForm == form && browser.ContainsFocus, "foreground focus before Alt+F4");
                SendKeys.Send("%{F4}");
                prompt = await FindWindow("OperatorPasswordDialog");
                ((TextBox)prompt.Controls.Find("Password", true).Single()).Text = "wrong-password";
                ((Button)prompt.Controls.Find("Submit", true).Single()).PerformClick();
                Check(prompt.Visible && form.Visible && browser.ZoomFactor == 1.0, "Wrong password leaves the window locked");
                ((TextBox)prompt.Controls.Find("Password", true).Single()).Text = correct;
                ((Button)prompt.Controls.Find("Submit", true).Single()).PerformClick();

                var panel = await FindWindow("OperatorPanel");
                var zoom = (ComboBox)panel.Controls.Find("Zoom", true).Single();
                zoom.SelectedItem = "125%";
                await WaitUntil(() => browser.ZoomFactor == 1.25, "operator zoom");
                Check(browser.ZoomFactor == 1.25, "Operator password enables zoom");
                ((Button)panel.Controls.Find("WindowMode", true).Single()).PerformClick();
                Check(form.FormBorderStyle != FormBorderStyle.None, "Operator password enables windowed mode");
                time.Advance(TimeSpan.FromMinutes(2));
                await WaitUntil(() => !panel.Visible, "operator session expiration");
                Check(browser.ZoomFactor == 1.0 && form.FormBorderStyle == FormBorderStyle.None, "Expiration restores locked fullscreen at 100%");
                form.Close();
                prompt = await FindWindow("OperatorPasswordDialog");
                ((TextBox)prompt.Controls.Find("Password", true).Single()).Text = correct;
                ((Button)prompt.Controls.Find("Submit", true).Single()).PerformClick();
                panel = await FindWindow("OperatorPanel");
                var exit = (Button)panel.Controls.Find("Exit", true).Single();
                panel.BeginInvoke(() => exit.PerformClick());
                var confirmation = await FindWindow("ExitConfirmation");
                form.FormClosed += (_, _) =>
                {
                    Check(true, "Authenticated operator can confirm and close the kiosk");
                    verified = true;
                    Console.WriteLine($"{checks} Windows kiosk WebView2 checks passed");
                };
                ((Button)confirmation.Controls.Find("ConfirmExit", true).Single()).PerformClick();
                await WaitUntil(() => verified, "authorized exit");
            }
            catch (Exception error) { failure = error; }
            finally
            {
                foreach (var child in form.OwnedForms) child.Dispose();
                Application.ExitThread();
            }
        };
        Application.Run(form);
        if (failure is null && verified) return 0;
        Console.Error.WriteLine(failure?.ToString() ?? "The window closed before checks completed.");
        return 1;
    }

    private static Task WaitUntil(Func<bool> condition, string description) =>
        WaitUntil(() => Task.FromResult(condition()), description);

    private static async Task WaitUntil(Func<Task<bool>> condition, string description)
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(35));
        while (!await condition())
        {
            try { await Task.Delay(80, timeout.Token); }
            catch (OperationCanceledException) { throw new TimeoutException(description); }
        }
    }

    private static async Task<Form> FindWindow(string name)
    {
        Form? result = null;
        await WaitUntil(() => (result = Application.OpenForms.Cast<Form>().FirstOrDefault(candidate => candidate.Name == name && candidate.Visible)) is not null, name);
        return result!;
    }

    private sealed class ManualTime : TimeProvider
    {
        private long ticks;
        public override long TimestampFrequency => TimeSpan.TicksPerSecond;
        public override long GetTimestamp() => ticks;
        internal void Advance(TimeSpan duration) => ticks += duration.Ticks;
    }
}
