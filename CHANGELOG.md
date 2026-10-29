# Changelog

## v0.2.0 — 2026-10-29

Performance release: the agent no longer slows the monitored app.

- **Spooled transport (default).** Records are appended to a local spool after
  the response is sent and shipped by one process per server every 5 s as a
  single gzip batch, instead of one HTTPS request per request / job. Backs off
  after failures, caps the spool at 50 MB and drops rather than block the app.
- **Removed the `laramon:agent` daemon and `laramon:sample`** with all host
  sampling (CPU / memory / process lists / in-flight marker files).
- Runtime info is sent at most hourly per server instead of being rebuilt on
  every request.
- `EXPLAIN` cooldown is shared across processes (APCu or a temp-file stamp);
  under PHP-FPM it previously reset on every request.
- Environment and deploy detection are resolved lazily, on first send.
- New `laramon:flush`, auto-scheduled every minute to ship the spool's tail.
- `LARAMON_TRANSPORT=direct` keeps the old behaviour for local debugging.

## v0.1.0 — 2026-10-29

First standalone release, split out of the Laramon repository.

- Watchers for requests, queries (with EXPLAIN for slow SELECTs), jobs, commands,
  scheduled tasks, exceptions (with source snippets), logs, cache, mail,
  notifications, outgoing HTTP and users.
- `laramon:agent` daemon for CPU / memory / process samples and host info.
- `laramon:status`, `laramon:test` and `laramon:sample` commands.
