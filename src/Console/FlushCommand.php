<?php

namespace Laramon\Agent\Console;

use Illuminate\Console\Command;
use Laramon\Agent\Transport;

/** Ship whatever is waiting in the local spool now. */
class FlushCommand extends Command
{
    protected $signature = 'laramon:flush';

    protected $description = 'Ship records waiting in the local Laramon spool';

    public function handle(Transport $transport): int
    {
        $result = $transport->shipSpool();
        $this->components->info($result['records'] ? "Shipped {$result['records']} records." : 'Spool is empty.');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
