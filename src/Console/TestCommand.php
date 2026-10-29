<?php

namespace Laramon\Agent\Console;

use Illuminate\Console\Command;
use Laramon\Agent\Support\Uuid;
use Laramon\Agent\Transport;

/** Sends a sample request, log and exception so the setup can be verified end to end. */
class TestCommand extends Command
{
    protected $signature = 'laramon:test';

    protected $description = 'Send test telemetry (request, log, exception) to Laramon';

    public function handle(Transport $transport): int
    {
        if (! $transport->configured()) {
            $this->components->error('LARAMON_URL / LARAMON_TOKEN are not configured.');

            return self::FAILURE;
        }

        $trace = Uuid::v4();
        $now = microtime(true);
        $exec = ['type' => 'request', 'name' => 'GET /laramon/test'];
        $exception = new \RuntimeException('This is a test exception from laramon:test');

        $records = [
            [
                't' => 'request', 'trace' => $trace, 'ts' => round($now, 6), 'exec' => null, 'user' => null,
                'method' => 'GET', 'url' => rtrim((string) config('app.url'), '/').'/laramon/test',
                'route_name' => 'laramon.test', 'route_path' => '/laramon/test', 'route_action' => 'Closure',
                'ip' => '127.0.0.1', 'status' => 500, 'duration' => 12_345, 'cpu' => 8_000,
                'memory' => memory_get_peak_usage(true), 'req_size' => 0, 'res_size' => 0,
                'phases' => ['bootstrap' => 2_000, 'before_middleware' => 1_000, 'action' => 8_000, 'render' => 0, 'after_middleware' => 345, 'sending' => 500, 'terminating' => 500],
                'counts' => ['queries' => 0, 'exceptions' => 1, 'logs' => 1, 'cache' => 0, 'mail' => 0, 'notifications' => 0, 'outgoing' => 0, 'jobs' => 0],
                'headers' => null,
            ],
            [
                't' => 'log', 'trace' => $trace, 'ts' => round($now + 0.005, 6), 'exec' => $exec, 'user' => null,
                'level' => 'error', 'message' => 'Laramon test log entry', 'context' => ['source' => 'laramon:test'],
            ],
            [
                't' => 'exception', 'trace' => $trace, 'ts' => round($now + 0.006, 6), 'exec' => $exec, 'user' => null,
                'class' => $exception::class, 'message' => $exception->getMessage(), 'code' => '0',
                'file' => 'vendor/laramon/agent/src/Console/TestCommand.php', 'line' => $exception->getLine(), 'handled' => true,
                'frames' => [['file' => 'artisan', 'line' => 13, 'class' => null, 'function' => '{main}']],
            ],
        ];

        $this->components->task('Sending test telemetry to '.$transport->endpoint(), function () use ($transport, $records, &$result) {
            $result = $transport->send($records);

            return $result['status'] >= 200 && $result['status'] < 300;
        });

        $this->line("  HTTP status: {$result['status']}");
        if ($result['error'] ?? null) {
            $this->line("  Error: {$result['error']}");
        }
        if ($result['body'] !== '') {
            $this->line('  Response: '.mb_substr($result['body'], 0, 300));
        }

        return $result['status'] >= 200 && $result['status'] < 300 ? self::SUCCESS : self::FAILURE;
    }
}
