<?php

namespace Laramon\Agent\Console;

use Laramon\Agent\Host\HostInfo;
use Illuminate\Console\Command;
use Laramon\Agent\Host\Sampler;
use Laramon\Agent\Support\Uuid;
use Laramon\Agent\Transport;

/**
 * Long running daemon that ships a host_sample every few seconds.
 * Run one per server / container (supervisor, Laravel Cloud background process).
 */
class AgentCommand extends Command
{
    protected $signature = 'laramon:agent {--interval= : Seconds between samples} {--once : Take a single sample and exit}';

    protected $description = 'Run the Laramon host resource sampler (CPU, memory, processes, OOM kills)';

    private bool $running = true;

    public function handle(Transport $transport): int
    {
        if (! $transport->configured()) {
            $this->components->error('LARAMON_URL / LARAMON_TOKEN are not configured.');

            return self::FAILURE;
        }

        $interval = max(1, (int) ($this->option('interval') ?: config('laramon.sample_interval', 10)));
        $sampler = Sampler::make((string) config('laramon.server', gethostname()), (int) config('laramon.top_processes', 15));

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, function () {
                    $this->running = false;
                });
            }
        }

        $sampler->prime();
        usleep(500_000);

        $this->components->info("Laramon agent sampling every {$interval}s → {$transport->endpoint()}");

        $infoAt = 0.0;

        while ($this->running) {
            $started = microtime(true);

            try {
                $sample = $sampler->sample();
                // Static host facts (tool versions, OS, CPU, disk) at start and then hourly.
                if ($started - $infoAt >= 3600) {
                    $sample['info'] = HostInfo::collect(base_path());
                    $infoAt = $started;
                }
                $result = $transport->send([self::record($sample)]);
                if ($this->output->isVerbose()) {
                    $this->line(sprintf('[%s] sample sent: HTTP %d %s', date('H:i:s'), $result['status'], $result['error'] ?? ''));
                }
            } catch (\Throwable $e) {
                $this->components->warn('Sample failed: '.$e->getMessage());
            }

            if ($this->option('once')) {
                break;
            }

            // Sleep in small steps so signals stop us quickly.
            $until = $started + $interval;
            while ($this->running && microtime(true) < $until) {
                usleep(200_000);
            }

            gc_collect_cycles();
        }

        $this->components->info('Laramon agent stopped.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $sample */
    public static function record(array $sample): array
    {
        return ['t' => 'host_sample', 'trace' => Uuid::v4(), 'ts' => round(microtime(true), 6), 'exec' => null, 'user' => null] + $sample;
    }
}
