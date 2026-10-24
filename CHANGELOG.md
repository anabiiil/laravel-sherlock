# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **Server signals** per trace: CPU time (`getrusage()`), 1-minute load average, CPU cores (lazily, once
  per process) and available memory (persisted traces only); config `server.*`. New verdicts
  `server_load`, `queue_backlog` and `waiting` (blocked: little CPU for the wall time); the signals used are
  stored in `details.verdict.signals`. New `ServerLoadAnalyzer` (`server_load` issues, one per server).
- **Queue wait** on job traces (`queue_wait_ms`, from `available_at` / `pushedAt` / `createdAt` + `delay`) and
  `QueueWaitAnalyzer` (`queue_wait` issues per connection + queue, `thresholds.queue_wait.*`).
- **Worker memory leak detection** (`memory_leak.*`): steady memory growth across consecutive jobs raises a
  `memory_leak` issue with the growth per job class (`MemoryLeakAnalyzer`).
- **Lazy-loading integration** (`lazy_loading.detect`, `SHERLOCK_LAZY_LOADING`; null = local mode only): exact
  `Model::relation` names on N+1 findings (`model`, `relation_source` metrics, `details.lazy_loads`), and
  `Sherlock::recordLazyLoad($model, $relation)` for apps with their own violation handler.
- **Releases**: every trace carries `release` (`SHERLOCK_RELEASE`, else a `.release` / `REVISION` file, else the
  git short SHA; baked in by `config:cache`), issues carry `first_seen_release` / `last_seen_release`, new
  `sherlock_releases` table and `ReleaseComparator` (before / after per entry, sampled traces when there are
  enough of them, `releases.*`).
- **Impact**: issues accumulate `impact_ms`, the time each occurrence cost (`impact.exception_ms` for
  exceptions); `IssueFilter(sort: 'impact')` and `IssueRepository::topByImpact()`.
- Dashboard **Deploys** page: release list and comparison of the latest release with the previous one (or any
  two), with slower / faster / new / gone entries, before → after averages, % / ms / DB / query deltas, a
  "biased" marker when the comparison could not use sampled traces, and new / regressed / gone issues.
- Dashboard: Impact column and sort and a first-seen release filter on Issues; impact, first / last seen
  release, the N+1 relation (exact or guessed), lazy loads, memory leak and server signals on the issue page;
  a server signals panel (CPU vs wall time, load per core, free memory, queue wait), release, lazy-loading
  violations and memory leak on the trace page; a release filter on Traces; "Top issues by impact" and
  "Latest deploy" cards on the Overview.
- `sherlock:report`: "Top issues by impact" and "Latest deploy" sections, `--release=` (compare a release with
  the one before it) and `--compare=after,before`.
- Digest: "Top by impact" and "Since last deploy" (only with at least two releases) sections, from stored
  data only.
- Notifications: `Release` and `Total impact` fields, "First seen in release ..." in new-issue summaries.
- **Test assertions** (`Anabil\Sherlock\Testing`): `InteractsWithSherlock` trait (`assertNoNPlusOne`,
  `assertNoDuplicateQueries`, `assertNoSlowQueries`, `assertQueryCountLessThan`, `assertQueryCount`,
  `assertNoQueryIssues`, `sherlockProbe`) and Pest expectations (`Expectations::register()`:
  `toHaveNoNPlusOne`, `toHaveNoDuplicateQueries`, `toHaveNoSlowQueries`, `toHaveNoQueryIssues`,
  `toRunQueriesLessThan`, `toRunQueries`). Work in every mode, `off` included; N+1 threshold 2 in tests;
  config `testing.*`.
- `benchmarks/capture-overhead.php`: a "100 distinct SQL" scenario and measured backtrace counts.
- `watch.enabled` (`SHERLOCK_WATCH_ENABLED`) to never read the cache in the request path, and
  `watch.shared_memo`: on PHP-FPM the watch-window read is shared between workers through APCu.

### Changed

- **Upgrade:** run `php artisan migrate` (new migration `2026_10_24_000000_add_sherlock_server_signals_and_releases`).
  A published `config/sherlock.php` must add `ServerLoadAnalyzer`, `QueueWaitAnalyzer` and
  `MemoryLeakAnalyzer` to `analyzers` to get the new issue types.
