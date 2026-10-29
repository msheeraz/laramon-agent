<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Laramon\Agent\Recorder;
use Laramon\Agent\Support\Clock;
use Throwable;

class LogWatcher extends Watcher
{
    private const LEVELS = [
        'emergency' => 0, 'alert' => 1, 'critical' => 2, 'error' => 3,
        'warning' => 4, 'notice' => 5, 'info' => 6, 'debug' => 7,
    ];

    public function __construct(Application $app, Recorder $recorder, private readonly ExceptionWatcher $exceptions)
    {
        parent::__construct($app, $recorder);
    }

    public function register(): void
    {
        $minimum = self::LEVELS[strtolower((string) $this->recorder->config('log_level', 'debug'))] ?? 7;
        $captureLogs = (bool) $this->recorder->config('capture.logs', true);

        $this->listen(MessageLogged::class, function (MessageLogged $event) use ($minimum, $captureLogs) {
            $message = is_string($event->message) ? $event->message : (string) json_encode($event->message);
            if (str_starts_with($message, '[laramon]')) {
                return;
            }

            $context = is_array($event->context) ? $event->context : [];

            // Fallback exception capture (e.g. a reportable callback stopped propagation).
            if (($context['exception'] ?? null) instanceof Throwable) {
                $this->exceptions->capture($context['exception']);
            }

            $level = strtolower((string) $event->level);
            if (! $captureLogs || (self::LEVELS[$level] ?? 7) > $minimum) {
                return;
            }

            $this->recorder->record('log', Clock::now(), [
                'level' => $level,
                'message' => mb_substr($message, 0, 10000),
                'context' => $this->context($context),
            ], 'logs', always: true);
        });
    }

    /** @return array<string, mixed> */
    private function context(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = $this->normalize($value, 0);
        }

        $json = json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json !== false && strlen($json) > 16384 ? ['_truncated' => substr($json, 0, 16384)] : $out;
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($value instanceof Throwable) {
            return ['class' => $value::class, 'message' => mb_substr($value->getMessage(), 0, 2000), 'file' => $this->relative($value->getFile()), 'line' => $value->getLine()];
        }

        if (is_array($value)) {
            if ($depth >= 3) {
                return '[array('.count($value).')]';
            }

            $out = [];
            foreach (array_slice($value, 0, 50, true) as $k => $v) {
                $out[$k] = $this->normalize($v, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable && $depth < 2) {
                return $this->normalize($value->jsonSerialize(), $depth + 1);
            }

            return method_exists($value, '__toString') ? mb_substr((string) $value, 0, 2000) : '['.$value::class.']';
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }

        return is_resource($value) ? '[resource]' : $value;
    }
}
