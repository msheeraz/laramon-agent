<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Laramon\Agent\Execution;
use Laramon\Agent\Support\Clock;

class JobWatcher extends Watcher
{
    /** @var array<int, Execution> keyed by spl_object_id of the queue job */
    private array $running = [];

    /** @var array<int, float> dispatch start keyed by spl_object_id of the job payload object */
    private array $queueing = [];

    public function register(): void
    {
        $this->linkDispatchingUser();

        $this->listen(JobQueueing::class, function ($event) {
            if (is_object($event->job ?? null)) {
                $this->queueing[spl_object_id($event->job)] = Clock::now();
            }
        });

        $this->listen(JobQueued::class, function ($event) {
            $now = Clock::now();
            $key = is_object($event->job ?? null) ? spl_object_id($event->job) : null;
            $start = $key !== null ? ($this->queueing[$key] ?? $now) : $now;
            if ($key !== null) {
                unset($this->queueing[$key]);
            }

            $name = $this->className($event->job ?? null);
            if ($this->hooks()->rejects('jobs', $name, $event->job ?? null)) {
                return;
            }

            $this->recorder->record('job_queued', $start, [
                'job_id' => $this->queuedId($event),
                'name' => $name,
                'connection' => $event->connectionName ?? null,
                'queue' => $event->queue ?? null,
                'duration' => Clock::us($now - $start),
            ], 'jobs', always: true);
        });

        $this->listen(JobProcessing::class, function (JobProcessing $event) {
            $job = $event->job;
            $name = (string) $job->resolveName();
            $root = $this->recorder->depth() === 0;

            if ($root) {
                // A fresh unit of work in a worker: per job peak memory.
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
            }

            $sampled = ! $this->matches($name, (array) $this->recorder->config('ignore.jobs', []))
                && ! $this->hooks()->rejects('jobs', $name, $job)
                && $this->recorder->sample('jobs');

            $execution = Execution::start('job', $sampled, $name);
            $execution->state = [
                'job_id' => $job->uuid() ?? (string) $job->getJobId(),
                'connection' => $event->connectionName,
                'queue' => $job->getQueue(),
                'memory_before' => memory_get_usage(true),
            ];

            // The user who dispatched the job (set by createPayloadUsing below).
            try {
                $dispatcher = $job->payload()['laramon_user'] ?? null;
                if (is_scalar($dispatcher) && (string) $dispatcher !== '') {
                    $execution->userId = substr((string) $dispatcher, 0, 64);
                    $execution->userSent = true; // id only: don't overwrite the stored name/email
                }
            } catch (\Throwable) {
            }

            $this->running[spl_object_id($job)] = $execution;
            $this->recorder->begin($execution);
        });

        $this->listen(JobProcessed::class, fn (JobProcessed $e) => $this->finish($e->job, 'processed'));
        $this->listen(JobReleasedAfterException::class, fn ($e) => $this->finish($e->job, 'released'));
        $this->listen(JobFailed::class, fn (JobFailed $e) => $this->finish($e->job, 'failed', $e->connectionName));
    }

    private function finish(object $job, string $status, ?string $connection = null): void
    {
        $key = spl_object_id($job);
        $execution = $this->running[$key] ?? null;
        unset($this->running[$key]);

        if ($execution === null) {
            // Failed without being processed here (e.g. max attempts exceeded).
            $execution = Execution::start('job', $this->recorder->sample('jobs'), (string) $job->resolveName());
            $execution->state = [
                'job_id' => $job->uuid() ?? (string) $job->getJobId(),
                'connection' => $connection,
                'queue' => $job->getQueue(),
                'memory_before' => memory_get_usage(true),
            ];
        }

        if ($execution->finished) {
            return;
        }

        try {
            $this->recorder->recordExecution($execution, 'job_attempt', [
                'job_id' => $execution->state['job_id'],
                'name' => $execution->name,
                'connection' => $execution->state['connection'],
                'queue' => $execution->state['queue'],
                'attempt' => max(1, (int) $job->attempts()),
                'status' => $status,
                'duration' => Clock::us(Clock::now() - $execution->startedAt),
                'cpu' => max(0, Clock::cpu() - $execution->cpuStart),
                'memory' => memory_get_peak_usage(true),
                'memory_delta' => memory_get_usage(true) - $execution->state['memory_before'],
            ] + array_filter(['context' => $this->laravelContext()]));
        } finally {
            $this->recorder->end($execution);
            if ($this->recorder->depth() === 0) {
                $this->recorder->flush();
            }
        }
    }

    /** Stamp queued payloads with the id of the user dispatching them. */
    private function linkDispatchingUser(): void
    {
        if (! class_exists(\Illuminate\Queue\Queue::class) || ! method_exists(\Illuminate\Queue\Queue::class, 'createPayloadUsing')) {
            return;
        }

        \Illuminate\Queue\Queue::createPayloadUsing(function () {
            try {
                $id = $this->recorder->current()?->userId;
                if ($id === null && $this->app->resolved('auth')) {
                    $guard = $this->app['auth']->guard();
                    if ($guard->hasUser()) {
                        $id = $guard->id();
                    }
                }

                return $id !== null && $id !== '' ? ['laramon_user' => (string) $id] : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    private function queuedId(object $event): ?string
    {
        try {
            if (method_exists($event, 'payload')) {
                $payload = $event->payload();
                if (is_array($payload) && isset($payload['uuid'])) {
                    return (string) $payload['uuid'];
                }
            }

            if (isset($event->payload) && is_string($event->payload)) {
                $payload = json_decode($event->payload, true);
                if (isset($payload['uuid'])) {
                    return (string) $payload['uuid'];
                }
            }
        } catch (\Throwable) {
        }

        return isset($event->id) ? (string) $event->id : null;
    }
}
