<?php

namespace Laramon\Agent;

use Laramon\Agent\Support\Clock;
use Laramon\Agent\Support\Uuid;

/**
 * Buffers records for the executions running in this process and flushes
 * them through the transport. Executions form a stack so a sync job or an
 * Artisan::call() inside a request gets its own trace.
 */
class Recorder
{
    /** @var list<Execution> */
    private array $stack = [];

    /** @var list<array{0: array<string, mixed>, 1: ?Execution, 2: bool}> record, execution, isChild */
    private array $buffer = [];

    private bool $flushing = false;

    private bool $paused = false;

    private string $processTrace;

    private readonly Hooks $hooks;

    public function __construct(
        private readonly Transport $transport,
        private readonly array $config,
        ?Hooks $hooks = null,
    ) {
        $this->processTrace = Uuid::v4();
        $this->hooks = $hooks ?? new Hooks;
    }

    public function hooks(): Hooks
    {
        return $this->hooks;
    }

    /** Stop recording until resume() (for code that can't use ignore()). */
    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $value = $this->config;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function isRecording(): bool
    {
        return ! $this->flushing && ! $this->paused;
    }

    /** Run a callback without recording anything it triggers. */
    public function ignore(callable $callback): mixed
    {
        $paused = $this->paused;
        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $paused;
        }
    }

    public function sample(string $kind): bool
    {
        $rate = (float) $this->config("sample_rates.{$kind}", 1.0);

        return $rate >= 1.0 || ($rate > 0.0 && mt_rand() / mt_getrandmax() < $rate);
    }

    public function current(): ?Execution
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    public function begin(Execution $execution): Execution
    {
        $this->stack[] = $execution;

        return $execution;
    }

    /** @var list<callable(Execution): void> */
    private array $endingCallbacks = [];

    /** Register a callback run right before an execution ends. */
    public function ending(callable $callback): void
    {
        $this->endingCallbacks[] = $callback;
    }

    /** Attach an authenticated user to an execution without triggering queries. */
    public function identify(?Execution $execution, mixed $user): void
    {
        if ($execution === null || ! is_object($user) || ! method_exists($user, 'getAuthIdentifier')) {
            return;
        }

        try {
            $id = $user->getAuthIdentifier();
            if ($id === null || $id === '') {
                return;
            }

            $execution->userId = substr((string) $id, 0, 64);
            $execution->user = [
                'name' => $this->attribute($user, 'name'),
                'email' => $this->attribute($user, 'email'),
            ];
        } catch (\Throwable) {
        }
    }

    private function attribute(object $user, string $key): ?string
    {
        try {
            $value = method_exists($user, 'getAttributes') ? ($user->getAttributes()[$key] ?? null) : ($user->{$key} ?? null);

            return is_scalar($value) ? substr((string) $value, 0, 255) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Remove the execution from the stack and emit its user record. */
    public function end(Execution $execution): void
    {
        foreach ($this->endingCallbacks as $callback) {
            try {
                $callback($execution);
            } catch (\Throwable) {
            }
        }

        $execution->finished = true;

        foreach ($this->stack as $i => $candidate) {
            if ($candidate === $execution) {
                array_splice($this->stack, $i, 1);
                break;
            }
        }

        if ($execution->userId !== null && ! $execution->userSent && $execution->sampled) {
            $execution->userSent = true;
            $this->buffer[] = [[
                't' => 'user',
                'ts' => round(Clock::now(), 6),
                'id' => $execution->userId,
                'name' => $execution->user['name'] ?? null,
                'email' => $execution->user['email'] ?? null,
            ], $execution, true];
        }
    }

    /**
     * Record the top-level row of an execution (request, job_attempt...).
     *
     * @param  array<string, mixed>  $fields
     */
    public function recordExecution(Execution $execution, string $type, array $fields): void
    {
        if (! $execution->sampled) {
            return;
        }

        $this->buffer[] = [['t' => $type, 'ts' => round($execution->startedAt, 6)] + $fields, $execution, false];
        $this->maybeFlush();
    }

    /**
     * Record a child of the current execution. Without an active execution
     * the record is dropped unless $always (logs, exceptions, job dispatches).
     *
     * @param  array<string, mixed>  $fields
     */
    public function record(string $type, float $ts, array $fields, ?string $countKey = null, bool $always = false): void
    {
        if (! $this->isRecording()) {
            return;
        }

        $execution = $this->current();

        if ($execution === null && ! $always) {
            return;
        }

        if ($execution !== null && ! $execution->sampled && ! $always) {
            return;
        }

        if ($execution !== null && $countKey !== null) {
            $execution->count($countKey);
        }

        $this->buffer[] = [['t' => $type, 'ts' => round($ts, 6)] + $fields, $execution, true];
        $this->maybeFlush();
    }

    public function pending(): int
    {
        return count($this->buffer);
    }

    private function maybeFlush(): void
    {
        if (count($this->buffer) >= max(1, (int) $this->config('buffer_limit', 500))) {
            $this->flush();
        }
    }

    /** @return list<array<string, mixed>> */
    public function serialize(): array
    {
        $out = [];

        foreach ($this->buffer as [$record, $execution, $child]) {
            $out[] = [
                't' => $record['t'],
                'trace' => $execution?->trace ?? $this->processTrace,
                'ts' => $record['ts'],
                'exec' => $child && $execution !== null ? $execution->toExec() : null,
                'user' => $record['user'] ?? $execution?->userId,
            ] + array_diff_key($record, ['t' => 1, 'ts' => 1, 'user' => 1]);
        }

        return $out;
    }

    public function flush(): void
    {
        if ($this->flushing || $this->buffer === []) {
            return;
        }

        $this->flushing = true;

        try {
            $records = $this->serialize();
            $this->buffer = [];
            $this->transport->send($records);
        } catch (\Throwable $e) {
            @error_log('[laramon] flush failed: '.$e->getMessage());
        } finally {
            $this->buffer = [];
            $this->flushing = false;
        }
    }

    /** @return list<Execution> */
    public function executions(): array
    {
        return $this->stack;
    }

    /** Forget all state (long running processes between executions). */
    public function reset(): void
    {
        $this->stack = [];
        $this->buffer = [];
        $this->processTrace = Uuid::v4();
    }
}
