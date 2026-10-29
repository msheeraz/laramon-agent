<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Laramon\Agent\Execution;
use Laramon\Agent\Support\Clock;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

class CommandWatcher extends Watcher
{
    /** @var list<Execution> */
    private array $running = [];

    public function register(): void
    {
        $this->listen(CommandStarting::class, function (CommandStarting $event) {
            $name = $event->command;
            if ($name === null || $name === '' || $this->matches($name, (array) $this->recorder->config('ignore.commands', []))) {
                return;
            }

            if ($this->recorder->depth() === 0 && function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }

            $execution = Execution::start('command', $this->recorder->sample('commands'), $name);
            $execution->state['line'] = $this->line($name, $event->input);

            $this->running[] = $execution;
            $this->recorder->begin($execution);
        });

        $this->listen(CommandFinished::class, function (CommandFinished $event) {
            $execution = null;
            for ($i = count($this->running) - 1; $i >= 0; $i--) {
                if ($this->running[$i]->name === $event->command) {
                    $execution = $this->running[$i];
                    array_splice($this->running, $i, 1);
                    break;
                }
            }

            if ($execution === null || $execution->finished) {
                return;
            }

            try {
                $this->recorder->recordExecution($execution, 'command', array_filter([
                    'name' => $execution->name,
                    'command' => mb_substr($this->hooks()->redact('commands', (string) $execution->state['line']), 0, 1024),
                    'exit_code' => (int) $event->exitCode,
                    'duration' => Clock::us(Clock::now() - $execution->startedAt),
                    'cpu' => max(0, Clock::cpu() - $execution->cpuStart),
                    'memory' => memory_get_peak_usage(true),
                    'context' => $this->laravelContext(),
                ], fn ($v) => $v !== null));
            } finally {
                $this->recorder->end($execution);
                if ($this->recorder->depth() === 0) {
                    $this->recorder->flush();
                }
            }
        });
    }

    private function line(string $name, ?InputInterface $input): string
    {
        $line = 'artisan '.$name;

        try {
            $argv = $_SERVER['argv'] ?? null;
            if ($input instanceof ArgvInput && is_array($argv) && in_array($name, $argv, true)) {
                return mb_substr('artisan '.implode(' ', array_slice($argv, 1)), 0, 1024);
            }

            if ($input !== null && method_exists($input, '__toString')) {
                $string = trim((string) $input);
                $line = 'artisan '.(str_starts_with(trim($string, "'\""), $name) ? $string : $name.' '.$string);
            }
        } catch (\Throwable) {
        }

        return mb_substr(trim($line), 0, 1024);
    }
}
