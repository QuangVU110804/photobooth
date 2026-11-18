using System.Text.Json;

namespace PhotoboothKiosk;

internal static class KioskLogicChecks
{
    internal static void Run()
    {
        var checks = 0;
        void Check(bool result, string label)
        {
            if (!result) throw new InvalidOperationException(label);
            checks++;
        }
        var kiosk = KioskOptions.Parse([]);
        Check(kiosk.Home.AbsoluteUri == "http://localhost:8080/photobooth/" && !kiosk.Setup, "Default URL is the XAMPP example");
        var custom = KioskOptions.Parse(["--url", "http://127.0.0.1:9099/event/booth", "--setup"]);
        Check(custom.Setup && custom.Home.AbsoluteUri == "http://127.0.0.1:9099/event/booth/", "Operators can choose the loopback port and folder");
        Check(custom.Allows("http://127.0.0.1:9099/event/booth/photo.php?image=test.jpg"), "Local QR receipt navigation stays in the kiosk");
        Check(custom.Allows("http://127.0.0.1:9099/event/booth") && custom.Allows("http://127.0.0.1:9099/event/booth/#gallery"), "App routes and fragments remain available");
        foreach (var url in new[] {
            "http://127.0.0.1:9099/event/boothx/", "http://127.0.0.1:9099/event/booth/../secret",
            "http://127.0.0.1:9099/event/booth/images%2f..%2f..%2fsecret", "http://127.0.0.1:9099/event/booth/%252e%252e/secret",
            "http://localhost:9099/event/booth/", "http://127.0.0.1:8080/event/booth/",
            "https://127.0.0.1:9099/event/booth/", "http://anyone@127.0.0.1:9099/event/booth/",
            "https://example.com/", "file:///C:/Windows/", "about:blank"
        }) Check(!custom.Allows(url), $"Rejects navigation to {url}");
        foreach (var url in new[] { "https://example.org/", "http://127.0.0.2/", "http://localhost/?page=1", "http://127.0.0.1/#hash", "http://user@localhost/", "file:///C:/" })
        {
            try { KioskOptions.Parse(["--url", url]); throw new Exception($"Invalid startup URL accepted: {url}"); }
            catch (ArgumentException) { checks++; }
        }
        Check(KioskOptions.Parse(["--url", "http://[::1]:9090/"]).Allows("http://[::1]:9090/index.php"), "IPv6 loopback works on another installation");

        var testFolder = Path.Combine(Path.GetTempPath(), "photobooth-kiosk-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(testFolder);
        try
        {
            var passwordPath = Path.Combine(testFolder, "operator.json");
            Check(!OperatorPassword.TryLoad(passwordPath, out _), "Missing credentials fail closed");
            const string correct = "Một mật khẩu đủ dài";
            const string replacement = "Mật khẩu mới cho booth";
            OperatorPassword.Save(passwordPath, correct, replace: false);
            var originalRecord = File.ReadAllText(passwordPath);
            Check(!originalRecord.Contains(correct), "Credential storage never contains the password");
            Check(OperatorPassword.TryLoad(passwordPath, out var saved) && saved!.Verify(correct), "Correct Unicode password opens a persisted credential");
            Check(!saved!.Verify(correct + " ") && !saved.Verify("sai"), "Incorrect passwords never authenticate");
            try { OperatorPassword.Save(passwordPath, replacement, replace: false); throw new Exception("Setup overwrote a credential"); }
            catch (IOException) { checks++; }
            Check(File.ReadAllText(passwordPath) == originalRecord, "Refused setup leaves the current credential intact");
            OperatorPassword.Save(passwordPath, replacement, replace: true);
            Check(OperatorPassword.TryLoad(passwordPath, out var updated) && updated!.Verify(replacement) && !updated.Verify(correct), "Authenticated rotation changes the password");
            var anotherPath = Path.Combine(testFolder, "another.json");
            OperatorPassword.Save(anotherPath, replacement, replace: false);
            Check(File.ReadAllText(passwordPath) != File.ReadAllText(anotherPath), "Each record uses a fresh salt");
            using var valid = JsonDocument.Parse(originalRecord);
            var version = valid.RootElement.GetProperty("Version").GetInt32();
            File.WriteAllText(passwordPath, originalRecord.Replace("\"Version\":" + version, "\"Version\":999"));
            Check(!OperatorPassword.TryLoad(passwordPath, out _), "Unknown credential version fails closed");
            File.WriteAllText(passwordPath, "{bad record}");
            Check(!OperatorPassword.TryLoad(passwordPath, out _), "Corrupt credentials fail closed");

            var time = new ManualTime();
            var session = new OperatorSession(time);
            Check(!session.IsUnlocked && !session.CanClose(systemClosing: false), "Startup is locked");
            Check(session.CanClose(systemClosing: true), "Windows shutdown can complete");
            for (var attempt = 0; attempt < 5; attempt++)
                Check(!session.TryUnlock(() => false), "An incorrect password stays locked");
            Check(session.RetryAfter == TimeSpan.FromSeconds(30), "Five wrong passwords trigger 30-second throttle");
            Check(!session.TryUnlock(() => throw new Exception("Throttle failed")), "Cooldown avoids password checks");
            time.Advance(TimeSpan.FromSeconds(30));
            Check(session.TryUnlock(() => true) && session.CanClose(systemClosing: false), "A valid password opens operator mode");
            time.Advance(TimeSpan.FromSeconds(119));
            Check(session.IsUnlocked, "Operator window remains open for the configured session");
            time.Advance(TimeSpan.FromSeconds(1));
            Check(!session.IsUnlocked && !session.CanClose(systemClosing: false), "Operator mode expires at two minutes");
            Check(session.TryUnlock(() => true), "The user can authenticate again");
            session.Lock();
            Check(!session.IsUnlocked, "Explicit lock immediately revokes access");
        }
        finally
        {
            Directory.Delete(testFolder, recursive: true);
        }
        Console.WriteLine($"{checks} Windows kiosk logic checks passed");
    }

    private sealed class ManualTime : TimeProvider
    {
        private long ticks;
        public override long TimestampFrequency => TimeSpan.TicksPerSecond;
        public override long GetTimestamp() => ticks;
        public void Advance(TimeSpan duration) => ticks += duration.Ticks;
    }
}
