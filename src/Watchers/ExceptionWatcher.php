<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Laramon\Agent\Support\Clock;
use Throwable;
use WeakMap;

class ExceptionWatcher extends Watcher
{
    /** @var WeakMap<Throwable, true>|null */
    private ?WeakMap $seen = null;

    public function register(): void
    {
        $hook = function ($handler) {
            if (is_object($handler) && method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e) {
                    $this->capture($e);
                });
            }
        };

        if ($this->app->resolved(ExceptionHandler::class)) {
            $hook($this->app->make(ExceptionHandler::class));
        } else {
            $this->app->afterResolving(ExceptionHandler::class, $hook);
        }
    }

    public function capture(Throwable $e, ?bool $handled = null): void
    {
        if (! $this->recorder->isRecording()) {
            return;
        }

        try {
            $this->seen ??= new WeakMap;
            if (isset($this->seen[$e])) {
                return;
            }
            $this->seen[$e] = true;

            $handled ??= $this->reportedManually();

            $fields = [
                'class' => $e::class,
                'message' => mb_substr($this->hooks()->redact('exceptions', $e->getMessage()), 0, 10000),
                'code' => (string) $e->getCode(),
                'file' => $this->relative($e->getFile()),
                'line' => $e->getLine(),
                'handled' => $handled,
                'frames' => $this->frames($e),
            ];

            if (! $handled && ($payload = $this->payload())) {
                $fields['payload'] = $payload;
            }

            $this->recorder->record('exception', Clock::now(), $fields, 'exceptions', always: true);
        } catch (Throwable) {
        }
    }

    /**
     * report() / rescue() mean the application caught the exception itself;
     * anything else reached the handler through the framework (unhandled).
     */
    private function reportedManually(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if (! isset($frame['class']) && in_array($frame['function'], ['report', 'rescue', 'report_if', 'report_unless'], true)) {
                return true;
            }
        }

        return false;
    }

    /** PHP 8.4 names closures "{closure:/abs/path/file.php:12}". */
    private function relativeClosure(string $function): string
    {
        $base = rtrim($this->app->basePath(), '/').'/';

        return str_contains($function, $base) ? str_replace($base, '', $function) : $function;
    }

    /**
     * Frames with the throw site first: frame i is the code location (file/line)
     * inside the function named by trace[i] — how developers read a trace.
     *
     * @return list<array{file: ?string, line: ?int, class: ?string, function: ?string, code?: array}>
     */
    private function frames(Throwable $e): array
    {
        $trace = array_slice($e->getTrace(), 0, 50);
        $locations = [['file' => $e->getFile(), 'line' => $e->getLine()]];
        foreach ($trace as $frame) {
            $locations[] = ['file' => $frame['file'] ?? null, 'line' => $frame['line'] ?? null];
        }

        $withSource = (bool) $this->recorder->config('capture.source_code', true);
        $snippets = 0;
        $frames = [];

        foreach (array_slice($locations, 0, 50) as $i => $location) {
            $call = $trace[$i] ?? null;
            $frame = [
                'file' => $this->relative($location['file']),
                'line' => $location['line'],
                'class' => $call['class'] ?? null,
                'function' => isset($call['function']) ? $this->relativeClosure($call['function']) : ($call === null ? '{main}' : null),
            ];

            if ($withSource && $snippets < 15 && $location['file'] !== null && $location['line'] !== null && $this->isApplicationFile($location['file'])) {
                if ($code = $this->snippet($location['file'], (int) $location['line'])) {
                    $frame['code'] = $code;
                    $snippets++;
                }
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    private function isApplicationFile(string $file): bool
    {
        return ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)
            && ! str_contains($file, "eval()'d")
            && ! str_starts_with($file, dirname(__DIR__, 2));
    }

    /** @var array<string, list<string>|false> */
    private array $files = [];

    /** @return array{start_line: int, lines: list<string>}|null ±6 lines around $line */
    private function snippet(string $file, int $line): ?array
    {
        try {
            if (! array_key_exists($file, $this->files)) {
                if (count($this->files) > 50) {
                    $this->files = [];
                }
                $size = @filesize($file);
                $this->files[$file] = $size !== false && $size <= 2 * 1024 * 1024 && is_readable($file)
                    ? (@file($file, FILE_IGNORE_NEW_LINES) ?: false)
                    : false;
            }

            $lines = $this->files[$file];
            if ($lines === false || $line < 1 || $line > count($lines)) {
                return null;
            }

            $start = max(1, $line - 6);
            $slice = array_slice($lines, $start - 1, 13);

            return [
                'start_line' => $start,
                'lines' => array_map(fn ($l) => mb_substr((string) $l, 0, 500), $slice),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Input of the current HTTP request (opt-in), sensitive fields redacted,
     * uploaded files described by name and size only, capped at 32KB.
     *
     * @return array<string, mixed>|null
     */
    private function payload(): ?array
    {
        if (! $this->recorder->config('capture.request_payload', false)) {
            return null;
        }

        $execution = $this->recorder->current();
        if ($execution === null || $execution->type !== 'request' || ! $this->app->bound('request')) {
            return null;
        }

        try {
            $request = $this->app['request'];
            $input = $request->except(array_keys($request->allFiles()));
            $redact = array_map('strtolower', (array) $this->recorder->config('redact_payload_fields', []));

            $clean = function (array $data) use (&$clean, $redact): array {
                foreach ($data as $key => $value) {
                    if (in_array(strtolower((string) $key), $redact, true)) {
                        $data[$key] = '[redacted]';
                    } elseif (is_array($value)) {
                        $data[$key] = $clean($value);
                    }
                }

                return $data;
            };

            $payload = $clean($input);

            $files = [];
            foreach ($request->allFiles() as $key => $file) {
                foreach (is_array($file) ? $file : [$file] as $f) {
                    if (is_object($f) && method_exists($f, 'getClientOriginalName')) {
                        $files[] = ['field' => $key, 'name' => $f->getClientOriginalName(), 'size' => (int) $f->getSize()];
                    }
                }
            }
            if ($files !== []) {
                $payload['_files'] = $files;
            }

            if ($payload === []) {
                return null;
            }

            $json = json_encode($payload, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            return $json !== false && strlen($json) <= 32768
                ? $payload
                : ['_truncated' => true, 'keys' => array_slice(array_map('strval', array_keys($payload)), 0, 100)];
        } catch (Throwable) {
            return null;
        }
    }
}
