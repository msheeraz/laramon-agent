<?php

namespace Laramon\Agent\Support;

/**
 * Identifies the running release: LARAMON_DEPLOY, then platform variables
 * (Laravel Cloud, Forge, Vapor), then the git commit of the checkout.
 * Detected once per process.
 */
final class Deploy
{
    private static ?string $detected = null;

    private static bool $resolved = false;

    public static function detect(string $basePath, ?string $configured = null): ?string
    {
        if (self::$resolved) {
            return self::$detected;
        }

        self::$resolved = true;

        return self::$detected = self::fromConfig($configured)
            ?? self::fromEnv()
            ?? self::fromGit($basePath);
    }

    private static function fromConfig(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? substr($value, 0, 64) : null;
    }

    private static function fromEnv(): ?string
    {
        foreach ([
            'LARAVEL_CLOUD_DEPLOYMENT_ID', 'LARAVEL_CLOUD_COMMIT_SHA',
            'FORGE_DEPLOY_COMMIT', 'FORGE_DEPLOYMENT_ID', 'FORGE_DEPLOY_ID',
            'VAPOR_DEPLOYMENT_ID', 'VAPOR_COMMIT_HASH',
        ] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return substr(trim($value), 0, 64);
            }
        }

        return null;
    }

    private static function fromGit(string $basePath): ?string
    {
        try {
            $head = rtrim($basePath, '/').'/.git/HEAD';
            if (! is_readable($head)) {
                return null;
            }

            $ref = trim((string) @file_get_contents($head));
            if (preg_match('/^[0-9a-f]{40}$/i', $ref)) {
                return substr($ref, 0, 12);
            }

            if (str_starts_with($ref, 'ref: ')) {
                $refPath = rtrim($basePath, '/').'/.git/'.substr($ref, 5);
                if (is_readable($refPath)) {
                    $sha = trim((string) @file_get_contents($refPath));
                    if (preg_match('/^[0-9a-f]{40}$/i', $sha)) {
                        return substr($sha, 0, 12);
                    }
                }

                // Packed refs (after git gc).
                $packed = rtrim($basePath, '/').'/.git/packed-refs';
                if (is_readable($packed)) {
                    foreach (file($packed, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                        if (str_ends_with($line, ' '.substr($ref, 5))) {
                            return substr($line, 0, 12);
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
