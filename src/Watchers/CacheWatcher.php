<?php

namespace Laramon\Agent\Watchers;

use Laramon\Agent\Support\Clock;

class CacheWatcher extends Watcher
{
    private const KINDS = [
        'Illuminate\Cache\Events\CacheHit' => 'hit',
        'Illuminate\Cache\Events\CacheMissed' => 'miss',
        'Illuminate\Cache\Events\KeyWritten' => 'write',
        'Illuminate\Cache\Events\KeyForgotten' => 'delete',
        'Illuminate\Cache\Events\KeyWriteFailed' => 'failure',
        'Illuminate\Cache\Events\KeyForgetFailed' => 'failure',
    ];

    /** @var array<string, float> operation start keyed by store|key */
    private array $started = [];

    public function register(): void
    {
        // Laravel 11+ fires "before" events we can use for durations.
        $this->listen([
            'Illuminate\Cache\Events\RetrievingKey',
            'Illuminate\Cache\Events\WritingKey',
            'Illuminate\Cache\Events\ForgettingKey',
        ], function ($event) {
            if ($this->recorder->current() !== null && count($this->started) < 1000) {
                $this->started[$this->key($event)] = Clock::now();
            }
        });

        foreach (self::KINDS as $class => $kind) {
            $this->listen($class, function ($event) use ($kind) {
                $execution = $this->recorder->current();
                if ($execution === null || ! $execution->sampled) {
                    return;
                }

                $key = (string) ($event->key ?? '');
                $store = $event->storeName ?? (string) config('cache.default');
                if ($this->matches($key, (array) $this->recorder->config('ignore.cache_keys', []))
                    || $this->hooks()->rejects('cache', $key, $kind, (string) $store)) {
                    return;
                }

                $now = Clock::now();
                $id = $this->key($event);
                $start = $this->started[$id] ?? $now;
                unset($this->started[$id]);

                $this->recorder->record('cache', $start, [
                    'store' => $store,
                    'key' => mb_substr($this->hooks()->redact('cache_keys', $key), 0, 512),
                    'kind' => $kind,
                    'duration' => Clock::us($now - $start),
                ], 'cache');
            });
        }
    }

    private function key(object $event): string
    {
        return ($event->storeName ?? '').'|'.($event->key ?? '');
    }
}
