<?php

namespace Laramon\Agent;

use Laramon\Agent\Support\Clock;
use Laramon\Agent\Support\Uuid;

/**
 * One unit of work: a request, job attempt, command or scheduled task run.
 * Child records keep a reference to it and resolve exec/user when serialized,
 * so the route name or user discovered later still applies to earlier records.
 */
final class Execution
{
    public readonly string $trace;

    public ?string $name = null;

    public ?string $userId = null;

    public ?array $user = null;

    public bool $userSent = false;

    public bool $finished = false;

    /** @var array<string, int> */
    public array $counts = [
        'queries' => 0, 'exceptions' => 0, 'logs' => 0, 'cache' => 0,
        'mail' => 0, 'notifications' => 0, 'outgoing' => 0, 'jobs' => 0,
    ];

    /** @var array<string, float> timing marks (unix seconds) */
    public array $marks = [];

    /** @var array<string, mixed> type specific state */
    public array $state = [];

    public function __construct(
        public readonly string $type,          // request | job | command | schedule
        public readonly float $startedAt,
        public readonly bool $sampled = true,
        public readonly int $cpuStart = 0,
        ?string $name = null,
    ) {
        $this->trace = Uuid::v4();
        $this->name = $name;
    }

    public static function start(string $type, bool $sampled, ?string $name = null, ?float $startedAt = null, ?int $cpuStart = null): self
    {
        return new self($type, $startedAt ?? Clock::now(), $sampled, $cpuStart ?? Clock::cpu(), $name);
    }

    public function resolvedName(): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        if ($this->type === 'request') {
            return strtoupper((string) ($this->state['method'] ?? 'GET')).' [unmatched]';
        }

        return 'unknown';
    }

    /** @return array{type: string, name: string} */
    public function toExec(): array
    {
        return ['type' => $this->type, 'name' => $this->resolvedName()];
    }

    public function count(string $key): void
    {
        if (isset($this->counts[$key])) {
            $this->counts[$key]++;
        }
    }
}
