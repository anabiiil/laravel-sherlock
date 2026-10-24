# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `watch.enabled` (`TRACING_WATCH_ENABLED`) to never read the cache in the request path, and
  `watch.shared_memo`: on PHP-FPM the watch-window read is shared between workers through APCu.

### Fixed

- The package's own throttled-summary job (`Anabil\Tracing\Notifications\Jobs`) was traced as a unit;
  logs, HTTP calls and reported failures of package jobs could become orphan traces and issues about the
  package itself. Package jobs and `tracing:*` commands are now always ignored and capture is paused while
  package jobs run.
- With a `sync` queue, the AI provider call ran inside the terminating callback of PHP-FPM / Octane
  workers; HTTP processes now skip the AI job on a sync queue (notifications are still sent).
- Requests no route matched were stored and notified under their raw, unredacted path.
- URL-encoded sensitive values in URLs (`email=a%40b.com`, `/users/jane%40example.com`) were kept.
- The Redactor no longer memoizes the column map of huge statements (bounded memory in workers).
- AI code snippets are limited to `.php` files outside `storage/` and `vendor/`.

## [0.1.0] - Unreleased

First release.

### Added

- Recording modes `off`, `local` and `production` (thresholds + `sample_rate`), with zero overhead when off.
- Capture of HTTP requests, queue jobs and artisan commands: queries, outgoing HTTP calls, cache hits /
  misses, logs and exceptions, memory and bootstrap time. Octane / long-running worker safe.
- `tracing:watch` temporary full tracing (cache-backed, auto-expiring, route / user filters) and the
  `X-Tracing` header trigger.
- Analyzers: slow query, N+1, duplicate query, excessive queries, slow request / job / command, high memory,
  slow external call, cache miss rate, exceptions / error logs, missing index.
- Bottleneck verdict per trace (database, application, external service, cache, bootstrap, mixed) with a
  human-readable reason.
- Read-only `EXPLAIN` for MySQL / MariaDB, PostgreSQL (never `ANALYZE`) and SQLite, with strict safety rules
  and index suggestions.
- Database storage (`tracing_*` tables, optional dedicated connection), persistence after the response or
  on a queue, deduplicated issues by fingerprint with recurrence and regression detection.
- Retention with scheduled `tracing:prune`, and `tracing:clear`.
- Notifications to Slack, Telegram and Mail with per-channel minimum severity and per-issue throttling;
  `tracing:test-notification`.
- Optional AI analysis once per issue (Anthropic, OpenAI, Gemini, DeepSeek, Qwen, Groq, OpenRouter, Mistral,
  Ollama, any OpenAI-compatible API, custom drivers via `Tracing::extendAi()`), daily budget, redacting
  decorator; `tracing:analyze`.
- Periodic digest (`digest.every_days`, `digest.at`) from stored data only, no AI calls; `tracing:digest`.
- Blade dashboard (overview, issues, traces, queries) behind the `viewTracing` gate.
- `tracing:install`, `tracing:status` and `tracing:report`.
- Privacy by default: redaction at capture time in every mode, locked sensitive keys, password hash
  detection, no bindings in production lightweight traces, outbound payloads redacted again, and a
  dedicated privacy test suite.
