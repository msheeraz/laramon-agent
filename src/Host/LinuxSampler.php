<?php

namespace Laramon\Agent\Host;

/**
 * Linux sampler: cgroup v2 / v1 (containers, Laravel Cloud, Kubernetes)
 * with /proc fallbacks for bare hosts.
 */
class LinuxSampler extends Sampler
{
    private ?array $cpuPrev = null;      // [usage µs, wall µs]

    /** @var array<int, int> pid => utime+stime ticks */
    private array $procPrev = [];

    private ?float $procPrevAt = null;

    private ?string $cgroupV2 = null;

    private int $clkTck = 100;

    private int $pageSize = 4096;

    public function __construct(string $host, int $top = 15)
    {
        parent::__construct($host, $top);

        if (function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')) {
            $this->clkTck = (int) (posix_sysconf(POSIX_SC_CLK_TCK) ?: 100);
        }
        if (function_exists('posix_sysconf') && defined('POSIX_SC_PAGESIZE')) {
            $this->pageSize = (int) (posix_sysconf(POSIX_SC_PAGESIZE) ?: 4096);
        }

        $this->cgroupV2 = $this->detectCgroupV2();
    }

    public function prime(): void
    {
        $this->cpuPrev = $this->cpuUsage();
        $this->processes();
    }

    public function sample(): array
    {
        [$memUsed, $memTotal] = $this->memory();
        $cores = $this->cores();
        $procs = $this->finalizeProcesses($this->processes());

        return [
            'host' => $this->host,
            'cpu_pct' => $this->cpuPercent($cores),
            'cpu_cores' => (int) ceil($cores),
            'mem_used' => $memUsed,
            'mem_total' => $memTotal,
            'swap_used' => $this->swap(),
            'load1' => $this->load1(),
            'uptime' => $this->uptime(),
            'oom_kills' => $this->oomKills(),
            'php_processes' => $procs['php_processes'],
            'processes' => $procs['processes'],
            'inflight' => $procs['inflight'],
        ];
    }

    protected function alive(int $pid): bool
    {
        return is_dir("/proc/{$pid}");
    }

    private function read(string $file): ?string
    {
        $v = is_readable($file) ? @file_get_contents($file) : false;

        return $v === false ? null : trim($v);
    }

    /** @return array<string, int> */
    private function keyValues(?string $content): array
    {
        $out = [];
        foreach (explode("\n", (string) $content) as $line) {
            $parts = preg_split('/[\s:]+/', trim($line));
            if (count($parts) >= 2 && is_numeric($parts[1])) {
                $out[$parts[0]] = (int) $parts[1];
            }
        }

        return $out;
    }

    private function detectCgroupV2(): ?string
    {
        if (! is_file('/sys/fs/cgroup/cgroup.controllers')) {
            return null;
        }

        // "0::/some/path" — inside a container namespace this is usually "/".
        $path = '/';
        foreach (explode("\n", (string) $this->read('/proc/self/cgroup')) as $line) {
            if (str_starts_with($line, '0::')) {
                $path = substr($line, 3) ?: '/';
            }
        }

        foreach ([rtrim('/sys/fs/cgroup'.$path, '/'), '/sys/fs/cgroup'] as $dir) {
            if (is_file($dir.'/memory.current') || is_file($dir.'/cpu.stat')) {
                return $dir;
            }
        }

        return '/sys/fs/cgroup';
    }

    /** @return array{0: int, 1: int} used, total bytes */
    private function memory(): array
    {
        $meminfo = $this->keyValues($this->read('/proc/meminfo'));
        $hostTotal = ($meminfo['MemTotal'] ?? 0) * 1024;
        $hostUsed = max(0, $hostTotal - ($meminfo['MemAvailable'] ?? $meminfo['MemFree'] ?? 0) * 1024);

        if ($this->cgroupV2 !== null && ($current = $this->read($this->cgroupV2.'/memory.current')) !== null) {
            $stat = $this->keyValues($this->read($this->cgroupV2.'/memory.stat'));
            $used = max(0, (int) $current - ($stat['inactive_file'] ?? 0));
            $max = $this->read($this->cgroupV2.'/memory.max');
            $total = $max === null || $max === 'max' ? $hostTotal : min((int) $max, $hostTotal ?: PHP_INT_MAX);

            return [$used, $total];
        }

        if (($usage = $this->read('/sys/fs/cgroup/memory/memory.usage_in_bytes')) !== null) {
            $stat = $this->keyValues($this->read('/sys/fs/cgroup/memory/memory.stat'));
            $used = max(0, (int) $usage - ($stat['total_inactive_file'] ?? 0));
            $limit = (int) $this->read('/sys/fs/cgroup/memory/memory.limit_in_bytes');
            $total = $limit > 0 && $limit < ($hostTotal ?: PHP_INT_MAX) ? $limit : $hostTotal;

            return [$used, $total];
        }

        return [$hostUsed, $hostTotal];
    }

    private function swap(): int
    {
        if ($this->cgroupV2 !== null && ($v = $this->read($this->cgroupV2.'/memory.swap.current')) !== null) {
            return (int) $v;
        }

        $meminfo = $this->keyValues($this->read('/proc/meminfo'));

        return max(0, (($meminfo['SwapTotal'] ?? 0) - ($meminfo['SwapFree'] ?? 0)) * 1024);
    }

    private function oomKills(): int
    {
        if ($this->cgroupV2 !== null && ($v = $this->read($this->cgroupV2.'/memory.events')) !== null) {
            return $this->keyValues($v)['oom_kill'] ?? 0;
        }

        if (($v = $this->read('/sys/fs/cgroup/memory/memory.oom_control')) !== null) {
            return $this->keyValues($v)['oom_kill'] ?? 0;
        }

        return $this->keyValues($this->read('/proc/vmstat'))['oom_kill'] ?? 0;
    }

