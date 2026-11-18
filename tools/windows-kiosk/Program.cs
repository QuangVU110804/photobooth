namespace PhotoboothKiosk;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        ApplicationConfiguration.Initialize();
        try
        {
            var options = KioskOptions.Parse(args);
            var local = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
            if (local.Length == 0) throw new IOException("Windows không cung cấp thư mục dữ liệu người dùng.");
            var directory = Path.Combine(local, "HMU Photobooth", "Kiosk");
            Directory.CreateDirectory(directory);
            using var lease = new FileStream(Path.Combine(directory, "instance.lock"), FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
            var path = Path.Combine(directory, "operator.json");

            if (options.Setup)
            {
                SetupPassword(path);
                return;
            }

            if (!OperatorPassword.TryLoad(path, out var password))
            {
                ShowError("Cần thiết lập mật khẩu bằng PhotoboothKiosk.exe --setup. Nếu tệp xác thực đã hỏng, người vận hành cần khôi phục bản sao trước.");
                return;
            }

            Application.Run(new MainForm(options, password!, Path.Combine(directory, "WebView2"), new OperatorSession()));
        }
        catch (Exception error) when (error is IOException or UnauthorizedAccessException or ArgumentException)
        {
            ShowError("Không mở được Photobooth Kiosk. Kiểm tra tham số, quyền lưu dữ liệu và đóng phiên kiosk khác trước khi thử lại.\n\n" + error.Message);
        }
    }

    private static void SetupPassword(string path)
    {
        var hasExisting = File.Exists(path);
        var session = new OperatorSession();
        if (hasExisting)
        {
            if (!OperatorPassword.TryLoad(path, out var existing))
            {
                ShowError("Không đọc được tệp xác thực. Hãy khôi phục bản sao theo hướng dẫn vận hành.");
                return;
            }
            using var current = OperatorDialogs.PasswordPrompt(existing!, session);
            if (current.ShowDialog() != DialogResult.OK) return;
        }

        using var setup = new OperatorDialogs.SecretDialog(
            "Thiết lập mật khẩu Photobooth", "Đặt mật khẩu vận hành từ 10 đến 256 ký tự.", repeat: true,
            (password, confirmation) =>
            {
                if (hasExisting && !session.IsUnlocked)
                    return "Phiên xác thực đã hết hạn. Hủy hộp thoại và mở lại phần thiết lập.";
                if (!OperatorPassword.IsAcceptable(password)) return "Mật khẩu cần từ 10 đến 256 ký tự.";
                if (password != confirmation) return "Hai lần nhập mật khẩu khác nhau.";
                OperatorPassword.Save(path, password, replace: hasExisting);
                return null;
            });
        if (setup.ShowDialog() == DialogResult.OK)
            MessageBox.Show("Đã lưu mật khẩu vận hành. Bây giờ có thể mở Photobooth Kiosk.", "Photobooth Kiosk", MessageBoxButtons.OK, MessageBoxIcon.Information);
    }

    private static void ShowError(string message) =>
        MessageBox.Show(message, "Photobooth Kiosk", MessageBoxButtons.OK, MessageBoxIcon.Warning);
}
