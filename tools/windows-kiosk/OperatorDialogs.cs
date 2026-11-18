namespace PhotoboothKiosk;

internal static class OperatorDialogs
{
    internal static Form ExitConfirmation()
    {
        var dialog = new Form
        {
            Name = "ExitConfirmation", Text = "Thoát Photobooth", StartPosition = FormStartPosition.CenterParent,
            ShowInTaskbar = false, AutoScaleMode = AutoScaleMode.Dpi, FormBorderStyle = FormBorderStyle.FixedDialog,
            MaximizeBox = false, MinimizeBox = false, AutoSize = true, AutoSizeMode = AutoSizeMode.GrowAndShrink
        };
        var layout = new FlowLayoutPanel { AutoSize = true, Dock = DockStyle.Fill, WrapContents = false, Padding = new Padding(16) };
        var yes = new Button { Text = "Thoát", Name = "ConfirmExit", DialogResult = DialogResult.Yes, AutoSize = true };
        var no = new Button { Text = "Quay lại", DialogResult = DialogResult.Cancel, AutoSize = true };
        layout.Controls.AddRange([yes, no]);
        dialog.Controls.Add(layout);
        dialog.AcceptButton = no;
        dialog.CancelButton = no;
        dialog.Shown += (_, _) => no.Focus();
        return dialog;
    }

    internal static SecretDialog PasswordPrompt(OperatorPassword password, OperatorSession session)
    {
        return new SecretDialog("Xác thực người vận hành", "Nhập mật khẩu để đổi zoom hoặc thoát Photobooth.", repeat: false,
            (input, _) =>
            {
                if (session.TryUnlock(() => password.Verify(input))) return null;
                if (session.RetryAfter > TimeSpan.Zero)
                    return $"Vui lòng thử lại sau {(int)Math.Ceiling(session.RetryAfter.TotalSeconds)} giây.";
                return "Mật khẩu chưa đúng.";
            });
    }

    internal sealed class SecretDialog : Form
    {
        internal SecretDialog(string title, string instructions, bool repeat, Func<string, string, string?> submit)
        {
            Name = "OperatorPasswordDialog";
            Text = title;
            StartPosition = FormStartPosition.CenterParent;
            ShowInTaskbar = false;
            AutoSize = true;
            AutoSizeMode = AutoSizeMode.GrowAndShrink;
            AutoScaleMode = AutoScaleMode.Dpi;
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;
            MinimizeBox = false;

            var layout = new FlowLayoutPanel
            {
                Dock = DockStyle.Fill, AutoSize = true, Padding = new Padding(18),
                FlowDirection = FlowDirection.TopDown, WrapContents = false
            };
            var note = new Label { Text = instructions, AutoSize = true, MaximumSize = new Size(380, 0) };
            var password = SecretEntry("Password", "Mật khẩu");
            var confirmation = SecretEntry("Confirmation", "Nhập lại mật khẩu");
            var status = new Label { AutoSize = true, ForeColor = SystemColors.HotTrack, MaximumSize = new Size(380, 0) };
            var buttons = new FlowLayoutPanel { AutoSize = true, WrapContents = false };
            var ok = new Button { AutoSize = true, Text = "Xác nhận", Name = "Submit" };
            var cancel = new Button { AutoSize = true, Text = "Hủy", DialogResult = DialogResult.Cancel, Name = "Cancel" };
            ok.Click += (_, _) =>
            {
                var error = submit(password.Text, confirmation.Text);
                password.Clear();
                confirmation.Clear();
                if (error is null) DialogResult = DialogResult.OK;
                else { status.Text = error; password.Focus(); }
            };
            buttons.Controls.AddRange([ok, cancel]);
            layout.Controls.AddRange([note, password]);
            if (repeat) layout.Controls.Add(confirmation);
            layout.Controls.AddRange([status, buttons]);
            Controls.Add(layout);
            AcceptButton = ok;
            CancelButton = cancel;
            Shown += (_, _) => password.Focus();
        }

        private static TextBox SecretEntry(string name, string label) => new()
        {
            Name = name, AccessibleName = label, PlaceholderText = label,
            UseSystemPasswordChar = true, MaxLength = OperatorPassword.MaxLength, Width = 320
        };
    }
}
