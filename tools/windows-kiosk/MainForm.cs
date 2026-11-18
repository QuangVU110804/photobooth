using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace PhotoboothKiosk;

internal sealed class MainForm : Form
{
    private readonly KioskOptions options;
    private readonly OperatorPassword password;
    private readonly string browserFolder;
    private readonly OperatorSession session;
    private readonly WebView2 web = new() { Dock = DockStyle.Fill, Visible = false };
    private readonly FlowLayoutPanel retryPanel = new()
    {
        Name = "RetryPanel", Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown,
        Padding = new Padding(28), WrapContents = false
    };
    private readonly Label retryMessage = new()
    {
        Text = "Đang mở Photobooth…", AutoSize = true, MaximumSize = new Size(580, 0)
    };
    private readonly Button retry = new() { Name = "Retry", Text = "Thử lại", AutoSize = true, Enabled = false };
    private readonly System.Windows.Forms.Timer timer = new() { Interval = 350 };
    private Form? operatorPanel;
    private bool prompting;
    private bool exitApproved;
    private bool connecting;
    private bool webReady;
    private bool disposing;

    internal MainForm(KioskOptions options, OperatorPassword password, string browserFolder, OperatorSession session)
    {
        this.options = options;
        this.password = password;
        this.browserFolder = browserFolder;
        this.session = session;

        Text = "HMU Photobooth";
        AutoScaleMode = AutoScaleMode.Dpi;
        StartPosition = FormStartPosition.Manual;
        retryPanel.Controls.AddRange([retryMessage, retry]);
        Controls.AddRange([web, retryPanel]);
        SetFullscreen(true);
        retry.Click += async (_, _) => await ConnectAsync();
        timer.Tick += (_, _) =>
        {
            if (operatorPanel is not null && !session.IsUnlocked) LockNow();
        };
        Shown += async (_, _) => await ConnectAsync();
    }

    private async Task ConnectAsync()
    {
        if (connecting) return;
        connecting = true;
        retry.Enabled = false;
        retryMessage.Text = "Đang mở Photobooth…";
        try
        {
            if (!webReady)
            {
                var environment = await CoreWebView2Environment.CreateAsync(userDataFolder: browserFolder);
                await web.EnsureCoreWebView2Async(environment);
                if (disposing) return;
                ConfigureBrowser();
                webReady = true;
            }

            web.CoreWebView2.Navigate(options.Home.AbsoluteUri);
        }
        catch (WebView2RuntimeNotFoundException)
        {
            ShowRetry("Máy này cần Microsoft Edge WebView2 Evergreen Runtime. Cài Runtime chính thức rồi thử lại.");
        }
        catch (Exception error) when (error is InvalidOperationException or System.Runtime.InteropServices.COMException or IOException or NotImplementedException)
        {
            if (!disposing) ShowRetry("Không mở được WebView2. Kiểm tra quyền lưu hồ sơ và bản cài WebView2 rồi thử lại.");
        }
        finally
        {
            connecting = false;
        }
    }

    private void ConfigureBrowser()
    {
        var core = web.CoreWebView2;
        core.Settings.IsZoomControlEnabled = false;
        core.Settings.IsPinchZoomEnabled = false;
        core.Settings.AreDefaultContextMenusEnabled = false;
        core.Settings.AreDevToolsEnabled = false;
        core.Settings.IsWebMessageEnabled = false;
        web.ZoomFactor = 1.0;
        core.NavigationStarting += (_, args) =>
        {
            if (!options.Allows(args.Uri)) args.Cancel = true;
        };
        core.NewWindowRequested += (_, args) =>
        {
            args.Handled = true;
            if (args.IsUserInitiated && options.Allows(args.Uri)) core.Navigate(args.Uri);
        };
        core.NavigationCompleted += (_, args) =>
        {
            if (args.IsSuccess)
            {
                retryPanel.Visible = false;
                web.Visible = true;
                if (!session.IsUnlocked) web.ZoomFactor = 1.0;
                web.Focus();
            }
            else if (args.WebErrorStatus != CoreWebView2WebErrorStatus.OperationCanceled)
            {
                ShowRetry($"Không mở được {options.Home}. Kiểm tra Apache/XAMPP đang chạy rồi bấm Thử lại.");
            }
        };
        core.ProcessFailed += (_, args) =>
        {
            if (args.ProcessFailedKind == CoreWebView2ProcessFailedKind.BrowserProcessExited)
            {
                webReady = false;
                ShowRetry("WebView2 đã dừng. Người vận hành có thể mở khóa, thoát và mở lại Photobooth.");
                retry.Enabled = false;
            }
            else if (args.ProcessFailedKind == CoreWebView2ProcessFailedKind.RenderProcessExited)
            {
                ShowRetry("Trang Photobooth đã dừng. Bấm Thử lại để tải lại trang.");
            }
        };
        web.KeyDown += (_, args) =>
        {
            if (!IsKioskCommand(args.KeyData)) return;
            args.Handled = true;
            if (OpensOperator(args.KeyData)) BeginInvoke(OpenOperator);
        };
    }

    private void ShowRetry(string message)
    {
        retryMessage.Text = message;
        retryPanel.Visible = true;
        retry.Enabled = true;
        web.Visible = false;
        retry.Focus();
    }

