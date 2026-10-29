<?php

namespace Laramon\Agent\Watchers;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Laramon\Agent\Support\Clock;

class OutgoingRequestWatcher extends Watcher
{
    /** @var array<int, float> */
    private array $started = [];

    public function register(): void
    {
        $this->listen(RequestSending::class, function (RequestSending $event) {
            if ($this->recorder->current() !== null && count($this->started) < 1000) {
                $this->started[spl_object_id($event->request)] = Clock::now();
            }
        });

        $this->listen(ResponseReceived::class, function (ResponseReceived $event) {
            $response = $event->response;
            $stats = $response->transferStats ?? null;
            $transfer = $stats !== null && method_exists($stats, 'getTransferTime') ? $stats->getTransferTime() : null;

            $size = $response->header('Content-Length');
            if (! is_numeric($size)) {
                $size = $response->toPsrResponse()->getBody()->getSize() ?? 0;
            }

            $this->store($event->request, (int) $response->status(), $transfer, (int) $size);
        });

        $this->listen(ConnectionFailed::class, fn (ConnectionFailed $e) => $this->store($e->request, 0, null, 0));
    }

    private function store(object $request, int $status, ?float $transfer, int $responseSize): void
    {
        $id = spl_object_id($request);
        if ($this->hooks()->rejects('outgoing', strtoupper((string) $request->method()), (string) $request->url(), $request)) {
            unset($this->started[$id]);

            return;
        }

        $now = Clock::now();
        $start = $this->started[$id] ?? ($transfer !== null ? $now - $transfer : $now);
        unset($this->started[$id]);

        $this->recorder->record('outgoing_request', $start, [
            'method' => strtoupper((string) $request->method()),
            'url' => mb_substr((string) $request->url(), 0, 2048),
            'status' => $status,
            'duration' => Clock::us($transfer ?? ($now - $start)),
            'req_size' => strlen((string) $request->body()),
            'res_size' => $responseSize,
        ], 'outgoing');
    }
}
