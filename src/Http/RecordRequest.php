<?php

namespace Laramon\Agent\Http;

use Closure;
use Illuminate\Http\Request;
use Laramon\Agent\Watchers\RequestWatcher;
use Symfony\Component\HttpFoundation\Response;

/** Global middleware (prepended) that opens and closes the request execution. */
class RecordRequest
{
    public function __construct(private readonly RequestWatcher $watcher)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->watcher->start($request);
        } catch (\Throwable) {
        }

        $response = $next($request);

        try {
            if ($response instanceof Response) {
                $this->watcher->handled($response);
            }
        } catch (\Throwable) {
        }

        return $response;
    }

    public function terminate(Request $request, mixed $response): void
    {
        try {
            if ($response instanceof Response) {
                $this->watcher->handled($response);
            }
            $this->watcher->terminating();
        } catch (\Throwable) {
        }
    }
}
