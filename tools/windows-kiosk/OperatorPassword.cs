using System.Security.Cryptography;
using System.Text.Json;

namespace PhotoboothKiosk;

internal sealed class OperatorPassword(byte[] salt, byte[] hash)
{
    private const int Iterations = 600_000;
    private const int SaltLength = 16;
    private const int HashLength = 32;
    internal const int MaxLength = 256;

    private sealed record Credential(int Version, int Iterations, byte[] Salt, byte[] Hash);

    internal static bool IsAcceptable(string value) =>
        value.Length is >= 10 and <= MaxLength && !string.IsNullOrWhiteSpace(value);

    internal static bool TryLoad(string path, out OperatorPassword? password)
    {
        password = null;
        try
        {
            using var file = File.OpenRead(path);
            if (file.Length is 0 or > 4096) return false;
            var saved = JsonSerializer.Deserialize<Credential>(file);
            if (saved is not { Version: 1, Iterations: Iterations, Salt.Length: SaltLength, Hash.Length: HashLength })
                return false;
            password = new OperatorPassword(saved.Salt, saved.Hash);
            return true;
        }
        catch (Exception error) when (error is IOException or UnauthorizedAccessException or JsonException)
        {
            return false;
        }
    }

    internal bool Verify(string value)
    {
        if (!IsAcceptable(value)) return false;
        var candidate = Derive(value, salt);
        try
        {
            return CryptographicOperations.FixedTimeEquals(candidate, hash);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(candidate);
        }
    }

    internal static void Save(string path, string value, bool replace)
    {
        if (!IsAcceptable(value)) throw new ArgumentException("Mật khẩu cần từ 10 đến 256 ký tự.");
        var salt = RandomNumberGenerator.GetBytes(SaltLength);
        var derived = Derive(value, salt);
        var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            using (var file = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None))
                JsonSerializer.Serialize(file, new Credential(1, Iterations, salt, derived));

            File.Move(temporary, path, overwrite: replace);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(derived);
            if (File.Exists(temporary)) File.Delete(temporary);
        }
    }

    private static byte[] Derive(string value, byte[] salt) =>
        Rfc2898DeriveBytes.Pbkdf2(value, salt, Iterations, HashAlgorithmName.SHA256, HashLength);
}
