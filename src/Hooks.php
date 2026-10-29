<?php

namespace Laramon\Agent;

/**
 * User supplied filters (reject*) and redactors (redact*) applied by the
 * watchers before a record is buffered. A hook that throws is ignored so a
 * buggy callback can never break the host application.
 */
final class Hooks
{
    /** @var array<string, list<callable>> */
    private array $rejectors = [];

    /** @var array<string, list<callable>> */
    private array $redactors = [];

    public function addRejector(string $type, callable $callback): void
    {
        $this->rejectors[$type][] = $callback;
    }

    public function addRedactor(string $type, callable $callback): void
    {
        $this->redactors[$type][] = $callback;
    }

    /** True when any rejector for the type returns true. */
    public function rejects(string $type, mixed ...$args): bool
    {
        foreach ($this->rejectors[$type] ?? [] as $callback) {
            try {
                if ($callback(...$args) === true) {
                    return true;
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    /** Pipe the value through every redactor for the type. */
    public function redact(string $type, mixed $value): mixed
    {
        foreach ($this->redactors[$type] ?? [] as $callback) {
            try {
                $result = $callback($value);
                if ($result !== null && gettype($result) === gettype($value)) {
                    $value = $result;
                }
            } catch (\Throwable) {
            }
        }

        return $value;
    }

    public function hasRedactor(string $type): bool
    {
        return ! empty($this->redactors[$type]);
    }

    public function flush(): void
    {
        $this->rejectors = [];
        $this->redactors = [];
    }
}
