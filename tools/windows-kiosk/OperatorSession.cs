namespace PhotoboothKiosk;

internal sealed class OperatorSession(TimeProvider? timeProvider = null)
{
    private readonly TimeProvider time = timeProvider ?? TimeProvider.System;
    private static readonly TimeSpan UnlockDuration = TimeSpan.FromMinutes(2);
    private static readonly TimeSpan RetryDuration = TimeSpan.FromSeconds(30);
    private long? unlockedAt;
    private long? throttledAt;
    private int failures;

    internal bool IsUnlocked => unlockedAt is long stamp && time.GetElapsedTime(stamp) < UnlockDuration;

    internal TimeSpan RetryAfter
    {
        get
        {
            if (throttledAt is not long stamp) return TimeSpan.Zero;
            var remaining = RetryDuration - time.GetElapsedTime(stamp);
            return remaining > TimeSpan.Zero ? remaining : TimeSpan.Zero;
        }
    }

    internal bool TryUnlock(Func<bool> verify)
    {
        if (RetryAfter > TimeSpan.Zero) return false;
        if (!verify())
        {
            if (++failures == 5)
            {
                throttledAt = time.GetTimestamp();
                failures = 0;
            }
            return false;
        }

        failures = 0;
        throttledAt = null;
        unlockedAt = time.GetTimestamp();
        return true;
    }

    internal bool CanClose(bool systemClosing) => systemClosing || IsUnlocked;

    internal void Lock() => unlockedAt = null;
}
