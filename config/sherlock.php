<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Recording mode
    |--------------------------------------------------------------------------
    |
    | off        - nothing is registered: no listeners, no middleware, zero overhead.
    | local      - full recording of every request / job / command.
    | production - lightweight in-memory metrics for every unit; a trace is persisted
    |              only when a threshold is crossed (see "persist_min_severity"), an
    |              exception happens, or the trace is sampled (see "sample_rate").
    |
    | The safe default is "production". Set SHERLOCK_MODE=local on dev machines.
    |
    */

    'mode' => env('SHERLOCK_MODE', 'production'),

    // Fraction (0.0 - 1.0) of non-problematic units persisted in production as a baseline.
    'sample_rate' => (float) env('SHERLOCK_SAMPLE_RATE', 0.05),

    // In production, a trace is persisted when at least one finding reaches this severity
    // (info|warning|critical). Exceptions / log errors are always persisted.
    'persist_min_severity' => env('SHERLOCK_PERSIST_MIN_SEVERITY', 'warning'),

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    |
    | Release / deploy identifier stamped on every trace and issue (first / last seen
    | release), the base of before / after deploy comparisons. SHERLOCK_RELEASE wins;
    | otherwise it is detected from a ".release" / "REVISION" file or the git HEAD (short
    | SHA) of the application, without shelling out. Run "php artisan config:cache" on
    | deploy: the value is then baked into the cached config (no file read per request).
    |
    */

    'release' => Anabil\Sherlock\Support\ReleaseDetector::normalize(env('SHERLOCK_RELEASE'))
        ?? Anabil\Sherlock\Support\ReleaseDetector::detect(function_exists('base_path') ? base_path() : null),

    /*
    |--------------------------------------------------------------------------
    | Server signals
    |--------------------------------------------------------------------------
    |
    | Answers "is it the query or the server?". Per unit: CPU time (getrusage() at start
    | and end) and the 1-minute load average (sys_getloadavg() when the unit ends). The
    | core count is resolved once per process, only when needed: "cpu_cores", else Linux
    | /proc/cpuinfo. Available memory (Linux /proc/meminfo) is read only for persisted
    | traces. Never shells out.
    |
    */

    'server' => [
        'cpu_time' => env('SHERLOCK_SERVER_CPU_TIME', true),
        'load_average' => env('SHERLOCK_SERVER_LOAD', true),
        'memory' => env('SHERLOCK_SERVER_MEMORY', true),
        // Cores of the server; null = detected (Linux). Set it on macOS / containers with CPU limits.
        'cpu_cores' => env('SHERLOCK_CPU_CORES'),
        // Load average per core: ServerLoadAnalyzer finding on slow entries + "server_load" verdict.
        'load_per_core_warning' => 1.5,
        'load_per_core_critical' => 3.0,
        // Application segment classification: CPU time / application time >= cpu_bound_ratio is
        // CPU-bound code; <= waiting_ratio is waiting / blocked (locks, sleep, I/O, slow disk).
        'cpu_bound_ratio' => 0.7,
        'waiting_ratio' => 0.3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Capture
    |--------------------------------------------------------------------------
    |
    | Which units and signals are collected. Every captured value goes through the
    | Redactor at capture time. Limits cap per-trace memory in long-running workers.
    |
    */

    'capture' => [
        'requests' => env('SHERLOCK_CAPTURE_REQUESTS', true),
        'jobs' => env('SHERLOCK_CAPTURE_JOBS', true),
        'commands' => env('SHERLOCK_CAPTURE_COMMANDS', true),

        'queries' => true,
        'http_client' => true,
        'cache' => true,
        'logs' => true,
        'exceptions' => true,

        // Minimum log level captured (PSR-3 name). "error" covers error, critical, alert, emergency.
        'log_level' => env('SHERLOCK_LOG_LEVEL', 'error'),

        // Source location (backtrace) capture, with DEBUG_BACKTRACE_IGNORE_ARGS: once per SQL
        // string per trace (first occurrence in full capture, second in production lightweight
        // capture: a query that runs once is not located) and for every slow query.
        'source_location' => true,
        'backtrace_limit' => 50,

        // Hard caps per trace; extra records are counted but not kept.
        'max_queries' => 1000,
        'max_http_calls' => 200,
        'max_logs' => 100,
        'max_stack_frames' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds (per analyzer / entry type)
    |--------------------------------------------------------------------------
    */

    'thresholds' => [
        'slow_query' => [
            'warning_ms' => (int) env('SHERLOCK_SLOW_QUERY_MS', 100),
            'critical_ms' => 1000,
        ],

        // Same fingerprint + same source location repeated in one trace.
        'n_plus_one' => [
            'warning_count' => (int) env('SHERLOCK_N_PLUS_ONE_THRESHOLD', 5),
            'critical_count' => 20,
        ],

        // Identical SQL + identical bindings repeated at least this many times.
        'duplicate_query' => [
            'min_count' => 2,
        ],

        // Total query count per trace.
        'excessive_queries' => [
            'warning_count' => (int) env('SHERLOCK_MAX_QUERIES', 50),
            'critical_count' => 200,
        ],

        // Duration per entry type.
        'entries' => [
            'request' => ['warning_ms' => 1000, 'critical_ms' => 3000],
            'job' => ['warning_ms' => 10000, 'critical_ms' => 60000],
            'command' => ['warning_ms' => 30000, 'critical_ms' => 300000],
        ],

        // Peak memory per unit.
        'memory' => [
            'warning_mb' => (int) env('SHERLOCK_MEMORY_MB', 128),
            'critical_mb' => 512,
        ],

        // Outgoing HTTP call duration.
        'slow_http' => [
            'warning_ms' => (int) env('SHERLOCK_SLOW_HTTP_MS', 1000),
            'critical_ms' => 5000,
        ],

        // Cache miss ratio, evaluated only with at least "min_lookups" lookups.
        'cache_miss_rate' => [
            'min_lookups' => 10,
            'info_ratio' => 0.5,
            'warning_ratio' => 0.8,
        ],

        // EXPLAIN-based detection on slow SELECT queries.
        'missing_index' => [
            'warning_rows' => 1000,
            'critical_rows' => 100000,
        ],

        // Queue jobs: time between becoming available (push + delay) and starting.
        'queue_wait' => [
            'warning_ms' => (int) env('SHERLOCK_QUEUE_WAIT_MS', 30000),
            'critical_ms' => 300000,
        ],

        // Severity of captured log records per PSR-3 level (exceptions are always critical).
        // Levels not listed here never raise a log_error finding.
        'log_levels' => [
            'error' => 'warning',
            'critical' => 'critical',
            'alert' => 'critical',
            'emergency' => 'critical',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyzers
    |--------------------------------------------------------------------------
    |
    | Analyzer pipeline (Strategy). Each class implements
    | Anabil\Sherlock\Contracts\Analyzer. Add your own or remove any.
    |
    */

    'analyzers' => [
        'Anabil\Sherlock\Analysis\Analyzers\SlowQueryAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\NPlusOneAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\DuplicateQueryAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\ExcessiveQueriesAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\SlowEntryAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\HighMemoryAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\SlowExternalCallAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\CacheMissRateAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\ExceptionAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\MissingIndexAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\ServerLoadAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\QueueWaitAnalyzer',
        'Anabil\Sherlock\Analysis\Analyzers\MemoryLeakAnalyzer',
    ],

    /*
    |--------------------------------------------------------------------------
    | Lazy-loading violations (exact N+1 relation names)
    |--------------------------------------------------------------------------
    |
    | When enabled and the app does not use Model::preventLazyLoading() itself, Sherlock
    | enables it with a handler that records "Model::relation" in the trace and never
    | throws (the relation still loads). null = enabled in local mode only. Apps with their
    | own handler can call Sherlock::recordLazyLoad($model, $relation) from it.
    |
    */

    'lazy_loading' => [
        'detect' => env('SHERLOCK_LAZY_LOADING'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory leaks in queue workers
    |--------------------------------------------------------------------------
    |
    | After each job a worker records memory_get_usage() in a bounded ring buffer. Growth
    | of "growth_mb" over the baseline (taken after "warmup_jobs"), steady across at least
    | "min_jobs" jobs, raises a memory_leak finding on the job that crossed it (once per
    | worker process per growth_mb step). Zero cost outside queue workers.
    |
    */

    'memory_leak' => [
        'enabled' => env('SHERLOCK_MEMORY_LEAK', true),
        'growth_mb' => 32,
        'critical_mb' => 128,
        'min_jobs' => 20,
        'warmup_jobs' => 5,
        'buffer' => 50,
        // Share of the buffered jobs that must have grown the memory (steady growth).
        'min_growing_ratio' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Impact
    |--------------------------------------------------------------------------
    |
    | Issues accumulate impact_ms: the time each occurrence cost (N+1: the repeated
    | queries, slow query: its time, slow entry: the excess over the threshold, excessive
    | queries: db time, external call: its duration, queue wait: the wait). Exceptions
    | count "exception_ms" each.
    |
    */

    'impact' => [
        'exception_ms' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Release comparison
    |--------------------------------------------------------------------------
    |
    | In production only sampled / threshold traces are stored, so averages of all stored
    | traces are biased towards slow ones. Comparisons use "sample" traces when both
    | releases have at least "compare_min_samples" of them for an entry.
    |
    */

    'releases' => [
        'compare_min_samples' => 20,
        // An entry is slower / faster when its average changes by this ratio and by delta_ms.
        'compare_min_change' => 0.1,
        'compare_min_delta_ms' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bottleneck classification
    |--------------------------------------------------------------------------
    |
    | Share of total duration (0.0 - 1.0) a segment needs to be declared the
    | bottleneck. Checked in this order; otherwise "mixed" (or "unknown" for
    | traces shorter than "min_duration_ms").
    |
    */

    'bottleneck' => [
        'min_duration_ms' => 50,
        'database' => 0.5,
        'external_service' => 0.4,
        'cache' => 0.3,
        'bootstrap' => 0.4,
        'application' => 0.6,
    ],

    /*
    |--------------------------------------------------------------------------
    | EXPLAIN
    |--------------------------------------------------------------------------
    |
    | Runs only on SELECT queries of slow-query fingerprints, read-only, at most
    | once per fingerprint per "cache_minutes". PostgreSQL never uses ANALYZE.
    |
    */

    'explain' => [
        'enabled' => env('SHERLOCK_EXPLAIN', true),
        'only_select' => true,
        // Minimum query time to explain; null = thresholds.slow_query.warning_ms. In production,
        // such queries keep their redacted bindings in memory until EXPLAIN ran (never stored).
        'min_query_ms' => null,
        'cache_minutes' => 1440,
        // Cache store for the "already explained" markers; null = default store.
        'cache_store' => null,
        'max_per_trace' => 3,
        // Statement timeout for EXPLAIN where the driver supports it (MySQL hint,
        // MariaDB max_statement_time, PostgreSQL statement_timeout).
        'timeout_ms' => 1000,
        // Environments where EXPLAIN may run (array or comma separated); null = all.
        'environments' => env('SHERLOCK_EXPLAIN_ENVIRONMENTS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Watch window (php artisan sherlock:watch)
    |--------------------------------------------------------------------------
    |
    | Temporary full tracing stored in cache. Use a shared store (redis) so all
    | servers see it. Expires automatically.
    |
    | In production the window is checked when a unit starts (before the response):
    | one cache "get" per memo_seconds per process. PHP-FPM rebuilds the app on every
    | request, so the read is shared across FPM workers through APCu when the extension
    | is enabled ("shared_memo"); without APCu it is one cache get per request. Set
    | "enabled" to false to never read the cache in the request path (sherlock:watch
    | then has no effect).
    |
    */

    'watch' => [
        'enabled' => env('SHERLOCK_WATCH_ENABLED', true),
        // Share the last read between the PHP-FPM workers of a server (APCu, when available).
        'shared_memo' => true,
        'cache_store' => env('SHERLOCK_WATCH_STORE'),
        'cache_key' => 'sherlock:watch',
        'default_minutes' => 30,
        'max_minutes' => 1440,
        // Seconds a worker process (Octane / queue / long commands) reuses the last cache
        // read before looking again. Keeps the check at one cache "get" per few seconds.
        'memo_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Header trigger
    |--------------------------------------------------------------------------
    |
    | A request carrying "<name>: <secret>" is fully traced. Compared with
    | hash_equals(). Disabled while the secret is empty.
    |
    */

    'header' => [
        'enabled' => env('SHERLOCK_HEADER_ENABLED', true),
        'name' => 'X-Sherlock',
        'secret' => env('SHERLOCK_HEADER_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore lists
    |--------------------------------------------------------------------------
    |
    | Patterns use Str::is() wildcards. Ignored units register no trace at all.
    |
    */

    'ignore' => [
        'paths' => [
            'sherlock*',
            'horizon*',
            'telescope*',
            'pulse*',
            '_debugbar*',
            '_ignition*',
            'livewire/livewire.js*',
            'up',
            'favicon.ico',
        ],

        // Route names.
        'routes' => [],

        // Job class names.
        'jobs' => [
            'Anabil\Sherlock\Jobs\*',
        ],

        // Artisan command names.
        'commands' => [
            'queue:work',
            'queue:listen',
            'horizon*',
            'schedule:run',
            'schedule:work',
            'schedule:finish',
            'octane:*',
            'reverb:*',
            'pulse:*',
            'tinker',
            'serve',
            'pail',
            'queue:restart',
            'package:discover',
            'vendor:publish',
            'migrate*',
            'sherlock:*',
        ],

        // SQL patterns (Str::is on the raw SQL) never recorded.
        'queries' => [],

        // HTTP methods never traced.
        'methods' => ['OPTIONS', 'HEAD'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Tables are prefixed "sherlock_". The package's own queries are never recorded.
    | Persistence: "after_response" (in the terminating callback, after the
    | response is sent) or "queue" (a PersistTraceJob on the queue below).
    |
    */

    'storage' => [
        'connection' => env('SHERLOCK_DB_CONNECTION'),
        // Prefix for the package tables (sherlock_traces, sherlock_issues, ...).
        'table_prefix' => env('SHERLOCK_TABLE_PREFIX', 'sherlock_'),
        'persistence' => env('SHERLOCK_PERSISTENCE', 'after_response'),
        // Max query rows stored per trace (the slowest / most relevant first).
        'max_queries_per_trace' => 200,
        // Example traces kept per issue (sherlock_issue_traces).
        'issue_traces_cap' => 10,
    ],

    // Queue used by all package jobs (persist, AI analysis, notifications, digest).
    'queue' => [
        'connection' => env('SHERLOCK_QUEUE_CONNECTION'),
        'name' => env('SHERLOCK_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep; null keeps forever. sherlock:prune is scheduled automatically
    | unless "schedule" is false.
    |
    */

    'retention' => [
        'traces' => 7,
        'issues' => 90,
        'schedule' => true,
        'schedule_at' => '03:00',
        'chunk_size' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Redaction happens at capture time in every mode. The built-in sensitive keys
    | (password, token, secret, ...) are locked and cannot be removed; you can only
    | add more. Keys match case-insensitively by "contains" (very short words such
    | as "pin" or "otp" match as a whole word segment, e.g. "user_pin").
    |
    | store_bindings: auto   - store redacted bindings in full traces (local / watch /
    |                          header), never in production lightweight traces.
    |                 always - store redacted bindings whenever a trace is persisted.
    |                 never  - only fingerprints / normalized SQL are stored.
    |
    */

    'privacy' => [
        'sensitive_keys' => [],

        // Extra regexes; every match is replaced with [REDACTED].
        'patterns' => [],

        'store_bindings' => env('SHERLOCK_STORE_BINDINGS', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Delivered via the queue; a failing channel never blocks the others. The
    | first occurrence of an issue is sent immediately; repeats are counted and
    | summarized after "throttle_minutes".
    |
    */

    'notifications' => [
        'enabled' => env('SHERLOCK_NOTIFICATIONS', false),

        // Any combination of: slack, telegram, mail (comma separated in env).
        'channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SHERLOCK_NOTIFICATION_CHANNELS', ''))
        ))),

        // Global minimum severity; each channel may raise it.
        'min_severity' => env('SHERLOCK_NOTIFY_MIN_SEVERITY', 'warning'),

        'throttle_minutes' => (int) env('SHERLOCK_THROTTLE_MINUTES', 60),

        // What triggers a notification.
        'notify_on' => [
            'new' => true,
            'regressed' => true,
            'exceptions' => true,
        ],

        // Base URL for dashboard links; null = app.url.
        'dashboard_url' => env('SHERLOCK_DASHBOARD_URL'),

        // HTTP timeout (seconds) for webhook / bot API calls.
        'timeout' => 10,

        'slack' => [
            'webhook_url' => env('SHERLOCK_SLACK_WEBHOOK_URL'),
            'min_severity' => env('SHERLOCK_SLACK_MIN_SEVERITY', 'warning'),
            'username' => 'Sherlock',
            'channel' => env('SHERLOCK_SLACK_CHANNEL'),
        ],

        'telegram' => [
            'bot_token' => env('SHERLOCK_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('SHERLOCK_TELEGRAM_CHAT_ID'),
            'min_severity' => env('SHERLOCK_TELEGRAM_MIN_SEVERITY', 'warning'),
            'api_url' => 'https://api.telegram.org',
        ],

        'mail' => [
            'to' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('SHERLOCK_MAIL_TO', ''))
            ))),
            'min_severity' => env('SHERLOCK_MAIL_MIN_SEVERITY', 'critical'),
            // Mailer name; null = default mailer.
            'mailer' => env('SHERLOCK_MAILER'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI analysis
    |--------------------------------------------------------------------------
    |
    | Optional, once per unique issue fingerprint, budget-limited. Every payload
    | passes the Redactor again before leaving the app. The job never throws and
    | the notification is always sent, with or without an analysis.
    |
    | driver: anthropic | openai | gemini | deepseek | qwen | groq | openrouter |
    |         mistral | ollama | openai_compatible | null | <custom via extendAi()>
    |
    */

    'ai' => [
        'enabled' => env('SHERLOCK_AI_ENABLED', false),
        'driver' => env('SHERLOCK_AI_DRIVER', 'anthropic'),
        // null = the driver's default model below.
        'model' => env('SHERLOCK_AI_MODEL'),
        'api_key' => env('SHERLOCK_AI_KEY'),
        // null = the driver's default base URL below (required for openai_compatible).
        'base_url' => env('SHERLOCK_AI_BASE_URL'),
        // Language of the generated analysis.
        'language' => env('SHERLOCK_AI_LANGUAGE', 'en'),
        'analyze' => [
            'performance' => true,
            'exceptions' => false,
        ],
        'min_severity' => env('SHERLOCK_AI_MIN_SEVERITY', 'critical'),
        // AI calls per day (app timezone). 0 = no calls (fail-safe), negative (-1) = unlimited.
        'daily_limit' => (int) env('SHERLOCK_AI_DAILY_LIMIT', 50),
        'max_tokens' => 1500,
        // HTTP timeout in seconds; bounds the wait before the notification is sent.
        'timeout' => 30,
        'connect_timeout' => 10,
        // Opt-in: send ±snippet_lines of redacted source around the location.
        'send_code_snippet' => false,
        'snippet_lines' => 15,
        // Send column names/types and existing indexes of the tables in the SQL (never row data).
        'include_schema' => true,
        // Cache store used for the daily budget counter and the analysis lock; null = default store.
        'budget_store' => null,

        // Per-driver defaults / presets. OpenAI-compatible presets share one driver.
        // Each preset may also override model / base_url / api_key for that driver only;
        // the top-level model / base_url / api_key apply to the selected `driver` only.
        'drivers' => [
            'anthropic' => [
                'base_url' => 'https://api.anthropic.com/v1',
                'model' => 'claude-sonnet-5-5',
                'version' => '2023-06-01',
            ],
            'openai' => [
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
            ],
            'gemini' => [
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'model' => 'gemini-2.0-flash',
            ],
            'deepseek' => [
                'base_url' => 'https://api.deepseek.com/v1',
                'model' => 'deepseek-chat',
            ],
            'qwen' => [
                'base_url' => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
                'model' => 'qwen-plus',
            ],
            'groq' => [
                'base_url' => 'https://api.groq.com/openai/v1',
                'model' => 'llama-3.3-70b-versatile',
            ],
            'openrouter' => [
                'base_url' => 'https://openrouter.ai/api/v1',
                'model' => 'meta-llama/llama-3.3-70b-instruct:free',
                // Optional attribution headers (HTTP-Referer / X-Title); null = not sent.
                'referer' => null,
                'title' => 'Laravel Sherlock',
            ],
            'mistral' => [
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'mistral-small-latest',
            ],
            'ollama' => [
                'base_url' => env('SHERLOCK_OLLAMA_URL', 'http://localhost:11434'),
                'model' => 'qwen2.5-coder',
            ],
            'openai_compatible' => [
                'base_url' => null,
                'model' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Digest
    |--------------------------------------------------------------------------
    |
    | Scheduled daily at "at"; sends when "every_days" have passed since the last
    | digest. Uses stored AI recommendations only (no new AI calls).
    |
    */

    'digest' => [
        'enabled' => env('SHERLOCK_DIGEST', false),
        'every_days' => (int) env('SHERLOCK_DIGEST_EVERY_DAYS', 1),
        // Time of the daily check (scheduler timezone).
        'at' => env('SHERLOCK_DIGEST_AT', '09:00'),
        // Notification channels (comma separated in env); empty = every enabled channel.
        'channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SHERLOCK_DIGEST_CHANNELS', 'mail'))
        ))),
        // Items per digest section.
        'limit' => 10,
        // Cache store holding the last-sent time (kept forever); null = default store.
        'cache_store' => env('SHERLOCK_DIGEST_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Access is guarded by the "viewSherlock" gate (default: local env only).
    | Override it in your AppServiceProvider::boot(), e.g.:
    |
    |     Gate::define('viewSherlock', fn ($user) => in_array($user->email, [...]));
    |
    | When disabled, no route, view or gate is registered. Dashboard requests
    | are never traced (their path and "sherlock.*" route names are ignored).
    |
    */

    'dashboard' => [
        'enabled' => env('SHERLOCK_DASHBOARD', true),
        'path' => env('SHERLOCK_PATH', 'sherlock'),
        // Optional dedicated (sub)domain; the path is then relative to it.
        'domain' => env('SHERLOCK_DOMAIN'),
        // The viewSherlock gate middleware is always appended.
        'middleware' => ['web'],
        'per_page' => 25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Test assertions
    |--------------------------------------------------------------------------
    |
    | Thresholds of the PHPUnit trait (Anabil\Sherlock\Testing\InteractsWithSherlock)
    | and the Pest expectations (toHaveNoNPlusOne()...). They work in every mode, "off"
    | included. null = fall back to the matching "thresholds.*" value. The N+1 threshold
    | is stricter than in production on purpose: a query repeated twice from the same
    | line is already an N+1 in a test.
    |
    */

    'testing' => [
        // Same query from the same source location this many times = N+1 (min 2; null = 2,
        // never thresholds.n_plus_one: test fixtures are too small for the production value).
        'n_plus_one' => env('SHERLOCK_TEST_N_PLUS_ONE', 2),
        // null = thresholds.duplicate_query.min_count
        'duplicate_query' => null,
        // null = thresholds.slow_query.warning_ms
        'slow_query_ms' => null,
        // null = thresholds.excessive_queries.warning_count (used by assertNoQueryIssues())
        'max_queries' => null,
    ],

];
