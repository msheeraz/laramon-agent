# laramon/agent

The Laramon agent records what your Laravel application does — requests, queries,
jobs, commands, scheduled tasks, exceptions, logs, cache, mail, notifications,
outgoing HTTP calls — and ships it to your self-hosted Laramon server in
batches, without a daemon and with about 0.3 ms of overhead per request.

Supports PHP 8.2+ and Laravel 11, 12 and 13.

## Install

Add the package to the monitored app as a Composer repository. Pick one:

**Local folder** (apps on the same machine, for development):

```bash
composer config repositories.laramon-agent path /Users/641/Documents/Dev/websites/laramon-agent
```

**Git** (servers / Laravel Cloud):

```bash
composer config repositories.laramon-agent vcs https://github.com/msheeraz/laramon-agent.git
```

Then install:

```bash
composer require laramon/agent
php artisan vendor:publish --tag=laramon-config   # optional
```

Create a project in Laramon (**Projects → New project**) and copy its token:

```dotenv
LARAMON_URL=https://laramon.example.com
LARAMON_TOKEN=lm_xxxxxxxxxxxxxxxx
```

Verify:

```bash
php artisan laramon:status   # config + connectivity + token check
php artisan laramon:test     # sends a test request, log and exception
php artisan laramon:flush    # ship the local spool now
```

Nothing is recorded while `LARAMON_URL` / `LARAMON_TOKEN` are empty, when
`LARAMON_ENABLED=false`, or while running the test suite (set
`LARAMON_RECORD_TESTS=true` to override).

## How data leaves your app (performance)

The agent is built so production traffic never waits on Laramon:

1. While a request / job / command runs, records are kept in memory.
2. After the response is sent (PHP-FPM `fastcgi_finish_request`), the
   execution's records are appended to a local spool file — one small write,
   about **+0.3 ms** per request.
3. At most every `LARAMON_SPOOL_INTERVAL` seconds (default 5) **one** process per
   server claims the spool and ships it to Laramon as a single gzip batch.
   Failed shipments back off for 30 s; if Laramon is unreachable for long, the
   spool is capped at 50 MB and new records are dropped instead of slowing the app.
4. When traffic stops, the scheduler ships what is left every minute (it registers
   `laramon:flush` automatically; on Laravel Cloud just enable the scheduler).

No daemon or background process is needed. Other per-request costs are bounded:
runtime info (PHP / Laravel / drivers) is sent at most hourly per server, and
`EXPLAIN` for slow queries runs at most once per query shape per 10 minutes per
server (shared through APCu or a small file in the temp dir).

The spool lives in `{sys_get_temp_dir()}/laramon` (override with `LARAMON_STATE_PATH`).
`LARAMON_TRANSPORT=direct` posts every execution straight to Laramon — useful
locally for debugging, never in production.

## What is captured

| Record | Source |
|---|---|
| request | global middleware + router events: phases (bootstrap, middleware, action, render, sending, terminating), status, duration, CPU time, peak memory, sizes, route, user, redacted headers |
| query | `QueryExecuted` (SQL with placeholders, never bindings), connection, duration, calling file:line |
| exception | exception handler `reportable()` + log fallback; handled (`report()`/`rescue()`) vs unhandled, 50 frames (throw site first) with ±6 lines of source for application frames; optional request payload on unhandled exceptions |
| job_queued / job_attempt | queue events; attempt status, duration, CPU, peak memory and memory growth per job (leak detection) |
| command | `CommandStarting` / `CommandFinished`, exit code, duration, CPU, memory |
| scheduled_task | schedule events: processed / skipped / failed, duration, CPU, memory |
| cache | hit / miss / write / delete / failure with durations (Laravel 11+) |
| outgoing_request | HTTP client events, status, duration, sizes |
| mail / notification | mailable / notification class, channel, recipients count, failures |
| log | `MessageLogged` at or above `LARAMON_LOG_LEVEL` |
| user | authenticated user id / name / email, once per execution; queued jobs carry the id of the user who dispatched them |
| context | `Context::all()` (Laravel 11+) attached to requests, job attempts and commands (64KB cap) |

