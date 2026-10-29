<?php

namespace Laramon\Agent;

use Laramon\Agent\Support\Spool;
use Laramon\Agent\Support\Stamp;

/**
 * Ships records to Laramon. By default an execution's records are appended to
 * a local spool and shipped in batches (see Spool); in direct mode they are
 * posted straight to {url}/api/ingest. Raw curl is used so the agent never
 * triggers Laravel HTTP client events or records itself. It never throws into
 * the host application.
 */
class Transport
{
    private const CHUNK = 1000;

    private ?string $lastError = null;

    private int $errorsLogged = 0;

    private ?array $resolvedEnv = null;

    /** Reused between posts so a long-running process keeps its HTTPS connection. */
    private mixed $curl = null;

    private ?string $lastRoute = null;

    /**
     * @param  array<string, mixed>|\Closure(): array<string, mixed>  $env
     * @param  string  $mode  spool | direct
     */
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly float $timeout = 2.0,
        private readonly bool $gzip = true,
        private readonly array|\Closure $env = [],
        private readonly ?\Closure $runtime = null,
        private readonly string $mode = 'spool',
        private readonly int $spoolInterval = 5,
    ) {
    }

    public function configured(): bool
    {
        return ! empty($this->url) && ! empty($this->token);
    }

    public function endpoint(): string
    {
        return rtrim((string) $this->url, '/').'/api/ingest';
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** "spool" or "direct": how the last batch left this process. */
    public function lastRoute(): ?string
    {
        return $this->lastRoute;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array<string, mixed> */
    public function env(): array
    {
        if ($this->resolvedEnv === null) {
            try {
                $this->resolvedEnv = $this->env instanceof \Closure ? ($this->env)() : $this->env;
            } catch (\Throwable) {
                $this->resolvedEnv = [];
            }
        }

        return $this->resolvedEnv;
    }

    /** Runtime snapshot; null unless due (at most hourly per server) or forced. */
    public function runtime(bool $force = false): ?array
    {
        if ($this->runtime === null || (! $force && ! Stamp::due('runtime', 3600))) {
            return null;
        }

        try {
            return ($this->runtime)();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{status: int, body: string, error: ?string}
     */
    public function send(array $records): array
    {
        if (! $this->configured()) {
            return ['status' => 0, 'body' => '', 'error' => 'not configured'];
        }

        if ($this->mode === 'direct') {
            return $this->sendDirect($records);
        }

        // Never fall back to a direct post here: a full or unwritable spool
        // means Laramon is unreachable, and the app must not wait on it.
        try {
            $this->lastRoute = 'spool';
            if (! Spool::append($this->encode($records))) {
                $this->report('spool full or not writable ('.Spool::dir().'); records dropped');

                return ['status' => 0, 'body' => '', 'error' => 'spool unavailable'];
            }

            $failedAgo = Stamp::age('spool-failed');
            if (($failedAgo === null || $failedAgo >= 30) && Stamp::due('spool-ship', $this->spoolInterval)) {
                $this->shipSpool();
            }
        } catch (\Throwable $e) {
            $this->report($e->getMessage());
        }

        return ['status' => 202, 'body' => '', 'error' => null];
    }

    /**
     * Ship everything waiting in the local spool as a few compressed batches.
     *
     * @return array{records: int, ok: bool}
     */
    public function shipSpool(): array
    {
        [$files, $payloads] = Spool::claim();
        if ($files === []) {
            return ['records' => 0, 'ok' => true];
        }

        $groups = [];
        foreach ($payloads as $payload) {
            $env = is_array($payload['env'] ?? null) ? $payload['env'] : [];
            unset($env['runtime']);
            $key = md5(json_encode($env) ?: '');
            $groups[$key] ??= ['env' => $env, 'records' => []];
            array_push($groups[$key]['records'], ...$payload['records']);
        }

        $ok = true;
        $count = 0;
        $runtime = $this->runtime();
        foreach ($groups as $group) {
            $result = $this->sendDirect($group['records'], $group['env'], $runtime);
            $runtime = null;
            $count += count($group['records']);
            $ok = $ok && $result['error'] === null && $result['status'] >= 200 && $result['status'] < 300;
            if (! $ok && $result['status'] >= 400 && $result['status'] < 500 && ! in_array($result['status'], [408, 429], true)) {
                // Rejected (bad token): retrying would never succeed.
                $ok = true;
            }
            if (! $ok) {
                break;
            }
        }

        if ($ok) {
            Spool::release($files);
            Stamp::forget('spool-failed');
        } else {
            Spool::restore($files);
            Stamp::touch('spool-failed');
        }

        return ['records' => $count, 'ok' => $ok];
    }

    /**
     * Post straight to Laramon, bypassing the local agent.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<string, mixed>|null  $env  env of the producing process (daemon forwarding)
     * @return array{status: int, body: string, error: ?string}
     */
    public function sendDirect(array $records, ?array $env = null, ?array $runtime = null): array
    {
        $result = ['status' => 0, 'body' => '', 'error' => 'not configured'];

        if (! $this->configured()) {
            return $result;
        }

        $this->lastRoute = 'direct';
        $runtime ??= $env === null ? $this->runtime() : null;

        try {
            foreach (array_chunk($records, self::CHUNK) ?: [[]] as $i => $chunk) {
                $result = $this->post($this->encode($chunk, $i === 0 ? $runtime : null, $env));
                if ($result['error'] !== null || $result['status'] >= 400) {
                    $this->report('HTTP '.$result['status'].' '.($result['error'] ?? substr($result['body'], 0, 200)));
                }
            }
        } catch (\Throwable $e) {
            $result = ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
            $this->report($e->getMessage());
        }

        return $result;
    }

    /** @param  array<int, array<string, mixed>>  $records */
    public function encode(array $records, ?array $runtime = null, ?array $env = null): string
    {
        $env ??= $this->env();
        if ($runtime !== null) {
            $env['runtime'] = $runtime;
        }

        return (string) json_encode(
            ['v' => 1, 'env' => $env, 'records' => $records],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @return array{0: string, 1: list<string>} body and headers of a POST to /api/ingest */
    public function request(string $json, int $level = 1): array
    {
        $headers = [
            'Authorization: Bearer '.$this->token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: laramon-agent/0.2',
        ];

        if ($this->gzip && function_exists('gzencode')) {
            $encoded = gzencode($json, $level);
            if ($encoded !== false) {
                $headers[] = 'Content-Encoding: gzip';

                return [$encoded, $headers];
            }
        }

        return [$json, $headers];
    }

    /** @return array{status: int, body: string, error: ?string} */
    private function post(string $json): array
    {
        [$body, $headers] = $this->request($json);

        if (function_exists('curl_init')) {
            $this->curl ??= curl_init();
            $ch = $this->curl;
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->endpoint(),
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int) min(1000, $this->timeout * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_NOSIGNAL => true,
                CURLOPT_TCP_KEEPALIVE => 1,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = $response === false ? curl_error($ch) : null;

            return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($this->endpoint(), false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $response === false ? 'request failed' : null];
    }

    private function report(string $message): void
    {
        $this->lastError = $message;

        // At most a few lines per process so a down server never floods logs.
        if ($this->errorsLogged++ < 3) {
            @error_log('[laramon] ingest failed: '.$message);
        }
    }
}