- **Breaking:** renamed the package to **Laravel Sherlock**: `anabil/laravel-tracing` is now `anabiiil/laravel-sherlock`, namespace
  `Anabil\Tracing` is now `Anabil\Sherlock` (`SherlockServiceProvider`, `Sherlock` facade). Config
  `config/sherlock.php`, env vars `SHERLOCK_*`, commands `sherlock:*`, tables `sherlock_*`, publish tags
  `sherlock-config|migrations|views`, view namespace `sherlock::`, route names `sherlock.*`, dashboard path
  `/sherlock`, gate `viewSherlock`, header `X-Sherlock`.
- Lightweight (production) capture takes a query backtrace on the **second** occurrence of a SQL string
  (and for slow / EXPLAIN-eligible queries) instead of the first: a query that runs once costs no backtrace.
  Full capture keeps the first occurrence. N+1 detection counts the unlocated first execution.
- `ai_status` of new / regressed issues reflects what will happen (`skipped_disabled`, `skipped_severity`,
  `pending` only when an analysis job is dispatched, empty when no job runs); a regression that will not be
  re-analyzed keeps its stored analysis.
- Digest and test notifications carry no severity label (`[Sherlock] Digest: Shop`, `[Sherlock] Test notification`).

### Fixed

- The dashboard threw an `UnhandledMatchError` for traces with the new `server_load`, `queue_backlog` and
  `waiting` verdicts (missing badge colors).
- EXPLAIN (missing-index detection) never ran in production for parameterized queries. Queries slow enough
  to be explained now keep their redacted bindings in memory for EXPLAIN only; they are dropped before
  analysis, persistence and queued payloads. Literal values are scrubbed from raw plans before they are
  cached or stored. Bindings that must not be stored are also stripped from queued `PersistTraceJob`
  payloads (`privacy.store_bindings`).
- PHP 8.4 closure names (`{closure:/abs/path/routes/web.php:21}`) leaked absolute paths into issue titles,
  locations and exception frames; they are made relative and titles read `routes/web.php:21 (closure)`.
- `sherlock:test-notification <channel>` names the missing setting when that channel is not configured.
- The package's own throttled-summary job (`Anabil\Sherlock\Notifications\Jobs`) was traced as a unit;
  logs, HTTP calls and reported failures of package jobs could become orphan traces and issues about the
  package itself. Package jobs and `sherlock:*` commands are now always ignored and capture is paused while
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
- `sherlock:watch` temporary full tracing (cache-backed, auto-expiring, route / user filters) and the
  `X-Sherlock` header trigger.
- Analyzers: slow query, N+1, duplicate query, excessive queries, slow request / job / command, high memory,
  slow external call, cache miss rate, exceptions / error logs, missing index.
- Bottleneck verdict per trace (database, application, external service, cache, bootstrap, mixed) with a
  human-readable reason.
- Read-only `EXPLAIN` for MySQL / MariaDB, PostgreSQL (never `ANALYZE`) and SQLite, with strict safety rules
  and index suggestions.
- Database storage (`sherlock_*` tables, optional dedicated connection), persistence after the response or
  on a queue, deduplicated issues by fingerprint with recurrence and regression detection.
- Retention with scheduled `sherlock:prune`, and `sherlock:clear`.
- Notifications to Slack, Telegram and Mail with per-channel minimum severity and per-issue throttling;
  `sherlock:test-notification`.
- Optional AI analysis once per issue (Anthropic, OpenAI, Gemini, DeepSeek, Qwen, Groq, OpenRouter, Mistral,
  Ollama, any OpenAI-compatible API, custom drivers via `Sherlock::extendAi()`), daily budget, redacting
  decorator; `sherlock:analyze`.
- Periodic digest (`digest.every_days`, `digest.at`) from stored data only, no AI calls; `sherlock:digest`.
- Blade dashboard (overview, issues, traces, queries) behind the `viewSherlock` gate.
- `sherlock:install`, `sherlock:status` and `sherlock:report`.
- Privacy by default: redaction at capture time in every mode, locked sensitive keys, password hash
  detection, no bindings in production lightweight traces, outbound payloads redacted again, and a
  dedicated privacy test suite.
