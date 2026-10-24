# Laravel Tracing

Self-hosted **performance diagnosis** for Laravel 11, 12 and 13 (PHP 8.2+; Laravel 13 needs PHP 8.3+).

Laravel Tracing does more than record what happened. For every request, queue job and artisan command it
**finds the problem, decides where the time went, explains why and suggests a fix**: slow queries, N+1,
duplicate queries, missing indexes (via a safe `EXPLAIN`), slow external calls, high memory, cache misses,
exceptions. It groups them into deduplicated **issues**, can notify you on Slack / Telegram / Mail, and can
ask an AI model (any provider, including free and local ones) for a root-cause analysis **once per issue**.

Everything stays in your own database. Sensitive data is redacted **at capture time, in every mode**, and
passwords (plain or hashed) are never stored, not even locally.

> Package name `anabil/laravel-tracing`, namespace `Anabil\Tracing` (working names).

---

## Contents

- [How it differs from Telescope, Debugbar and Pulse](#how-it-differs-from-telescope-debugbar-and-pulse)
- [Installation](#installation)
- [Recording modes](#recording-modes)
- [Temporary full tracing: `tracing:watch`](#temporary-full-tracing-tracingwatch)
- [Header trigger](#header-trigger)
- [What is detected](#what-is-detected)
- [Verdicts: where did the time go?](#verdicts-where-did-the-time-go)
- [EXPLAIN and its safety rules](#explain-and-its-safety-rules)
- [Issues](#issues)
- [Storage and a dedicated connection](#storage-and-a-dedicated-connection)
- [Retention](#retention)
- [Notifications](#notifications)
- [AI analysis](#ai-analysis)
- [Digest](#digest)
- [Privacy guarantees](#privacy-guarantees)
- [Dashboard](#dashboard)
- [Artisan commands](#artisan-commands)
- [Production checklist](#production-checklist)
- [Extending](#extending)
- [Testing](#testing)
- [License](#license)

---

## How it differs from Telescope, Debugbar and Pulse

| | Telescope | Debugbar | Pulse | **Laravel Tracing** |
|---|---|---|---|---|
| Main job | Record everything for debugging | Inspect the current page | Aggregate app health metrics | **Diagnose performance problems** |
| Production use | Not recommended | No | Yes | **Yes**: thresholds + sampling, persistence after the response or on a queue |
| Detects N+1, slow / duplicate queries | No (you read the list) | Partly, per page | Slow queries only | **Yes, with severity and source location** |
| Missing indexes | No | No | No | **Yes, via read-only `EXPLAIN`** |
| Bottleneck verdict per trace | No | No | No | **Database / Application / External / Cache / Bootstrap** |
| Deduplicated issues, regressions | No | No | No | **Yes, by fingerprint** |
| Alerts | No | No | No | **Slack, Telegram, Mail, throttled** |
| AI root-cause analysis | No | No | No | **Optional, once per issue, any provider** |
| Redaction at capture time | Partial (hidden request fields) | No | n/a | **Always, every mode; passwords and hashes never stored** |

Use Telescope or Debugbar to look at raw details while developing, Pulse for a health overview. Use
Laravel Tracing when you want to be told *what is slow, why, and what to do about it*, including in
production.

---

## Installation

```bash
composer require anabil/laravel-tracing
php artisan tracing:install
```

`tracing:install` publishes `config/tracing.php` and the migration, asks whether to run the migrations
(use `--migrate` to skip the question, `--force` to overwrite an already published config) and prints
the next steps. Re-running it never publishes the migration twice.

Manual equivalent:

```bash
php artisan vendor:publish --tag=tracing-config
php artisan vendor:publish --tag=tracing-migrations
php artisan migrate
```

The dashboard views can be published with `--tag=tracing-views` if you want to customize them.

Then make sure the **scheduler** runs (`php artisan schedule:work` or a cron entry for `schedule:run`):
retention pruning and the digest are scheduled automatically.

---

## Recording modes

```dotenv
TRACING_MODE=production   # off | local | production (default: production)
```

| Mode | What happens |
|---|---|
| `off` | Nothing is registered: no listeners, no middleware, no terminating callback. Zero overhead. |
| `local` | Every request, job and command is fully recorded (queries with redacted bindings, source locations). For dev machines. |
| `production` | Lightweight in-memory metrics for every unit. A trace is **persisted only** when a finding reaches `persist_min_severity`, an exception / error log happens, or the trace is **sampled**. |

Production knobs:

```dotenv
TRACING_SAMPLE_RATE=0.05              # 5% of healthy traces stored as a baseline (0.0 - 1.0)
TRACING_PERSIST_MIN_SEVERITY=warning  # info | warning | critical
```

In production, query bindings are not stored for lightweight traces (see `privacy.store_bindings`), only
normalized SQL and fingerprints.

### What is captured

`config('tracing.capture')` switches units and signals on or off: `requests`, `jobs`, `commands`,
`queries`, `http_client`, `cache`, `logs`, `exceptions`, the minimum `log_level` (default `error`),
`source_location` / `backtrace_limit`, and per-trace hard caps (`max_queries`, `max_http_calls`,
`max_logs`, `max_stack_frames`) that bound memory in long-running workers.

`config('tracing.ignore')` lists paths, route names, job classes, artisan commands, SQL patterns and HTTP
methods that are never traced (`Str::is()` wildcards). The package's own queries and dashboard routes are
never recorded; its jobs (`Anabil\Tracing\Jobs\*`) and `tracing:*` commands are in the default ignore lists.

### Thresholds

All thresholds live in `config('tracing.thresholds')`. The most common ones have env overrides:

| Threshold | Key | Default |
|---|---|---|
| Slow query | `thresholds.slow_query.warning_ms` / `critical_ms` | 100 ms (`TRACING_SLOW_QUERY_MS`) / 1000 ms |
| N+1 (same query + same source location in one trace) | `thresholds.n_plus_one.warning_count` / `critical_count` | 5 (`TRACING_N_PLUS_ONE_THRESHOLD`) / 20 |
| Duplicate query (same SQL + same bindings) | `thresholds.duplicate_query.min_count` | 2 |
| Excessive queries per trace | `thresholds.excessive_queries.warning_count` / `critical_count` | 50 (`TRACING_MAX_QUERIES`) / 200 |
| Slow request / job / command | `thresholds.entries.{request,job,command}` | 1 s / 3 s, 10 s / 60 s, 30 s / 300 s |
| High memory | `thresholds.memory.warning_mb` / `critical_mb` | 128 MB (`TRACING_MEMORY_MB`) / 512 MB |
| Slow outgoing HTTP call | `thresholds.slow_http.warning_ms` / `critical_ms` | 1000 ms (`TRACING_SLOW_HTTP_MS`) / 5000 ms |
| Cache miss rate | `thresholds.cache_miss_rate` | 50% info / 80% warning, with at least 10 lookups |
| Missing index (rows examined) | `thresholds.missing_index.warning_rows` / `critical_rows` | 1,000 / 100,000 |
| Log levels to severity | `thresholds.log_levels` | `error` = warning; `critical`, `alert`, `emergency` = critical |

---

## Temporary full tracing: `tracing:watch`

Need full detail in production for a while? Open a watch window:

```bash
php artisan tracing:watch --minutes=30                      # everything, for 30 minutes
php artisan tracing:watch --minutes=15 --route="api/orders*" # only matching paths / route names
php artisan tracing:watch --user=15                         # only this authenticated user
php artisan tracing:status                                  # is a window active? how long is left?
php artisan tracing:watch --stop                            # end it early
```

The window is stored in the cache and **expires on its own**. Use a shared store (Redis) so every server
sees it: `TRACING_WATCH_STORE=redis`. Defaults: `watch.default_minutes` 30, `watch.max_minutes` 1440.
Long-running workers re-read the window at most every `watch.memo_seconds` (5) seconds.

---

## Header trigger

Fully trace a single request by sending a secret header:

```dotenv
TRACING_HEADER_SECRET=a-long-random-string
```

```bash
curl -H "X-Tracing: a-long-random-string" https://example.com/api/orders
```

The secret is compared with `hash_equals()`. The trigger is disabled while the secret is empty or when
`TRACING_HEADER_ENABLED=false`. The header name is `header.name` (default `X-Tracing`).

---

## What is detected

Each analyzer is one class (configured in `config('tracing.analyzers')`; remove any or add your own):

| Analyzer | Finds |
|---|---|
| `SlowQueryAnalyzer` | Queries above the slow-query threshold, aggregated per fingerprint and location |
| `NPlusOneAnalyzer` | The same query fingerprint repeated from the same source location in one trace |
| `DuplicateQueryAnalyzer` | Identical SQL with identical bindings repeated |
| `ExcessiveQueriesAnalyzer` | Too many queries in one trace |
| `SlowEntryAnalyzer` | Slow requests, jobs and commands (per-type thresholds) |
| `HighMemoryAnalyzer` | High peak memory |
| `SlowExternalCallAnalyzer` | Slow outgoing HTTP calls (Laravel HTTP client) |
| `CacheMissRateAnalyzer` | A high cache miss ratio |
| `ExceptionAnalyzer` | Exceptions and `Log::error()` (and above), reported immediately |
| `MissingIndexAnalyzer` | Full scans, filesort, temporary tables or no usable key in the `EXPLAIN` plan of slow queries |

Every finding has a severity (`info`, `warning`, `critical`), the first application source location
(`app/Http/...:42`, relative paths only), normalized SQL when relevant and a recommendation.

---

## Verdicts: where did the time go?

Each trace gets a **verdict**: total time is split into database, external HTTP, cache, bootstrap and
application (the remainder), then classified with ratio rules from `config('tracing.bottleneck')`:

| Verdict | Default rule (share of total time) |
|---|---|
| `database` | database ≥ 50% |
| `external_service` | external HTTP ≥ 40% |
| `cache` | cache ≥ 30% |
| `bootstrap` | bootstrap ≥ 40% |
| `application` | application ≥ 60% |
| `mixed` | none of the above |
| `unknown` | trace shorter than `bottleneck.min_duration_ms` (50 ms) |

The verdict comes with a human-readable reason such as
*"72% of 2.4s spent in 38 queries; top query 900ms on `orders`"*.

---

## EXPLAIN and its safety rules

`MissingIndexAnalyzer` relies on `EXPLAIN` (MySQL / MariaDB `FORMAT=JSON`, PostgreSQL `FORMAT JSON`,
SQLite `EXPLAIN QUERY PLAN`). It is designed so that a plan may be skipped, but never cause a side effect:

- only a single read-only `SELECT` (or `WITH ... SELECT`); anything with `;`, `INTO`, locking clauses,
  data-modifying statements or side-effect / sleep / lock / file functions is rejected;
- PostgreSQL never uses `ANALYZE` (the query is never executed);
- only for slow-query fingerprints, at most once per fingerprint per `explain.cache_minutes` (1440) and at
  most `explain.max_per_trace` (3) per trace;
- a statement timeout (`explain.timeout_ms`, 1000) where the driver supports it;
- runs after the response, never while the user waits;
- bindings must exist for every placeholder: values are never invented;
- can be limited to some environments (`TRACING_EXPLAIN_ENVIRONMENTS=staging,production`) or disabled
  (`TRACING_EXPLAIN=false`).

---

## Issues

Findings are grouped into **issues** by fingerprint: `type + query fingerprint + source location + entry
name` (route / job / command). One issue is created per unique problem, then:

- **new**: first occurrence, notified (and analyzed by AI, if enabled) once;
- **recurring**: occurrences are counted; notifications are throttled;
- **regressed**: a resolved issue that occurs again is reopened and notified again;
- **ignored**: occurrences are still counted, nothing is notified.

Severity only escalates. Each issue keeps up to `storage.issue_traces_cap` (10) recent example traces.

---

## Storage and a dedicated connection

Tables (prefix `storage.table_prefix`, default `tracing_`): `tracing_traces`, `tracing_queries`,
`tracing_issues`, `tracing_issue_traces`. No foreign keys, so they can live on another connection.

```dotenv
TRACING_DB_CONNECTION=tracing     # a connection from config/database.php; empty = app default
TRACING_PERSISTENCE=queue         # after_response (default) | queue
```

- `after_response` writes in the terminating callback, after the response has been sent.
- `queue` dispatches a `PersistTraceJob` (recommended in production with a real queue).
- At most `storage.max_queries_per_trace` (200) query rows are stored per trace (the slowest of each
  fingerprint first).

Set `TRACING_DB_CONNECTION` **before** running the migration: it creates the tables on that connection.

---

## Retention

```php
'retention' => [
    'traces' => 7,          // days; null = keep forever
    'issues' => 90,         // days since last seen; null = keep forever
    'schedule' => true,     // register tracing:prune in the scheduler
    'schedule_at' => '03:00',
    'chunk_size' => 1000,   // rows per delete chunk
],
```

`tracing:prune` deletes in short chunks and runs daily at `schedule_at`. For manual cleanup:

```bash
php artisan tracing:clear                          # everything (asks for confirmation)
php artisan tracing:clear --only=traces --older-than=30
php artisan tracing:clear --only=issues --force    # no confirmation
```

---

## Notifications

Channels: **Slack** (incoming webhook), **Telegram** (Bot API) and **Mail**, in any combination. Delivery is
queued; one failing channel never blocks the others; every outgoing payload is redacted again.

```dotenv
TRACING_NOTIFICATIONS=true
TRACING_NOTIFICATION_CHANNELS=slack,telegram,mail
TRACING_NOTIFY_MIN_SEVERITY=warning   # global minimum; a channel may raise it, never lower it
TRACING_THROTTLE_MINUTES=60
TRACING_DASHBOARD_URL=https://admin.example.com   # base URL for dashboard links; empty = app.url
```

What triggers a notification is set in `notifications.notify_on` (`new`, `regressed`, `exceptions`, all
`true` by default). Messages contain the title, severity, verdict, location, key metrics, a dashboard link
and the AI recommendation when one exists. Webhook / Bot API calls time out after `notifications.timeout`
(10) seconds.

Check your setup at any time:

```bash
php artisan tracing:test-notification            # every configured channel
php artisan tracing:test-notification telegram   # one channel
```

### Slack

1. Go to <https://api.slack.com/apps>, **Create New App** > *From scratch*, pick your workspace.
2. Open **Incoming Webhooks**, switch it on, click **Add New Webhook to Workspace** and choose a channel.
3. Copy the webhook URL:

```dotenv
TRACING_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T000/B000/XXXX
TRACING_SLACK_MIN_SEVERITY=warning
TRACING_SLACK_CHANNEL=            # optional override, if your webhook allows it
```

### Telegram

1. In Telegram, talk to [@BotFather](https://t.me/BotFather), send `/newbot` and follow the prompts. Copy
   the **bot token** (`123456789:AA...`).
2. Start a chat with your new bot and send it any message (or add the bot to a group and post a message
   there).
3. Open `https://api.telegram.org/bot<TOKEN>/getUpdates` in a browser and find `"chat":{"id": ...}`.
   That number is your **chat_id** (group ids are negative, often starting with `-100`).
4. Configure:

```dotenv
TRACING_TELEGRAM_BOT_TOKEN=123456789:AAExampleToken
TRACING_TELEGRAM_CHAT_ID=-1001234567890
TRACING_TELEGRAM_MIN_SEVERITY=warning
```

The API base URL is `notifications.telegram.api_url` (default `https://api.telegram.org`).

### Mail

Uses your Laravel mail configuration.

```dotenv
TRACING_MAIL_TO=ops@example.com,dev@example.com
TRACING_MAIL_MIN_SEVERITY=critical   # default: only critical issues by mail
TRACING_MAILER=                      # mailer name; empty = default mailer
```

### Throttling

The first occurrence of an issue is sent **immediately**. Repeats are counted silently, then summarized
once per `throttle_minutes` window (*"occurred 340 times in the last hour"*). Exceptions and error logs
are sent immediately as well.

---

## AI analysis

Optional. When enabled, an issue that qualifies (`ai.analyze`, `ai.min_severity`) is analyzed **once per
issue fingerprint**; the result is stored on the issue and reused by notifications, the digest and the
dashboard. The AI job runs before the notification job and never throws: a failure or timeout is recorded
as `failed` and the notification is sent anyway.

```dotenv
TRACING_AI_ENABLED=true
TRACING_AI_DRIVER=anthropic   # see the drivers below
TRACING_AI_KEY=...
TRACING_AI_MODEL=             # empty = the driver's default
TRACING_AI_BASE_URL=          # empty = the driver's default
TRACING_AI_LANGUAGE=en        # language of the analysis
TRACING_AI_MIN_SEVERITY=critical
TRACING_AI_DAILY_LIMIT=50
```

The prompt contains normalized SQL (placeholders only), the EXPLAIN plan, related table columns / indexes
(`ai.include_schema`, never row data), metrics, the verdict and the source location. Source snippets are
**opt-in** (`ai.send_code_snippet`, default `false`; `ai.snippet_lines` 15) and redacted. The answer is
requested as *Root cause / Fix / Code example / Expected impact*.

Other keys: `ai.analyze.performance` (`true`), `ai.analyze.exceptions` (`false`), `ai.max_tokens` (1500),
`ai.timeout` (30 s), `ai.connect_timeout` (10 s), `ai.budget_store` (cache store for the budget counter).

### Drivers

Defaults come from `ai.drivers.<name>`. The top-level `model` / `base_url` / `api_key` override them for the
selected driver only.

| Driver | Default model | Example |
|---|---|---|
| `anthropic` | `claude-sonnet-5-5` | `TRACING_AI_DRIVER=anthropic` `TRACING_AI_KEY=sk-ant-...` |
| `openai` | `gpt-4o-mini` | `TRACING_AI_DRIVER=openai` `TRACING_AI_KEY=sk-...` |
| `gemini` | `gemini-2.0-flash` | `TRACING_AI_DRIVER=gemini` `TRACING_AI_KEY=AIza...` (free tier available) |
| `deepseek` | `deepseek-chat` | `TRACING_AI_DRIVER=deepseek` `TRACING_AI_KEY=sk-...` |
| `qwen` | `qwen-plus` | `TRACING_AI_DRIVER=qwen` `TRACING_AI_KEY=sk-...` (Alibaba DashScope, international endpoint) |
| `groq` | `llama-3.3-70b-versatile` | `TRACING_AI_DRIVER=groq` `TRACING_AI_KEY=gsk_...` (free tier available) |
| `openrouter` | `meta-llama/llama-3.3-70b-instruct:free` | `TRACING_AI_DRIVER=openrouter` `TRACING_AI_KEY=sk-or-...` |
| `mistral` | `mistral-small-latest` | `TRACING_AI_DRIVER=mistral` `TRACING_AI_KEY=...` |
| `ollama` | `qwen2.5-coder` | local, no key; see below |
| `openai_compatible` | none | any OpenAI-compatible API; `TRACING_AI_BASE_URL` and `TRACING_AI_MODEL` required |
| `null` | none | disables calls |

**Free models with OpenRouter**: any model id ending in `:free` works, the default is one of them.

```dotenv
TRACING_AI_DRIVER=openrouter
TRACING_AI_KEY=sk-or-v1-...
TRACING_AI_MODEL=meta-llama/llama-3.3-70b-instruct:free
```

OpenRouter attribution headers can be set in `ai.drivers.openrouter.referer` / `title`.

**Local with Ollama** (nothing leaves your network):

```bash
ollama pull qwen2.5-coder
```

```dotenv
TRACING_AI_ENABLED=true
TRACING_AI_DRIVER=ollama
TRACING_OLLAMA_URL=http://localhost:11434
TRACING_AI_MODEL=qwen2.5-coder
```

**Any OpenAI-compatible server** (LM Studio, vLLM, a gateway):

```dotenv
TRACING_AI_DRIVER=openai_compatible
TRACING_AI_BASE_URL=http://localhost:1234/v1
TRACING_AI_MODEL=my-model
TRACING_AI_KEY=optional
```

### Cost control

- **Once per issue**: repeats, digests and the dashboard reuse the stored analysis.
- Re-analysis happens only on demand (dashboard *Re-analyze* button, `tracing:analyze {issue} --fresh`) or
  when a resolved issue **regresses**.
- **Daily budget**: `ai.daily_limit` calls per day (app timezone). Over budget, the issue is marked
  `skipped_budget`. `0` means no calls at all (fail-safe), `-1` means unlimited. Today's usage is shown by
  `tracing:status`.
- `ai.min_severity` (default `critical`) and `ai.analyze` keep cheap issues away from the model.
- The digest **never** calls the AI.

---

## Digest

An optional periodic summary sent through the notification channels:

```dotenv
TRACING_DIGEST=true
TRACING_DIGEST_EVERY_DAYS=1     # 1 = daily, 7 = weekly
TRACING_DIGEST_AT=09:00         # time of the daily check (scheduler timezone)
TRACING_DIGEST_CHANNELS=mail    # comma separated; empty = every enabled notification channel
```

It contains the period summary (traces recorded, open issues by severity), new issues, regressed issues,
the most frequent issues (each with an excerpt of its **stored** AI recommendation), the slowest routes /
jobs / commands, the top query fingerprints (normalized SQL) and the bottleneck distribution. Each section
holds at most `digest.limit` (10) items. Ignored issues are left out.

How it is scheduled: a daily check at `digest.at` (registered only when the digest is enabled) queues a
`SendDigestJob` when at least `every_days` calendar days have passed since the last digest. The period
covered is "since the last digest" (or the last `every_days` days for the first one). The last-sent time is
kept in the cache without expiry (`digest.cache_store`, `TRACING_DIGEST_STORE`; default store); if the cache
is flushed, the next check simply sends a digest.

The digest goes through the regular notification dispatcher, so notifications must be enabled
(`TRACING_NOTIFICATIONS=true`) and the channels configured.

```bash
php artisan tracing:digest          # send now if enabled and due
php artisan tracing:digest --force  # send now regardless
```

---

## Privacy guarantees

Redaction happens **at capture time, before anything is stored, in every mode, including `local`**.

- **Sensitive keys are locked**: `password`, `passwd`, `pwd`, `secret`, `token`, `api_key`, `apikey`,
  `access_token`, `refresh_token`, `remember_token`, `private_key`, `client_secret`, `authorization`,
  `credit_card`, `card_number`, `cvv`, `cvc`, `pin`, `otp`, `ssn`, and any key **containing** them. You can
  add more (`privacy.sensitive_keys`), never remove these.
- **Query bindings** bound to a sensitive column become `[REDACTED]`. **Password hashes** (bcrypt, argon,
  ...) are detected by shape and removed whatever the column name.
- **In production, bindings are not stored** for lightweight traces (`privacy.store_bindings`: `auto`
  (default), `always` or `never`).
- **Exception messages and log context** (recursively): emails, phone numbers, JWT / bearer tokens, card
  numbers (Luhn-checked), IP addresses and long secrets are masked; sensitive keys removed.
- **Never stored or sent**: request bodies, headers (`Authorization`, `Cookie`, `X-Api-Key`...), cookies,
  session, `.env`, config.
- **Stack traces**: relative paths only, no argument values.
- **Outbound payloads** (AI, Slack, Telegram, Mail) are redacted **again** by decorators, whatever the
  driver or channel, including custom ones.
- **Code snippets** for the AI are opt-in, off by default, and redacted.
- Extra patterns: `privacy.patterns` (regexes replaced with `[REDACTED]`).
- A dedicated privacy test suite registers a user with a known password, changes it, triggers exceptions
  and logs containing it, and scans every `tracing_*` table and every outgoing payload for the password and
  its hash.

---

## Dashboard

Served at `/tracing` (`TRACING_PATH`, optional `TRACING_DOMAIN`), with the `web` middleware
(`dashboard.middleware`) and the **`viewTracing`** gate. Disable it with `TRACING_DASHBOARD=false`.

Pages: **Overview** (counts, bottleneck split, recent critical issues), **Issues** (filters; detail with
verdict, metrics, example traces, EXPLAIN insights, AI analysis and resolve / ignore / reopen / re-analyze
actions), **Traces** (list and timeline breakdown) and **Queries** (top fingerprints). Server-rendered Blade
with Tailwind and Alpine from a CDN; no build step. `dashboard.per_page` defaults to 25.

By default the gate allows the **`local` environment only**. Grant access elsewhere in
`App\Providers\AppServiceProvider::boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewTracing', function ($user = null) {
    return in_array($user?->email, ['you@example.com'], true);
});
```

---

## Artisan commands

| Command | Purpose |
|---|---|
| `tracing:install {--force} {--migrate}` | Publish config and migrations, optionally migrate, print next steps |
| `tracing:watch {--minutes=30} {--route=} {--user=} {--stop}` | Temporary full tracing |
| `tracing:status` | Mode, sampling, header trigger, watch window, channels, AI driver, today's AI budget |
| `tracing:report {--days=7} {--type=} {--limit=10}` | Terminal summary: top open issues, slowest entries, top queries, bottleneck distribution. `--type` (`request`, `job`, `command`) filters the trace count and slowest entries |
| `tracing:analyze {issue} {--fresh}` | Run (or show) the AI analysis of an issue |
| `tracing:prune` | Retention cleanup (scheduled daily) |
| `tracing:clear {--older-than=} {--only=traces\|issues} {--force}` | Manual cleanup |
| `tracing:digest {--force}` | Send the digest now |
| `tracing:test-notification {channel?}` | Verify notification channels |

---

## Production checklist

- [ ] `TRACING_MODE=production` (the default) with a modest `TRACING_SAMPLE_RATE` (`0.01` to `0.05`).
- [ ] A **real queue** (Redis, database, SQS) with a worker, and `TRACING_PERSISTENCE=queue`. Notifications,
      AI analysis and the digest are queued too. Optionally isolate them: `TRACING_QUEUE=tracing`,
      `TRACING_QUEUE_CONNECTION=redis`.
- [ ] A **dedicated connection** (`TRACING_DB_CONNECTION`) if your main database is busy, set before migrating.
- [ ] The scheduler runs (`tracing:prune`, digest), and `retention` fits your disk.
- [ ] A shared cache (Redis) for `tracing:watch` across servers (`TRACING_WATCH_STORE`).
- [ ] The `viewTracing` gate is defined for the people who need the dashboard.
- [ ] `TRACING_HEADER_SECRET` is long and random, or the header trigger is disabled.
- [ ] Notifications tested with `tracing:test-notification`; AI budget set (`TRACING_AI_DAILY_LIMIT`).
- [ ] `EXPLAIN` restricted if needed (`TRACING_EXPLAIN_ENVIRONMENTS`) or disabled (`TRACING_EXPLAIN=false`).

**Octane, Horizon and long-running workers** are supported: trace state is scoped to each request / job /
command and reset in between; per-trace caps (`capture.max_*`) bound memory; the watch window is
re-read at most every `watch.memo_seconds`. `queue:work`, `horizon*`, `octane:*` and the scheduler commands
themselves are ignored as units (`ignore.commands`); the jobs they run are traced individually.

---

## Extending

### Custom analyzers

Implement `Anabil\Tracing\Contracts\Analyzer` and add the class to `config('tracing.analyzers')`:

```php
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;

final class TooManyHttpCallsAnalyzer implements Analyzer
{
    public function __construct(private readonly Fingerprinter $fingerprints) {}

    public function analyze(TraceData $trace): array
    {
        if ($trace->httpCount() < 10) {
            return [];
        }

        return [new Finding(
            IssueType::SlowExternalCall,
            Severity::Warning,
            "{$trace->httpCount()} HTTP calls in {$trace->name}",
            'Batch the calls or cache their results.',
            $this->fingerprints->issue(IssueType::SlowExternalCall, entryName: $trace->name, extra: 'many-calls'),
            ['count' => $trace->httpCount()],
        )];
    }
}
```

Analyzers run after the response, on queries that are already normalized, fingerprinted and redacted.

### Custom AI drivers

```php
use Anabil\Tracing\Facades\Tracing;

Tracing::extendAi('my-llm', fn ($app, array $config) => new MyLlmDriver($config));
```

A driver implements `Anabil\Tracing\Contracts\AiProvider` (`name()`, `complete(AiRequest): AiResponse`).
Select it with `TRACING_AI_DRIVER=my-llm`. Custom drivers are wrapped by the redacting decorator as well.

### Events

| Event | When |
|---|---|
| `Anabil\Tracing\Events\TraceRecorded` | A trace was stored (`traceId`, `trace`) |
| `Anabil\Tracing\Events\IssueDetected` | A finding created a new issue (`outcome`) |
| `Anabil\Tracing\Events\IssueRecurred` | An open issue occurred again (`outcome`) |
| `Anabil\Tracing\Events\IssueRegressed` | A resolved issue came back (`outcome`) |

### Other extension points

Every service is bound to a contract in `src/Contracts` (repositories, notification dispatcher, AI
analyzer, digest service, recording policy, redactor, ...) and can be rebound in your own service provider.
Other user-facing helpers on the `Tracing` facade: `Tracing::tag('tenant', 'acme')` (attach a redacted tag
to the current trace) and `Tracing::pause(fn () => ...)` (run code without recording it).

---

## Testing

In your application's test suite you will usually want tracing off:

```xml
<env name="TRACING_MODE" value="off"/>
```

Developing the package itself (Pest + Orchestra Testbench on MySQL):

```bash
composer install
mysql -e "CREATE DATABASE tracing_test"
DB_DATABASE=tracing_test vendor/bin/pest    # DB_HOST / DB_USERNAME / DB_PASSWORD are read from env
vendor/bin/phpstan analyse                  # level 8
vendor/bin/pint
```

The suite includes unit, feature, architecture and the mandatory privacy tests. See
[CONTRIBUTING.md](CONTRIBUTING.md).

---

## License

MIT. See [LICENSE](LICENSE).
