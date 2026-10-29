# Changelog

## v0.1.0 — 2026-10-29

First standalone release, split out of the Laramon repository.

- Watchers for requests, queries (with EXPLAIN for slow SELECTs), jobs, commands,
  scheduled tasks, exceptions (with source snippets), logs, cache, mail,
  notifications, outgoing HTTP and users.
- `laramon:agent` daemon for CPU / memory / process samples and host info.
- `laramon:status`, `laramon:test` and `laramon:sample` commands.
