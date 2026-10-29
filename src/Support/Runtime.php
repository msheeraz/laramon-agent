<?php

namespace Laramon\Agent\Support;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;

/**
 * Snapshot of the application's runtime, computed once per process and sent
 * with every payload so Laramon can show what the app runs on.
 */
final class Runtime
{
    private const PACKAGES = [
        'laravel/framework', 'laravel/octane', 'laravel/horizon', 'laravel/pulse', 'laravel/telescope',
        'laravel/sanctum', 'laravel/passport', 'laravel/fortify', 'laravel/reverb', 'laravel/cashier',
        'livewire/livewire', 'inertiajs/inertia-laravel', 'filament/filament', 'laravel/nova', 'laravel/vapor-core',
        'laramon/agent',
    ];

    public static function snapshot(Application $app): array
    {
        $config = $app['config'];
        $db = (string) $config->get('database.default');
        $packages = [];
        if (class_exists(InstalledVersions::class)) {
            foreach (self::PACKAGES as $package) {
                try {
                    if (InstalledVersions::isInstalled($package)) {
                        $packages[$package] = InstalledVersions::getPrettyVersion($package);
                    }
                } catch (\Throwable) {
                    // ignore broken metadata
                }
            }
        }

        return [
            'app_name' => (string) $config->get('app.name'),
            'app_env' => (string) $config->get('app.env'),
            'debug' => (bool) $config->get('app.debug'),
            'url' => (string) $config->get('app.url'),
            'timezone' => (string) $config->get('app.timezone'),
            'locale' => (string) $config->get('app.locale'),
            'os' => PHP_OS_FAMILY.' '.php_uname('r'),
            'sapi' => PHP_SAPI,
            'octane' => isset($_SERVER['LARAVEL_OCTANE']) || isset($_ENV['LARAVEL_OCTANE']),
            'drivers' => [
                'database' => $db.' ('.$config->get("database.connections.{$db}.driver").')',
                'database_version' => self::databaseVersion($app, $db),
                'cache' => (string) $config->get('cache.default'),
                'queue' => (string) $config->get('queue.default'),
                'session' => (string) $config->get('session.driver'),
                'mail' => (string) $config->get('mail.default'),
                'broadcast' => (string) $config->get('broadcasting.default'),
                'filesystem' => (string) $config->get('filesystems.default'),
                'log' => (string) $config->get('logging.default'),
            ],
            'php' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'opcache' => function_exists('opcache_get_status') && (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false),
                'jit' => (string) ini_get('opcache.jit'),
                'xdebug' => extension_loaded('xdebug'),
                'extensions' => count(get_loaded_extensions()),
            ],
            'packages' => $packages,
        ];
    }

    /** Only reads the version when a connection is already established — never opens one. */
    private static function databaseVersion(Application $app, string $connection): ?string
    {
        try {
            $db = $app['db'];
            foreach ($db->getConnections() as $name => $conn) {
                if ($name === $connection && $conn->getRawPdo()) {
                    return (string) $conn->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
                }
            }
        } catch (\Throwable) {
            // not available
        }

        return null;
    }
}
