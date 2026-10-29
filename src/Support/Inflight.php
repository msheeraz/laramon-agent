<?php

namespace Laramon\Agent\Support;

/**
 * Marks which execution a PHP process is currently running, so the host
 * daemon can attribute memory/CPU (and OOM kills) to a route, job, command
 * or scheduled task even when the process dies before reporting.
 */
final class Inflight
{
    public static function dir(): string
    {
        // Override when PHP-FPM and the daemon do not share /tmp (systemd PrivateTmp).
        $custom = $_SERVER['LARAMON_INFLIGHT_PATH'] ?? $_ENV['LARAMON_INFLIGHT_PATH'] ?? getenv('LARAMON_INFLIGHT_PATH');
        if (is_string($custom) && $custom !== '') {
            return rtrim($custom, DIRECTORY_SEPARATOR);
        }

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'laramon-inflight';
    }

    public static function write(string $type, string $name, string $trace, float $startedAt): void
    {
        try {
            $dir = self::dir();
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
                @chmod($dir, 0777);
            }

            @file_put_contents($dir.DIRECTORY_SEPARATOR.getmypid().'.json', json_encode([
                'type' => $type,
                'name' => $name,
                'trace' => $trace,
                'started_at' => round($startedAt, 6),
                'memory' => memory_get_usage(true),
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
        } catch (\Throwable) {
            // Never interfere with the host application.
        }
    }

    public static function clear(): void
    {
        try {
            $file = self::dir().DIRECTORY_SEPARATOR.getmypid().'.json';
            if (is_file($file)) {
                @unlink($file);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * All in-flight entries keyed by pid. Files of dead processes are removed.
     *
     * @param  callable(int): bool  $alive
     * @return array<int, array<string, mixed>>
     */
    public static function all(callable $alive): array
    {
        $out = [];
        $dir = self::dir();
        $files = is_dir($dir) ? (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: []) : [];

        foreach ($files as $file) {
            $pid = (int) basename($file, '.json');
            if ($pid <= 0) {
                continue;
            }

            if (! $alive($pid)) {
                @unlink($file);

                continue;
            }

            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data)) {
                $out[$pid] = $data;
            }
        }

        return $out;
    }
}
