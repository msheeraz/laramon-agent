<?php

namespace Laramon\Agent\Host;

use Laramon\Agent\Support\Inflight;

/**
 * Collects host / container resource usage. CPU percentages are computed
 * from deltas between consecutive samples, so keep one instance alive in the
 * daemon (or call prime() before a one-off sample).
 */
abstract class Sampler
{
    public function __construct(protected readonly string $host, protected readonly int $top = 15)
    {
    }

    public static function make(string $host, int $top = 15): self
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/stat')) {
            return new LinuxSampler($host, $top);
        }

        return new PsSampler($host, $top);
    }

    /** Take a baseline reading so the next sample() has CPU deltas. */
    abstract public function prime(): void;

    /** @return array<string, mixed> host_sample fields (without t/ts/trace) */
    abstract public function sample(): array;

    abstract protected function alive(int $pid): bool;

    /**
     * @param  list<array<string, mixed>>  $processes  all processes (unsorted)
     * @return array{processes: list<array<string, mixed>>, inflight: list<array<string, mixed>>, php_processes: int}
     */
    protected function finalizeProcesses(array $processes): array
    {
        $inflight = Inflight::all(fn (int $pid) => $this->alive($pid));

        $php = 0;
        foreach ($processes as $p) {
            if (! in_array($p['kind'], ['other'], true)) {
                $php++;
            }
        }

        usort($processes, fn ($a, $b) => $b['rss'] <=> $a['rss']);
        $processes = array_slice($processes, 0, max(1, $this->top));

        foreach ($processes as &$p) {
            $p['current'] = $inflight[$p['pid']] ?? null;
        }
        unset($p);

        $entries = [];
        foreach ($inflight as $pid => $data) {
            $entries[] = ['pid' => $pid] + $data;
        }

        return ['processes' => $processes, 'inflight' => $entries, 'php_processes' => $php];
    }

    public static function classify(string $cmd): string
    {
        $c = strtolower(trim($cmd));

        // Only PHP-ish executables are classified (not "sh -c '... queue:work'" wrappers).
        $exe = basename((string) strtok($c, ' '));
        if (! str_contains($exe, 'php') && ! in_array($exe, ['artisan', 'rr', 'frankenphp'], true)) {
            return 'other';
        }

        return match (true) {
            str_contains($c, 'php-fpm') => 'fpm',
            str_contains($c, 'queue:work') || str_contains($c, 'queue:listen') => 'queue',
            str_contains($c, 'horizon') => 'horizon',
            str_contains($c, 'schedule:run') || str_contains($c, 'schedule:work') => 'scheduler',
            str_contains($c, 'octane') || str_contains($c, 'frankenphp') || str_contains($c, 'swoole') || str_contains($c, 'roadrunner') || preg_match('#(^|/)rr\s+serve#', $c) === 1 => 'octane',
            str_contains($c, 'reverb') => 'reverb',
            str_contains($c, 'artisan') => 'artisan',
            preg_match('#(^|/)php[\d.]*(\s|$)#', $c) === 1 => 'php',
            default => 'other',
        };
    }

    protected function load1(): float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return is_array($load) ? round((float) $load[0], 2) : 0.0;
    }
}
