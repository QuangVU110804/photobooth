namespace PhotoboothKiosk;

internal sealed record KioskOptions(Uri Home, bool Setup)
{
    private const string DefaultUrl = "http://localhost:8080/photobooth/";

    internal static KioskOptions Parse(string[] args)
    {
        var url = DefaultUrl;
        var setup = false;
        var customUrl = false;
        for (var i = 0; i < args.Length; i++)
        {
            if (args[i] == "--setup" && !setup)
            {
                setup = true;
            }
            else if (args[i] == "--url" && !customUrl && i + 1 < args.Length)
            {
                url = args[++i];
                customUrl = true;
            }
            else
            {
                throw new ArgumentException("Tham số hợp lệ: --setup hoặc --url <địa chỉ Photobooth>.");
            }
        }

        if (!TryGetUri(url, out var home) || home!.Host is not ("localhost" or "127.0.0.1" or "[::1]")
            || home.Query.Length != 0 || home.Fragment.Length != 0)
        {
            throw new ArgumentException("Dùng URL HTTP hoặc HTTPS của Photobooth trên localhost, 127.0.0.1 hoặc [::1].");
        }

        return new KioskOptions(new Uri(home.AbsoluteUri.TrimEnd('/') + "/"), setup);
    }

    internal bool Allows(string url)
    {
        return TryGetUri(url, out var uri)
            && uri!.Scheme == Home.Scheme && uri.Host == Home.Host && uri.Port == Home.Port
            && (uri.AbsolutePath.StartsWith(Home.AbsolutePath, StringComparison.Ordinal)
                || uri.AbsolutePath == Home.AbsolutePath.TrimEnd('/'));
    }

    private static bool TryGetUri(string url, out Uri? uri)
    {
        return Uri.TryCreate(url, UriKind.Absolute, out uri)
            && uri.Scheme is "http" or "https" && uri.UserInfo.Length == 0
            && !uri.AbsolutePath.Contains("%2f", StringComparison.OrdinalIgnoreCase)
            && !uri.AbsolutePath.Contains("%5c", StringComparison.OrdinalIgnoreCase)
            && !uri.AbsolutePath.Contains("%25", StringComparison.OrdinalIgnoreCase);
    }
}
