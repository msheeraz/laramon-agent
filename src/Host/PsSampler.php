<?php

namespace Laramon\Agent\Host;

/**
 * Fallback sampler for macOS / BSD using ps, sysctl and vm_stat. Mostly for
 * local development; production Linux hosts use LinuxSampler.
 */
class PsSampler extends Sampler
{
    public function prime(): void
    {
        // ps reports a decaying CPU average, no baseline needed.
    }

    public function sample(): array
    {
        $cores = max(1, (int) $this->sh('sysctl -n hw.ncpu'));
        $total = (int) $this->sh('sysctl -n hw.memsize');
        $processes = $this->processes();

        $cpu = 0.0;
        foreach ($processes as $p) {
            $cpu += $p['cpu_pct'];
        }

        $procs = $this->finalizeProcesses($processes);

        return [
            'host' => $this->host,
            'cpu_pct' => round(max(0, min(100, $cpu / $cores)), 1),
            'cpu_cores' => $cores,
            'mem_used' => $this->memoryUsed(),
            'mem_total' => $total,
            'swap_used' => $this->swap(),
            'load1' => $this->load1(),
            'uptime' => $this->uptime(),
            'oom_kills' => 0,
            'php_processes' => $procs['php_processes'],
            'processes' => $procs['processes'],
            'inflight' => $procs['inflight'],
        ];
    }

    protected function alive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0) || (function_exists('posix_get_last_error') && posix_get_last_error() === 1);
        }

        return trim((string) $this->sh('ps -p '.(int) $pid.' -o pid=')) !== '';
    }

    private function sh(string $command): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $out = @shell_exec($command.' 2>/dev/null');

        return is_string($out) ? trim($out) : null;
    }

    private function memoryUsed(): int
    {
        $vm = (string) $this->sh('vm_stat');
        $page = preg_match('/page size of (\d+) bytes/', $vm, $m) ? (int) $m[1] : 4096;
        $pages = 0;
        foreach (['Pages active', 'Pages wired down', 'Pages occupied by compressor'] as $key) {
            if (preg_match('/'.preg_quote($key, '/').':\s+(\d+)/', $vm, $m)) {
                $pages += (int) $m[1];
            }
        }

        return $pages * $page;
    }

    private function swap(): int
    {
        $out = (string) $this->sh('sysctl -n vm.swapusage');
        if (preg_match('/used = ([\d.]+)([KMG])/', $out, $m)) {
            return (int) ((float) $m[1] * ['K' => 1024, 'M' => 1048576, 'G' => 1073741824][$m[2]]);
        }

        return 0;
    }

    private function uptime(): int
    {
        $out = (string) $this->sh('sysctl -n kern.boottime');

        return preg_match('/sec = (\d+)/', $out, $m) ? max(0, time() - (int) $m[1]) : 0;
    }

    /** @return list<array<string, mixed>> */
    private function processes(): array
    {
        $out = [];
        $selfPid = getmypid();
        $lines = explode("\n", (string) $this->sh('ps -axo pid=,rss=,%cpu=,lstart=,command='));

        foreach ($lines as $line) {
            // pid rss %cpu Dow Mon DD HH:MM:SS YYYY command...
            if (! preg_match('/^\s*(\d+)\s+(\d+)\s+([\d.]+)\s+(\w{3}\s+\w{3}\s+\d+\s+[\d:]+\s+\d{4})\s+(.*)$/', $line, $m)) {
                continue;
            }

            $pid = (int) $m[1];
            $started = strtotime($m[4]);

            $out[] = [
                'pid' => $pid,
                'rss' => (int) $m[2] * 1024,
                'cpu_pct' => round((float) $m[3], 1),
                'kind' => $pid === $selfPid ? 'artisan' : self::classify($m[5]),
                'cmd' => mb_substr(trim($m[5]), 0, 512),
                'started_at' => $started ? gmdate('Y-m-d\TH:i:s\Z', $started) : null,
            ];
        }

        return $out;
    }
}
