<?php

namespace Laramon\Agent\Host;

/**
 * Static facts about the server, collected by the daemon (never in web
 * requests): toolchain versions, OS, CPU, memory, disk. Each probe has a
 * short timeout and failures are simply omitted.
 */
final class HostInfo
{
    public static function collect(string $basePath): array
    {
        $info = [
            'os' => self::osName(),
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
            'cpu_model' => self::cpuModel(),
            'cpu_cores' => self::cores(),
            'mem_total' => self::memTotal(),
            'disk' => self::disk($basePath),
            'php_binary' => PHP_BINARY,
            'php_ini' => php_ini_loaded_file() ?: null,
            'tools' => array_filter([
                'php' => PHP_VERSION,
                'node' => self::version('node -v'),
                'npm' => self::version('npm -v'),
                'composer' => self::version('composer --version --no-ansi', '/Composer version (\S+)/'),
                'nginx' => self::version('nginx -v 2>&1', '/nginx\/(\S+)/'),
                'apache' => self::version('apache2 -v 2>/dev/null || httpd -v', '/Apache\/(\S+)/'),
                'caddy' => self::version('caddy version', '/v?(\d+\.\d+\.\d+)/'),
                'frankenphp' => self::version('frankenphp version', '/FrankenPHP v?(\S+)/'),
                'mysql' => self::version('mysql --version', '/(\d+\.\d+\.\d+)/'),
                'psql' => self::version('psql --version', '/(\d+\.\d+(\.\d+)?)/'),
                'redis' => self::version('redis-server --version', '/v=(\S+)/'),
                'supervisor' => self::version('supervisord --version'),
                'git' => self::version('git --version', '/(\d+\.\d+\.\d+)/'),
            ]),
            'collected_at' => gmdate('c'),
        ];

        return $info;
    }

    private static function version(string $command, string $pattern = '/v?(\d+\.\d+\.\d+\S*)/'): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $proc = @proc_open(['sh', '-c', $command.' 2>&1'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return null;
        }

        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk !== false && $chunk !== '') {
                $output .= $chunk;
            }
            if (! proc_get_status($proc)['running']) {
                $output .= (string) stream_get_contents($pipes[1]);
                break;
            }
            usleep(20_000);
        }
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        @proc_terminate($proc);
        @proc_close($proc);

        if (str_contains($output, 'not found') || str_contains($output, 'No such file')) {
            return null;
        }

        return preg_match($pattern, $output, $m) ? $m[1] : null;
    }

    private static function osName(): string
    {
        if (is_readable('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]+)/m', (string) @file_get_contents('/etc/os-release'), $m)) {
            return $m[1];
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return 'macOS '.(self::version('sw_vers -productVersion', '/(\d+(\.\d+)+)/') ?? '');
        }

        return PHP_OS_FAMILY;
    }

    private static function cpuModel(): ?string
    {
        if (is_readable('/proc/cpuinfo') && preg_match('/^model name\s*:\s*(.+)$/m', (string) @file_get_contents('/proc/cpuinfo'), $m)) {
            return trim($m[1]);
        }
        if (PHP_OS_FAMILY === 'Darwin') {
            return self::version('sysctl -n machdep.cpu.brand_string', '/(.+)/');
        }

        return null;
    }

    private static function cores(): ?int
    {
        if (is_readable('/proc/cpuinfo')) {
            return substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor') ?: null;
        }
        $n = self::version('sysctl -n hw.ncpu', '/(\d+)/');

        return $n ? (int) $n : null;
    }

    private static function memTotal(): ?int
    {
        if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+) kB/', (string) @file_get_contents('/proc/meminfo'), $m)) {
            return (int) $m[1] * 1024;
        }
        $n = self::version('sysctl -n hw.memsize', '/(\d+)/');

        return $n ? (int) $n : null;
    }

    private static function disk(string $path): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return $total ? ['path' => $path, 'total' => (int) $total, 'free' => (int) $free] : null;
    }
}