    private function cores(): float
    {
        $online = 0;
        foreach (explode("\n", (string) $this->read('/proc/cpuinfo')) as $line) {
            if (str_starts_with($line, 'processor')) {
                $online++;
            }
        }
        $online = max(1, $online);

        $quota = null;
        if ($this->cgroupV2 !== null && ($max = $this->read($this->cgroupV2.'/cpu.max')) !== null) {
            [$q, $p] = array_pad(explode(' ', $max), 2, '100000');
            if ($q !== 'max' && (int) $p > 0) {
                $quota = (int) $q / (int) $p;
            }
        } elseif (($q = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us')) !== null && (int) $q > 0) {
            $p = (int) ($this->read('/sys/fs/cgroup/cpu/cpu.cfs_period_us') ?: 100000);
            $quota = (int) $q / max(1, $p);
        }

        return $quota !== null ? max(0.01, min($quota, $online)) : $online;
    }

    /** CPU time consumed (µs) by the container / host. */
    private function cpuUsage(): array
    {
        $wall = (int) (microtime(true) * 1_000_000);

        if ($this->cgroupV2 !== null && ($stat = $this->read($this->cgroupV2.'/cpu.stat')) !== null) {
            $usage = $this->keyValues($stat)['usage_usec'] ?? null;
            if ($usage !== null) {
                return [$usage, $wall, false];
            }
        }

        foreach (['/sys/fs/cgroup/cpuacct/cpuacct.usage', '/sys/fs/cgroup/cpu,cpuacct/cpuacct.usage'] as $file) {
            if (($ns = $this->read($file)) !== null) {
                return [intdiv((int) $ns, 1000), $wall, false];
            }
        }

        // /proc/stat: busy and total jiffies across all CPUs.
        $line = strtok((string) $this->read('/proc/stat'), "\n");
        $parts = array_map('intval', array_slice(preg_split('/\s+/', (string) $line), 1));
        $idle = ($parts[3] ?? 0) + ($parts[4] ?? 0);
        $total = array_sum(array_slice($parts, 0, 8));

        return [$total - $idle, $total, true];
    }

    private function cpuPercent(float $cores): float
    {
        $now = $this->cpuUsage();
        $prev = $this->cpuPrev;
        $this->cpuPrev = $now;

        if ($prev === null || $prev[2] !== $now[2]) {
            return 0.0;
        }

        $busy = $now[0] - $prev[0];
        $elapsed = $now[1] - $prev[1];
        if ($elapsed <= 0) {
            return 0.0;
        }

        // /proc/stat deltas are already relative to all CPUs.
        $pct = $now[2] ? $busy / $elapsed * 100 : $busy / ($elapsed * $cores) * 100;

        return round(max(0, min(100, $pct)), 1);
    }

    private function uptime(): int
    {
        $system = (float) explode(' ', (string) $this->read('/proc/uptime'))[0];

        // In a container PID 1 is the entrypoint, its age is the container uptime.
        $comm = $this->read('/proc/1/comm');
        if ($comm !== null && ! in_array($comm, ['systemd', 'init'], true)) {
            $start = $this->startTicks(1);
            if ($start !== null) {
                return max(0, (int) ($system - $start / $this->clkTck));
            }
        }

        return (int) $system;
    }

    private function startTicks(int $pid): ?int
    {
        $fields = $this->statFields($pid);

        return $fields !== null ? (int) ($fields[19] ?? 0) : null;
    }

    /** Fields after the "(comm)" part of /proc/[pid]/stat; index 0 = state. */
    private function statFields(int $pid): ?array
    {
        $stat = $this->read("/proc/{$pid}/stat");
        if ($stat === null || ($pos = strrpos($stat, ')')) === false) {
            return null;
        }

        return explode(' ', trim(substr($stat, $pos + 1)));
    }

    /** @return list<array<string, mixed>> */
    private function processes(): array
    {
        $now = microtime(true);
        $elapsed = $this->procPrevAt !== null ? max(0.001, $now - $this->procPrevAt) : null;
        $boot = $this->keyValues($this->read('/proc/stat'))['btime'] ?? (int) (time() - (float) $this->read('/proc/uptime'));
        $selfPid = getmypid();

        $out = [];
        $ticksNow = [];

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $pid = (int) basename($dir);
            $cmd = $this->read("{$dir}/cmdline");
            if ($cmd === null || $cmd === '') {
                continue; // kernel threads / vanished
            }
            $cmd = trim(str_replace("\0", ' ', $cmd));

            $fields = $this->statFields($pid);
            $statm = $this->read("{$dir}/statm");
            if ($fields === null || $statm === null) {
                continue;
            }

            $rss = (int) (explode(' ', $statm)[1] ?? 0) * $this->pageSize;
            $ticks = (int) ($fields[11] ?? 0) + (int) ($fields[12] ?? 0);
            $ticksNow[$pid] = $ticks;

            $cpu = 0.0;
            if ($elapsed !== null && isset($this->procPrev[$pid])) {
                $cpu = round(max(0, ($ticks - $this->procPrev[$pid]) / $this->clkTck / $elapsed * 100), 1);
            }

            $out[] = [
                'pid' => $pid,
                'rss' => $rss,
                'cpu_pct' => $cpu,
                'kind' => $pid === $selfPid ? 'artisan' : self::classify($cmd),
                'cmd' => mb_substr($cmd, 0, 512),
                'started_at' => gmdate('Y-m-d\TH:i:s\Z', (int) ($boot + ((int) ($fields[19] ?? 0)) / $this->clkTck)),
            ];
        }

        $this->procPrev = $ticksNow;
        $this->procPrevAt = $now;

        return $out;
    }
}
