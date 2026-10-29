<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Laramon\Agent\Execution;
use Laramon\Agent\Support\Clock;

class ScheduleWatcher extends Watcher
{
    /** @var array<int, Execution> keyed by spl_object_id of the scheduled event */
    private array $running = [];

    public function register(): void
    {
        $this->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            if (self::internal($event->task)) {
                return;
            }
            if ($this->recorder->depth() === 0 && function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }

            $execution = Execution::start('schedule', $this->recorder->sample('scheduled_tasks'), self::name($event->task));
            $execution->state['memory_before'] = memory_get_usage(true);

            $this->running[spl_object_id($event->task)] = $execution;
            $this->recorder->begin($execution);
        });

        $this->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $exit = $event->task->exitCode ?? 0;
            $this->finish($event->task, $exit === 0 || $exit === null ? 'processed' : 'failed');
        });

        $this->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->finish($e->task, 'failed'));

        $this->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            if (self::internal($event->task)) {
                return;
            }
            $execution = Execution::start('schedule', $this->recorder->sample('scheduled_tasks'), self::name($event->task));
            $this->recorder->recordExecution($execution, 'scheduled_task', $this->fields($event->task, $execution, 'skipped') + [
                'duration' => 0, 'cpu' => 0, 'memory' => 0, 'memory_delta' => 0,
            ]);
            if ($this->recorder->depth() === 0) {
                $this->recorder->flush();
            }
        });
    }

    /** The agent's own housekeeping tasks are not recorded. */
    private static function internal(object $task): bool
    {
        return str_starts_with((string) ($task->description ?? ''), 'laramon:');
    }

    private function finish(object $task, string $status): void
    {
        $key = spl_object_id($task);
        $execution = $this->running[$key] ?? null;
        unset($this->running[$key]);

        if ($execution === null || $execution->finished) {
            return;
        }

        try {
            $this->recorder->recordExecution($execution, 'scheduled_task', $this->fields($task, $execution, $status) + [
                'duration' => Clock::us(Clock::now() - $execution->startedAt),
                'cpu' => max(0, Clock::cpu() - $execution->cpuStart),
                'memory' => memory_get_peak_usage(true),
                'memory_delta' => memory_get_usage(true) - ($execution->state['memory_before'] ?? 0),
            ]);
        } finally {
            $this->recorder->end($execution);
            if ($this->recorder->depth() === 0) {
                $this->recorder->flush();
            }
        }
    }

    /** @return array<string, mixed> */
    private function fields(object $task, Execution $execution, string $status): array
    {
        $timezone = $task->timezone ?? null;

        return [
            'name' => $execution->name,
            'cron' => $task->expression ?? null,
            'timezone' => $timezone instanceof \DateTimeZone ? $timezone->getName() : ($timezone !== null ? (string) $timezone : null),
            'status' => $status,
        ];
    }

    /** Human name of a scheduled task, e.g. "artisan reports:send --daily". */
    public static function name(object $task): string
    {
        $name = null;

        if (! empty($task->description)) {
            $name = (string) $task->description;
        } elseif (method_exists($task, 'getSummaryForDisplay')) {
            $name = (string) $task->getSummaryForDisplay();
        } elseif (! empty($task->command)) {
            $name = (string) $task->command;
        }

        $name = trim((string) $name) ?: 'Closure';
        // '/usr/bin/php8.4' 'artisan' inspire  ->  artisan inspire
        $name = (string) preg_replace("#^'?[^'\\s]*php[^'\\s]*'?\\s+'?artisan'?\\s+#i", 'artisan ', $name);
        $name = (string) preg_replace('#\\s*>\\s*\'?/dev/null\'?\\s*2>&1\\s*$#', '', $name);

        return mb_substr($name, 0, 1024);
    }
}
