<?php

namespace Laramon\Agent;

/**
 * Ships records to {url}/api/ingest using raw curl (or PHP streams) so the
 * agent never triggers Laravel HTTP client events or records itself.
 * It never throws into the host application.
 */
class Transport
{
    private const CHUNK = 1000;

    private ?string $lastError = null;

    private int $errorsLogged = 0;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly float $timeout = 2.0,
        private readonly bool $gzip = true,
        private readonly array $env = [],
        private readonly ?\Closure $runtime = null,
    ) {
    }

    private ?array $runtimeSnapshot = null;

    private int $runtimeAttempts = 0;

    /** Runtime snapshot, computed lazily; retried a few times until the DB version is known. */
    private function runtime(): ?array
    {
        if ($this->runtime === null) {
            return null;
        }

        $complete = $this->runtimeSnapshot !== null && ($this->runtimeSnapshot['drivers']['database_version'] ?? null) !== null;
        if (! $complete && $this->runtimeAttempts < 5) {
            $this->runtimeAttempts++;
            try {
                $this->runtimeSnapshot = ($this->runtime)();
            } catch (\Throwable) {
                // keep the previous snapshot
            }
        }

        return $this->runtimeSnapshot;
    }

    public function configured(): bool
    {
        return ! empty($this->url) && ! empty($this->token);
    }

    public function endpoint(): string
    {
        return rtrim((string) $this->url, '/').'/api/ingest';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{status: int, body: string, error: ?string}
     */
    public function send(array $records): array
    {
        $result = ['status' => 0, 'body' => '', 'error' => 'not configured'];

        if (! $this->configured()) {
            return $result;
        }

        try {
            foreach (array_chunk($records, self::CHUNK) ?: [[]] as $chunk) {
                $result = $this->post($this->encode($chunk));
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
    public function encode(array $records): string
    {
        return (string) json_encode(
            ['v' => 1, 'env' => $this->env + ['runtime' => $this->runtime()], 'records' => $records],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @return array{status: int, body: string, error: ?string} */
    private function post(string $json): array
    {
        $headers = [
            'Authorization: Bearer '.$this->token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: laramon-agent/1.0',
        ];

        $body = $json;
        if ($this->gzip && function_exists('gzencode')) {
            $encoded = gzencode($json, 6);
            if ($encoded !== false) {
                $body = $encoded;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint());
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => (int) min(1000, $this->timeout * 1000),
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_NOSIGNAL => true,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = $response === false ? curl_error($ch) : null;
            if (PHP_VERSION_ID < 80500) {
                @curl_close($ch);
            }

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
