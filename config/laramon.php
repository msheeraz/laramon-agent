<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laramon agent
    |--------------------------------------------------------------------------
    |
    | The agent records telemetry for every request, job, command and
    | scheduled task and ships it to your Laramon server. Recording is
    | disabled automatically when no URL or token is configured.
    |
    */

    'enabled' => (bool) env('LARAMON_ENABLED', true),

    'url' => env('LARAMON_URL'),

    'token' => env('LARAMON_TOKEN'),

    // Name reported for this machine / container in host samples and payloads.
    'server' => env('LARAMON_SERVER', gethostname() ?: 'unknown'),

    // Release identifier. When empty it is detected from Laravel Cloud /
    // Forge / Vapor variables, then from the git commit of the checkout.
    'deploy' => env('LARAMON_DEPLOY'),

    /*
    | Sample rates (0.0 - 1.0) decided once per execution. Child records
    | (queries, cache, mail...) follow their parent. Exceptions are always
    | recorded regardless of sampling.
    */
    'sample_rates' => [
        'requests' => (float) env('LARAMON_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => (float) env('LARAMON_COMMAND_SAMPLE_RATE', 1.0),
        'jobs' => (float) env('LARAMON_JOB_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => (float) env('LARAMON_SCHEDULED_TASK_SAMPLE_RATE', env('LARAMON_SCHEDULE_SAMPLE_RATE', 1.0)),
    ],

    'ignore' => [
        // Request paths (Str::is patterns, without leading slash).
        'paths' => ['up', 'telescope*', 'horizon*', '_debugbar*', 'pulse*', '_ignition*', 'favicon.ico'],

        // Artisan commands (Str::is patterns). Long-running workers are
        // excluded because each job / task they run is recorded separately.
        'commands' => [
            'queue:work', 'queue:listen', 'horizon*', 'schedule:run', 'schedule:work',
            'schedule:finish', 'octane:*', 'reverb:start', 'serve', 'tinker', 'pail',
            'laramon:*', 'package:discover', 'list', 'help',
        ],

        // Cache keys (Str::is patterns) that are framework noise.
        'cache_keys' => ['illuminate:queue:restart', 'illuminate:schedule:*', 'illuminate:foundation:down', 'laravel_cache_lock:*'],

        // Jobs (Str::is patterns on the display name).
        'jobs' => [],
    ],

    'capture' => [
        // LARAMON_IGNORE_* is the inverse switch (Nightwatch style naming).
        'queries' => (bool) env('LARAMON_CAPTURE_QUERIES', ! env('LARAMON_IGNORE_QUERIES', false)),
        'cache' => (bool) env('LARAMON_CAPTURE_CACHE', ! env('LARAMON_IGNORE_CACHE', env('LARAMON_IGNORE_CACHE_EVENTS', false))),
        'logs' => (bool) env('LARAMON_CAPTURE_LOGS', true),
        'outgoing' => (bool) env('LARAMON_CAPTURE_OUTGOING', ! env('LARAMON_IGNORE_OUTGOING_REQUESTS', false)),
        'mail' => (bool) env('LARAMON_CAPTURE_MAIL', ! env('LARAMON_IGNORE_MAIL', false)),
        'notifications' => (bool) env('LARAMON_CAPTURE_NOTIFICATIONS', ! env('LARAMON_IGNORE_NOTIFICATIONS', false)),
        'headers' => (bool) env('LARAMON_CAPTURE_HEADERS', true),

        // Source code (±6 lines) around application frames of exceptions.
        'source_code' => (bool) env('LARAMON_CAPTURE_SOURCE_CODE', true),

        // Request input sent with unhandled exceptions (off by default).
        'request_payload' => (bool) env('LARAMON_CAPTURE_REQUEST_PAYLOAD', false),

        // Laravel Context::all() attached to requests, jobs and commands.
        'context' => (bool) env('LARAMON_CAPTURE_CONTEXT', true),
    ],

    // Request payload fields replaced with "[redacted]" (case-insensitive, any depth).
    'redact_payload_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'LARAMON_REDACT_PAYLOAD_FIELDS',
        '_token,password,password_confirmation,current_password,card_number,cvv',
    ))))),

    // Capture the query plan (EXPLAIN) of slow SELECT queries so Laramon can
    // point at full scans / unused indexes. Runs at most once per query shape
    // per cooldown, on the same connection, and never raises into the app.
    'explain' => [
        'enabled' => (bool) env('LARAMON_EXPLAIN', true),
        'threshold_ms' => (float) env('LARAMON_EXPLAIN_THRESHOLD_MS', 100),
        'cooldown' => (int) env('LARAMON_EXPLAIN_COOLDOWN', 600),
    ],

    'redact_headers' => ['authorization', 'cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'php-auth-pw', 'proxy-authorization'],

    // Minimum log level recorded (debug, info, notice, warning, error, critical, alert, emergency).
    'log_level' => env('LARAMON_LOG_LEVEL', 'debug'),

    // Flush early when this many records are buffered (long jobs / commands).
    'buffer_limit' => (int) env('LARAMON_BUFFER_LIMIT', 500),

    /*
    | How records leave the app:
    |   spool  - after the response is sent, append the execution's records to
    |            a local spool file (one small write); at most every
    |            `spool_interval` seconds one process ships the spool to Laramon
    |            as a single compressed batch. Requests never wait on Laramon.
    |   direct - post every execution straight to Laramon (debugging only).
    */
    'transport' => env('LARAMON_TRANSPORT', 'spool'),

    // Seconds between spool shipments (per server).
    'spool_interval' => (int) env('LARAMON_SPOOL_INTERVAL', 5),

    // HTTP timeout in seconds when shipping to Laramon.
    'timeout' => (float) env('LARAMON_TIMEOUT', 2),

    'gzip' => (bool) env('LARAMON_GZIP', true),
];