    private static bool OpensOperator(Keys key) =>
        key is (Keys.Control | Keys.Shift | Keys.F12) or (Keys.Alt | Keys.F4) or (Keys.Control | Keys.W);

    private static bool IsKioskCommand(Keys key) =>
        OpensOperator(key) || key is Keys.F11 or (Keys.Control | Keys.T) or (Keys.Control | Keys.Shift | Keys.T)
            or (Keys.Control | Keys.N) or (Keys.Control | Keys.Shift | Keys.N) or (Keys.Control | Keys.Tab)
            or (Keys.Control | Keys.Shift | Keys.Tab) or (Keys.Control | Keys.L) or (Keys.Control | Keys.O);

    protected override bool ProcessCmdKey(ref Message msg, Keys keyData)
    {
        if (!IsKioskCommand(keyData)) return base.ProcessCmdKey(ref msg, keyData);
        if (OpensOperator(keyData)) BeginInvoke(OpenOperator);
        return true;
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        base.OnFormClosing(e);
        if (disposing || e.CloseReason == CloseReason.WindowsShutDown || e.CloseReason == CloseReason.ApplicationExitCall
            || (exitApproved && session.CanClose(systemClosing: false))) return;
        e.Cancel = true;
        BeginInvoke(OpenOperator);
    }

    private void OpenOperator()
    {
        if (prompting || disposing) return;
        if (!session.IsUnlocked)
        {
            LockNow();
            prompting = true;
            try
            {
                using var prompt = OperatorDialogs.PasswordPrompt(password, session);
                if (prompt.ShowDialog(this) != DialogResult.OK) return;
            }
            finally { prompting = false; }
        }

        if (operatorPanel is not null)
        {
            operatorPanel.Activate();
            return;
        }

        var panel = new Form
        {
            Name = "OperatorPanel", Text = "Điều khiển Photobooth", ShowInTaskbar = false,
            StartPosition = FormStartPosition.CenterParent, AutoScaleMode = AutoScaleMode.Dpi,
            AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink, FormBorderStyle = FormBorderStyle.FixedToolWindow
        };
        var layout = new FlowLayoutPanel
        {
            AutoSize = true, Dock = DockStyle.Fill, Padding = new Padding(18),
            WrapContents = false, FlowDirection = FlowDirection.TopDown
        };
        var zoom = new ComboBox
        {
            Name = "Zoom", AccessibleName = "Thu phóng trang", DropDownStyle = ComboBoxStyle.DropDownList,
            Width = 145, Enabled = webReady
        };
        zoom.Items.AddRange(["80%", "90%", "100%", "110%", "125%", "150%"]);
        zoom.SelectedItem = "100%";
        double[] factors = [0.8, 0.9, 1.0, 1.1, 1.25, 1.5];
        zoom.SelectedIndexChanged += (_, _) =>
        {
            if (session.IsUnlocked && webReady && zoom.SelectedIndex >= 0)
                web.ZoomFactor = factors[zoom.SelectedIndex];
        };
        var mode = new Button { Name = "WindowMode", Text = "Chế độ cửa sổ", AutoSize = true };
        mode.Click += (_, _) =>
        {
            if (!session.IsUnlocked) return;
            var full = FormBorderStyle != FormBorderStyle.None;
            SetFullscreen(full);
            mode.Text = full ? "Chế độ cửa sổ" : "Toàn màn hình";
        };
        var lockButton = new Button { Text = "Khóa lại", AutoSize = true };
        lockButton.Click += (_, _) => LockNow();
        var exit = new Button { Name = "Exit", Text = "Thoát Photobooth", AutoSize = true };
        exit.Click += (_, _) =>
        {
            if (!session.IsUnlocked) return;
            using var confirmation = OperatorDialogs.ExitConfirmation();
            if (confirmation.ShowDialog(panel) != DialogResult.Yes || !session.IsUnlocked) return;
            exitApproved = true;
            Close();
        };
        layout.Controls.AddRange([new Label { Text = "Thu phóng trang", AutoSize = true }, zoom, mode, lockButton, exit]);
        panel.Controls.Add(layout);
        panel.FormClosed += (_, _) =>
        {
            if (operatorPanel != panel) return;
            operatorPanel = null;
            if (!exitApproved && !disposing) LockNow();
        };
        operatorPanel = panel;
        panel.Show(this);
        timer.Start();
    }

    private void LockNow()
    {
        session.Lock();
        timer.Stop();
        var panel = operatorPanel;
        operatorPanel = null;
        panel?.Close();
        if (webReady) web.ZoomFactor = 1.0;
        SetFullscreen(true);
    }

    private void SetFullscreen(bool full)
    {
        var screen = Screen.FromControl(this);
        WindowState = FormWindowState.Normal;
        FormBorderStyle = full ? FormBorderStyle.None : FormBorderStyle.Sizable;
        if (full)
        {
            Bounds = screen.Bounds;
        }
        else
        {
            var area = screen.WorkingArea;
            Bounds = new Rectangle(area.X + area.Width / 10, area.Y + area.Height / 10, area.Width * 4 / 5, area.Height * 4 / 5);
        }
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            this.disposing = true;
            timer.Dispose();
        }
        base.Dispose(disposing);
    }
}
