<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Laramon\Agent\Execution;
use Laramon\Agent\Recorder;
use Laramon\Agent\Support\Clock;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Times a request through its lifecycle phases. Driven by the global
 * RecordRequest middleware plus router events.
 */
class RequestWatcher extends Watcher
{
    private ?Execution $execution = null;

    private ?Request $request = null;

    private ?Response $response = null;

    private bool $seenRequest = false;

    public function __construct(Application $app, Recorder $recorder, private readonly int $bootCpu)
    {
        parent::__construct($app, $recorder);
    }

    public function register(): void
    {
        $this->listen(RouteMatched::class, function (RouteMatched $event) {
            $execution = $this->execution;
            if ($execution === null || $execution->finished || isset($execution->marks['route'])) {
                return;
            }

            $route = $event->route;
            $path = '/'.ltrim($route->uri(), '/');

            $execution->marks['route'] = Clock::now();
            $execution->name = strtoupper($event->request->getMethod()).' '.$path;
            $execution->state['route_name'] = $route->getName();
            $execution->state['route_path'] = $path;
            $execution->state['route_action'] = $route->getActionName();

            $this->recorder->markInflight($execution);
        });

        $this->listen(PreparingResponse::class, function () {
            $execution = $this->execution;
            if ($execution !== null && isset($execution->marks['route']) && ! isset($execution->marks['preparing'])) {
                $execution->marks['preparing'] = Clock::now();
            }
        });

        $this->listen(ResponsePrepared::class, function () {
            $execution = $this->execution;
            if ($execution !== null && isset($execution->marks['preparing']) && ! isset($execution->marks['prepared'])) {
                $execution->marks['prepared'] = Clock::now();
            }
        });
    }

    public function start(Request $request): void
    {
        $now = Clock::now();

        // A previous request that never terminated (tests, sub requests).
        if ($this->execution !== null && ! $this->execution->finished) {
            $this->recorder->end($this->execution);
        }

        $first = ! $this->seenRequest;
        $this->seenRequest = true;

        $start = $now;
        $cpu = Clock::cpu();
        if ($first) {
            $origin = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) ($request->server('REQUEST_TIME_FLOAT') ?? $now);
            if ($origin <= $now && $now - $origin < 60) {
                $start = $origin;
                $cpu = $this->bootCpu;
            }
        } elseif (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage(); // Octane / long running servers.
        }

        $sampled = ! $this->matches(ltrim($request->path(), '/') ?: '/', (array) $this->recorder->config('ignore.paths', []))
            && $this->recorder->sample('requests');

        $execution = Execution::start('request', $sampled, null, $start, $cpu);
        $execution->state['method'] = $request->getMethod();
        $execution->marks['middleware'] = $now;

        $this->execution = $execution;
        $this->request = $request;
        $this->response = null;

        $this->recorder->begin($execution);
    }

    public function handled(Response $response): void
    {
        if ($this->execution === null || $this->execution->finished) {
            return;
        }

        $this->execution->marks['after'] ??= Clock::now();
        $this->response = $response;
    }

    public function terminating(): void
    {
        $execution = $this->execution;
        if ($execution === null || $execution->finished) {
            return;
        }

        $execution->marks['sent'] = Clock::now();

        // Registered now so it runs after the application's own terminating callbacks.
        $this->app->terminating(fn () => $this->finish());
    }

    /** Called on PHP shutdown for requests that never terminated (fatal errors). */
    public function abandon(): void
    {
        if ($this->execution !== null && ! $this->execution->finished) {
            $this->finish(500);
        }
    }

    public function finish(?int $forcedStatus = null): void
    {
        $execution = $this->execution;
        if ($execution === null || $execution->finished) {
            return;
        }

        try {
            $end = Clock::now();
            $execution->marks['end'] = $end;

            $this->resolveUser($execution);

            $request = $this->request;
            $response = $this->response;
            $status = $forcedStatus ?? ($response?->getStatusCode() ?? 500);

            $fields = [
                'method' => $execution->state['method'],
                'url' => $request ? substr($request->fullUrl(), 0, 2048) : null,
                'route_name' => $execution->state['route_name'] ?? null,
                'route_path' => $execution->state['route_path'] ?? null,
                'route_action' => $execution->state['route_action'] ?? null,
                'ip' => $request?->ip(),
                'status' => $status,
                'duration' => Clock::us($end - $execution->startedAt),
                'cpu' => max(0, Clock::cpu() - $execution->cpuStart),
                'memory' => memory_get_peak_usage(true),
                'req_size' => $request ? (int) ($request->server('CONTENT_LENGTH') ?: 0) : 0,
                'res_size' => $this->responseSize($response),
                'phases' => $this->phases($execution),
                'counts' => $execution->counts,
                'headers' => $request && $this->recorder->config('capture.headers', true) ? $this->headers($request) : null,
            ];
            if ($context = $this->laravelContext()) {
                $fields['context'] = $context;
            }

            $this->recorder->recordExecution($execution, 'request', $this->hooks()->redact('requests', $fields));
        } finally {
            $this->recorder->end($execution);
            $this->recorder->flush();
            $this->request = null;
            $this->response = null;
        }
    }

    private function resolveUser(Execution $execution): void
    {
        if ($execution->userId !== null || ! $this->app->resolved('auth')) {
            return;
        }

        try {
            $guard = $this->app['auth']->guard();
            if ($guard->hasUser()) {
                $this->recorder->identify($execution, $guard->user());
            }
        } catch (\Throwable) {
        }
    }

    /** @return array<string, int> */
    private function phases(Execution $execution): array
    {
        $m = $execution->marks;
        $start = $execution->startedAt;
        $middleware = $m['middleware'];
        $after = $m['after'] ?? $m['sent'] ?? $m['end'];
        $route = $m['route'] ?? null;
        $preparing = $m['preparing'] ?? null;
        $prepared = $m['prepared'] ?? null;

        $phases = [
            'bootstrap' => Clock::us($middleware - $start),
            'before_middleware' => Clock::us(($route ?? $after) - $middleware),
        ];

        if ($route !== null) {
            $phases['action'] = Clock::us(($preparing ?? $after) - $route);
        }

        if ($preparing !== null && $prepared !== null) {
            $phases['render'] = Clock::us($prepared - $preparing);
        }

        $phases['after_middleware'] = Clock::us($after - ($prepared ?? $preparing ?? $route ?? $after));

        if (isset($m['sent'])) {
            $phases['sending'] = Clock::us($m['sent'] - $after);
            $phases['terminating'] = Clock::us($m['end'] - $m['sent']);
        }

        return $phases;
    }

    private function responseSize(?Response $response): int
    {
        if ($response === null) {
            return 0;
        }

        $length = $response->headers->get('Content-Length');
        if (is_numeric($length)) {
            return (int) $length;
        }

        if ($response instanceof StreamedResponse) {
            return 0;
        }

        if ($response instanceof BinaryFileResponse) {
            return (int) ($response->getFile()->getSize() ?: 0);
        }

        $content = $response->getContent();

        return is_string($content) ? strlen($content) : 0;
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $redact = array_map('strtolower', (array) $this->recorder->config('redact_headers', []));
        $out = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            $out[$name] = in_array($name, $redact, true) ? '[redacted]' : substr(implode(', ', (array) $values), 0, 1024);
        }

        return $out;
    }
}
