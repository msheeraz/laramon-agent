<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Laramon\Agent\Hooks;
use Laramon\Agent\Recorder;

abstract class Watcher
{
    private ?string $basePath = null;

    private ?string $packagePath = null;

    public function __construct(
        protected readonly Application $app,
        protected readonly Recorder $recorder,
    ) {
    }

    abstract public function register(): void;

    protected function listen(string|array $events, callable $handler): void
    {
        $events = (array) $events;
        foreach ($events as $event) {
            if (! class_exists($event)) {
                continue;
            }

            $this->app['events']->listen($event, function ($payload) use ($handler) {
                if (! $this->recorder->isRecording()) {
                    return;
                }

                try {
                    $handler($payload);
                } catch (\Throwable $e) {
                    // The agent must never break the host application.
                    @error_log('[laramon] watcher error: '.$e->getMessage());
                }
            });
        }
    }

    protected function relative(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $this->basePath ??= rtrim($this->app->basePath(), '/').'/';

        return str_starts_with($file, $this->basePath) ? substr($file, strlen($this->basePath)) : $file;
    }

    /**
     * First application (non-vendor, non-agent) frame of the current stack.
     *
     * @return array{0: ?string, 1: ?int}
     */
    protected function location(): array
    {
        $this->packagePath ??= dirname(__DIR__, 2);

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null
                || str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
                || str_starts_with($file, $this->packagePath)
                || str_ends_with($file, 'artisan')
                || str_contains($file, 'eval()\'d')) {
                continue;
            }

            return [$this->relative($file), $frame['line'] ?? null];
        }

        return [null, null];
    }

    protected function hooks(): Hooks
    {
        return $this->recorder->hooks();
    }

    /**
     * Laravel Context (11+) for the current execution, capped at 64KB.
     *
     * @return array<string, mixed>|null
     */
    protected function laravelContext(): ?array
    {
        if (! $this->recorder->config('capture.context', true) || ! class_exists(\Illuminate\Support\Facades\Context::class)) {
            return null;
        }

        try {
            $all = \Illuminate\Support\Facades\Context::all();
            if (! is_array($all) || $all === []) {
                return null;
            }

            $json = json_encode($all, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                return null;
            }

            return strlen($json) > 65536 ? ['_truncated' => true, 'keys' => array_slice(array_keys($all), 0, 100)] : json_decode($json, true);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function matches(string $value, array $patterns): bool
    {
        return $patterns !== [] && Str::is($patterns, $value);
    }

    protected function className(mixed $value): string
    {
        if (is_object($value)) {
            return method_exists($value, 'displayName') ? (string) $value->displayName() : $value::class;
        }

        return is_string($value) ? $value : get_debug_type($value);
    }
}
