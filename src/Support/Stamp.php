<?php

namespace Laramon\Agent\Support;

/**
 * Cross-process "at most once per N seconds" switch. PHP-FPM forgets all
 * memory between requests, so per-process throttles do not throttle anything;
 * this uses APCu when enabled and otherwise one small file per key.
 */
final class Stamp
{
    public static function dir(): string
    {
        $custom = $_SERVER['LARAMON_STATE_PATH'] ?? $_ENV['LARAMON_STATE_PATH'] ?? getenv('LARAMON_STATE_PATH');

        return is_string($custom) && $custom !== ''
            ? rtrim($custom, DIRECTORY_SEPARATOR)
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laramon';
    }

    /** True (and marks the key) when the key was not marked within $seconds. */
    public static function due(string $key, int $seconds): bool
    {
        try {
            if (function_exists('apcu_enabled') && apcu_enabled()) {
                return apcu_add('laramon:'.$key, 1, $seconds);
            }

            $file = self::path($key);
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime > time() - $seconds) {
                return false;
            }

            @touch($file);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Seconds since the key was last touched, or null when never. */
    public static function age(string $key): ?int
    {
        $mtime = @filemtime(self::path($key));

        return $mtime === false ? null : max(0, time() - $mtime);
    }

    public static function touch(string $key): void
    {
        @touch(self::path($key));
    }

    public static function forget(string $key): void
    {
        @unlink(self::path($key));
    }

    private static function path(string $key): string
    {
        static $ready = false;
        $dir = self::dir();
        if (! $ready && ! is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 0777);
        }
        $ready = true;

        return $dir.DIRECTORY_SEPARATOR.preg_replace('/[^a-z0-9_.-]/i', '_', $key);
    }
}
