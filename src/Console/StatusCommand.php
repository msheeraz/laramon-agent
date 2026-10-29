<?php

namespace Laramon\Agent\Console;

use Illuminate\Console\Command;
use Laramon\Agent\Support\Spool;
use Laramon\Agent\Transport;

class StatusCommand extends Command
{
    protected $signature = 'laramon:status';

    protected $description = 'Show the Laramon agent configuration and check connectivity';

    public function handle(Transport $transport): int
    {
        $token = (string) config('laramon.token');

        $this->components->twoColumnDetail('Enabled', config('laramon.enabled') ? '<fg=green>yes</>' : '<fg=red>no</>');
        $this->components->twoColumnDetail('Endpoint', $transport->configured() ? $transport->endpoint() : '<fg=red>not configured</>');
        $this->components->twoColumnDetail('Token', $token !== '' ? substr($token, 0, 6).str_repeat('•', 8) : '<fg=red>missing</>');
        $this->components->twoColumnDetail('Server name', (string) config('laramon.server'));
        $this->components->twoColumnDetail('Sample rates', json_encode(config('laramon.sample_rates')));
        $this->components->twoColumnDetail('Transport', $transport->mode() === 'direct'
            ? '<fg=yellow>direct</> (every execution posts to Laramon — debugging only)'
            : '<fg=green>spool</>, shipped every '.config('laramon.spool_interval', 5).'s per server');
        $spool = @filesize(Spool::dir().DIRECTORY_SEPARATOR.'spool.ndjson');
        if ($spool) {
            $this->components->twoColumnDetail('Spool waiting', round($spool / 1024).' KB');
        }

        if (! $transport->configured()) {
            return self::FAILURE;
        }

        $result = $transport->sendDirect([]);
        $reachable = $result['status'] > 0;
        $authorized = $reachable && $result['status'] !== 401 && $result['status'] !== 403;

        $this->components->twoColumnDetail('Server reachable', $reachable ? '<fg=green>yes</>' : '<fg=red>no</> '.($result['error'] ?? ''));
        $this->components->twoColumnDetail('Token accepted', $authorized ? '<fg=green>yes</>' : '<fg=red>no</> (HTTP '.$result['status'].')');

        return $authorized ? self::SUCCESS : self::FAILURE;
    }
}
