<?php

namespace Laramon\Agent\Console;

use Illuminate\Console\Command;
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
        $this->components->twoColumnDetail('Host sample interval', config('laramon.sample_interval').'s');

        if (! $transport->configured()) {
            return self::FAILURE;
        }

        $result = $transport->send([]);
        $reachable = $result['status'] > 0;
        $authorized = $reachable && $result['status'] !== 401 && $result['status'] !== 403;

        $this->components->twoColumnDetail('Server reachable', $reachable ? '<fg=green>yes</>' : '<fg=red>no</> '.($result['error'] ?? ''));
        $this->components->twoColumnDetail('Token accepted', $authorized ? '<fg=green>yes</>' : '<fg=red>no</> (HTTP '.$result['status'].')');

        return $authorized ? self::SUCCESS : self::FAILURE;
    }
}
