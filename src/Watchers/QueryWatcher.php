<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Laramon\Agent\Support\Clock;
use Laramon\Agent\Support\Stamp;

class QueryWatcher extends Watcher
{
    /** @var array<string, int> query shapes already considered in this process */
    private array $explained = [];

    private bool $explaining = false;

    public function register(): void
    {
        $this->listen(QueryExecuted::class, function (QueryExecuted $event) {
            if ($this->explaining) {
                return;
            }

            $execution = $this->recorder->current();
            if ($execution === null || ! $execution->sampled) {
                return;
            }

            $sql = (string) $event->sql;
            if ($this->hooks()->rejects('queries', $sql, (string) $event->connectionName)) {
                return;
            }

            $duration = Clock::us(((float) $event->time) / 1000);
            [$file, $line] = $this->location();

            $record = [
                'sql' => mb_substr($this->hooks()->redact('queries', $sql), 0, 65535),
                'connection' => (string) $event->connectionName,
                'file' => $file,
                'line' => $line,
                'duration' => $duration,
            ];

            if ($plan = $this->explain($event, $duration)) {
                $record['explain'] = $plan;
            }

            $this->recorder->record('query', Clock::now() - $duration / 1_000_000, $record, 'queries');
        });
    }

    /** @return array{driver: string, rows: array}|null */
    private function explain(QueryExecuted $event, int $durationUs): ?array
    {
        $config = $this->app['config']->get('laramon.explain', []);
        if (! ($config['enabled'] ?? true) || $durationUs < ($config['threshold_ms'] ?? 100) * 1000) {
            return null;
        }

        $sql = ltrim((string) $event->sql);
        if (! preg_match('/^(select|with)\s/i', $sql)) {
            return null;
        }

        // Once per query shape per cooldown per server: PHP-FPM forgets process
        // memory between requests, so the cooldown lives in a shared stamp.
        $shape = md5(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        $cooldown = (int) ($config['cooldown'] ?? 600);
        if (isset($this->explained[$shape]) || ! Stamp::due('explain-'.$shape, $cooldown)) {
            return null;
        }
        $this->explained[$shape] = time();

        $connection = $event->connection;
        $driver = $connection->getDriverName();
        $prefix = match ($driver) {
            'mysql', 'mariadb' => 'EXPLAIN ',
            'pgsql' => 'EXPLAIN (FORMAT JSON) ',
            'sqlite' => 'EXPLAIN QUERY PLAN ',
            default => null,
        };
        if ($prefix === null) {
            return null;
        }

        $this->explaining = true;
        try {
            $rows = $connection->select($prefix.$sql, $event->bindings);

            return ['driver' => $driver, 'rows' => array_map(fn ($r) => (array) $r, array_slice($rows, 0, 50))];
        } catch (\Throwable) {
            return null;
        } finally {
            $this->explaining = false;
        }
    }
}
