<?php

namespace Laramon\Agent\Console;

use Laramon\Agent\Host\HostInfo;
use Illuminate\Console\Command;
use Laramon\Agent\Host\Sampler;
use Laramon\Agent\Transport;

/** One-off host sample, for cron based setups without a daemon. */
class SampleCommand extends Command
{
    protected $signature = 'laramon:sample {--dump : Print the sample instead of only sending it}';

    protected $description = 'Send a single Laramon host resource sample';

    public function handle(Transport $transport): int
    {
        $sampler = Sampler::make((string) config('laramon.server', gethostname()), (int) config('laramon.top_processes', 15));
        $sampler->prime();
        usleep(500_000);

        $record = AgentCommand::record($sampler->sample() + ['info' => HostInfo::collect(base_path())]);

        if ($this->option('dump')) {
            $this->line((string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if (! $transport->configured()) {
            $this->components->error('LARAMON_URL / LARAMON_TOKEN are not configured.');

            return self::FAILURE;
        }

        $result = $transport->send([$record]);
        $ok = $result['status'] >= 200 && $result['status'] < 300;

        $ok
            ? $this->components->info("Sample sent (HTTP {$result['status']}).")
            : $this->components->error("Sample failed (HTTP {$result['status']}) ".($result['error'] ?? $result['body']));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
