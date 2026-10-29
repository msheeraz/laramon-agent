<?php

namespace Laramon\Agent\Support;

/**
 * Local batching so the app never waits on Laramon.
 *
 * Every execution appends one line to a shared spool file (a single small
 * write). At most once per interval per server, one process claims the file
 * with an atomic rename and ships its contents to Laramon as one batch, so
 * the app makes a handful of HTTPS calls per minute instead of one per request.
 */
final class Spool
{
    private const CURRENT = 'spool.ndjson';

    /** Stop appending past this size (Laramon unreachable for a long time). */
    private const MAX_BYTES = 50 * 1024 * 1024;

    public static function dir(): string
    {
        return Stamp::dir();
    }

    public static function append(string $payload): bool
    {
        try {
            $file = self::dir().DIRECTORY_SEPARATOR.self::CURRENT;
            $size = @filesize($file);
            if ($size !== false && $size > self::MAX_BYTES) {
                return false;
            }
            if (! is_dir(self::dir())) {
                @mkdir(self::dir(), 0777, true);
                @chmod(self::dir(), 0777);
            }

            return @file_put_contents($file, $payload."\n", FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Claim the spooled payloads (atomic rename), including batches left behind
     * by a process that died while shipping. Returns [claimed files, payloads].
     *
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    public static function claim(): array
    {
        $dir = self::dir();
        $files = [];

        $current = $dir.DIRECTORY_SEPARATOR.self::CURRENT;
        if (@filesize($current)) {
            $claimed = $dir.DIRECTORY_SEPARATOR.'batch-'.getmypid().'-'.str_replace('.', '', (string) microtime(true)).'.ndjson';
            if (@rename($current, $claimed)) {
                @touch($claimed);
                $files[] = $claimed;
            }
        }

        // Orphans of a crashed shipper or a failed post (older than a minute);
        // anything older than a day is given up on.
        foreach (glob($dir.DIRECTORY_SEPARATOR.'batch-*') ?: [] as $orphan) {
            $age = time() - (int) @filemtime($orphan);
            if ($age > 86400) {
                @unlink($orphan);

                continue;
            }
            if (! in_array($orphan, $files, true) && $age > 60) {
                $taken = $dir.DIRECTORY_SEPARATOR.'batch-'.getmypid().'-'.md5($orphan).'.ndjson';
                if (@rename($orphan, $taken)) {
                    @touch($taken);
                    $files[] = $taken;
                }
            }
        }

        $payloads = [];
        foreach ($files as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && is_array($decoded['records'] ?? null)) {
                    $payloads[] = $decoded;
                }
            }
            fclose($handle);
        }

        return [$files, $payloads];
    }

    /** @param  list<string>  $files */
    public static function release(array $files): void
    {
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /** Put claimed payloads back for the next shipper (Laramon unreachable). */
    public static function restore(array $files): void
    {
        foreach ($files as $file) {
            $target = self::dir().DIRECTORY_SEPARATOR.'batch-retry-'.md5($file).'.ndjson';
            if (@rename($file, $target)) {
                // Make it eligible on the next claim, not after the orphan grace period.
                @touch($target, time() - 61);
            }
        }
    }
}
