<?php

namespace Laramon\Agent\Support;

final class Clock
{
    public static function now(): float
    {
        return microtime(true);
    }

    /** Seconds (float) to integer microseconds, never negative. */
    public static function us(float $seconds): int
    {
        return max(0, (int) round($seconds * 1_000_000));
    }

    /** User + system CPU time consumed by this process so far, in µs. */
    public static function cpu(): int
    {
        $r = function_exists('getrusage') ? @getrusage() : false;
        if (! is_array($r)) {
            return 0;
        }

        return (int) (($r['ru_utime.tv_sec'] ?? 0) * 1_000_000 + ($r['ru_utime.tv_usec'] ?? 0)
            + ($r['ru_stime.tv_sec'] ?? 0) * 1_000_000 + ($r['ru_stime.tv_usec'] ?? 0));
    }
}