Durations are microseconds. Data is buffered per execution and spooled after the
response is sent, after each job / command, when the buffer reaches
`LARAMON_BUFFER_LIMIT`, and on shutdown. Shipping uses raw curl with a short
timeout (`LARAMON_TIMEOUT`, default 2s) and never throws into your application.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `LARAMON_URL` / `LARAMON_TOKEN` | — | Laramon server and project token (recording is off without both) |
| `LARAMON_ENABLED` | `true` | Master switch |
| `LARAMON_SERVER` | `gethostname()` | Server / instance name sent with every payload |
| `LARAMON_DEPLOY` | auto | Release id. Auto: `LARAVEL_CLOUD_DEPLOYMENT_ID`, Forge / Vapor variables, then the git commit (`.git/HEAD`) |
| `LARAMON_REQUEST_SAMPLE_RATE` | `1` | Share of requests recorded (children follow) |
| `LARAMON_JOB_SAMPLE_RATE` | `1` | Share of job attempts recorded |
| `LARAMON_COMMAND_SAMPLE_RATE` | `1` | Share of Artisan commands recorded |
| `LARAMON_SCHEDULED_TASK_SAMPLE_RATE` | `1` | Share of scheduled task runs recorded (`LARAMON_SCHEDULE_SAMPLE_RATE` still works) |
| `LARAMON_IGNORE_QUERIES` | `false` | Drop all queries (inverse of `LARAMON_CAPTURE_QUERIES`) |
| `LARAMON_IGNORE_CACHE` | `false` | Drop all cache events (`LARAMON_IGNORE_CACHE_EVENTS` alias) |
| `LARAMON_IGNORE_MAIL` | `false` | Drop all mail |
| `LARAMON_IGNORE_NOTIFICATIONS` | `false` | Drop all notifications |
| `LARAMON_IGNORE_OUTGOING_REQUESTS` | `false` | Drop all outgoing HTTP requests |
| `LARAMON_CAPTURE_HEADERS` | `true` | Request headers (sensitive ones redacted) |
| `LARAMON_CAPTURE_SOURCE_CODE` | `true` | Source snippets for application stack frames |
| `LARAMON_CAPTURE_REQUEST_PAYLOAD` | `false` | Request input on unhandled exceptions (files: name + size only, 32KB cap) |
| `LARAMON_REDACT_PAYLOAD_FIELDS` | `_token,password,password_confirmation,current_password,card_number,cvv` | Payload keys replaced by `[redacted]` at any depth |
| `LARAMON_CAPTURE_CONTEXT` | `true` | Attach Laravel `Context::all()` |
| `LARAMON_EXPLAIN` | `true` | Capture `EXPLAIN` for slow `SELECT`s |
| `LARAMON_EXPLAIN_THRESHOLD_MS` | `100` | Minimum query duration to explain |
| `LARAMON_EXPLAIN_COOLDOWN` | `600` | Seconds before the same query shape is explained again (per process) |
| `LARAMON_LOG_LEVEL` | `debug` | Minimum log level recorded |
| `LARAMON_BUFFER_LIMIT` | `500` | Flush early after this many buffered records |
| `LARAMON_TIMEOUT` | `2` | Seconds per ingest request |
| `LARAMON_TRANSPORT` | `spool` | `spool` (batched, recommended) or `direct` (post every execution; debugging) |
| `LARAMON_SPOOL_INTERVAL` | `5` | Seconds between spool shipments per server |
| `LARAMON_STATE_PATH` | temp dir | Where the spool and throttle stamps live |

## Query plans (EXPLAIN)

For `SELECT`/`WITH` queries slower than `LARAMON_EXPLAIN_THRESHOLD_MS`, the agent
runs `EXPLAIN` (MySQL/MariaDB), `EXPLAIN (FORMAT JSON)` (PostgreSQL) or
`EXPLAIN QUERY PLAN` (SQLite) once per query shape per cooldown, on the same
connection with the same bindings, and attaches the plan to that query record.
Laramon uses it to point at full table scans, unused indexes and filesorts.
The explain itself is never recorded and errors are swallowed. Disable with
`LARAMON_EXPLAIN=false` (e.g. for replicas where you can't run `EXPLAIN`).

## Filtering, redaction and pausing

```php
use Laramon\Agent\Facades\Laramon;

// Drop everything a block of code produces
$report = Laramon::ignore(fn () => $exporter->run());

// Or around code that can't use a callback
Laramon::pause();
// ...
Laramon::resume();

// AppServiceProvider::boot()
Laramon::rejectQueries(fn (string $sql, string $connection) => str_contains($sql, 'telescope_'))
    ->rejectCacheKeys(fn (string $key, string $kind, string $store) => str_starts_with($key, 'throttle:'))
    ->rejectMail(fn (array $mail) => $mail['mailable'] === \App\Mail\Heartbeat::class)
    ->rejectNotifications(fn ($notification, string $channel, $notifiable) => $channel === 'broadcast')
    ->rejectOutgoingRequests(fn (string $method, string $url) => str_contains($url, 'health'))
    ->rejectJobs(fn (string $name) => $name === \App\Jobs\Ping::class);

Laramon::redactQueries(fn (string $sql) => preg_replace("/'[^']*@[^']*'/", "'[email]'", $sql))
    ->redactExceptions(fn (string $message) => preg_replace('/\d{12,19}/', '[card]', $message))
    ->redactRequests(function (array $request) {
        $request['url'] = preg_replace('/token=[^&]+/', 'token=[redacted]', (string) $request['url']);
        $request['ip'] = null;

        return $request;
    })
    ->redactMailSubjects(fn (string $subject) => 'redacted')
    ->redactCacheKeys(fn (string $key) => preg_replace('/user:\d+/', 'user:*', $key))
    ->redactCommands(fn (string $line) => preg_replace('/--password=\S+/', '--password=***', $line));
```

Rejectors return `true` to drop a record; redactors return the transformed
value (a callback that throws or returns a different type is ignored).
Exceptions are never dropped by sampling.

## Sampling & filtering

```dotenv
LARAMON_REQUEST_SAMPLE_RATE=0.25   # keep 25% of requests (and their queries, cache...)
LARAMON_JOB_SAMPLE_RATE=1
LARAMON_COMMAND_SAMPLE_RATE=1
LARAMON_SCHEDULED_TASK_SAMPLE_RATE=1
LARAMON_LOG_LEVEL=info
LARAMON_CAPTURE_QUERIES=true
LARAMON_CAPTURE_CACHE=true
LARAMON_CAPTURE_HEADERS=true
```

Exceptions are always recorded. Ignored paths, commands, jobs and cache keys are
configurable in `config/laramon.php` (`ignore.*`).
