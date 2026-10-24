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
    | The safe default is "production". Set TRACING_MODE=local on dev machines.
    |
    */

    'mode' => env('TRACING_MODE', 'production'),

    // Fraction (0.0 - 1.0) of non-problematic units persisted in production as a baseline.
    'sample_rate' => (float) env('TRACING_SAMPLE_RATE', 0.05),

    // In production, a trace is persisted when at least one finding reaches this severity
    // (info|warning|critical). Exceptions / log errors are always persisted.
    'persist_min_severity' => env('TRACING_PERSIST_MIN_SEVERITY', 'warning'),

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
        'requests' => env('TRACING_CAPTURE_REQUESTS', true),
        'jobs' => env('TRACING_CAPTURE_JOBS', true),
        'commands' => env('TRACING_CAPTURE_COMMANDS', true),

        'queries' => true,
        'http_client' => true,
        'cache' => true,
        'logs' => true,
        'exceptions' => true,

        // Minimum log level captured (PSR-3 name). "error" covers error, critical, alert, emergency.
        'log_level' => env('TRACING_LOG_LEVEL', 'error'),

        // Source location (backtrace) capture: only for the first occurrence of a query
        // fingerprint per trace and for slow queries, with DEBUG_BACKTRACE_IGNORE_ARGS.
        'source_location' => true,
        'backtrace_limit' => 30,

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
            'warning_ms' => (int) env('TRACING_SLOW_QUERY_MS', 100),
            'critical_ms' => 1000,
        ],

        // Same fingerprint + same source location repeated in one trace.
        'n_plus_one' => [
            'warning_count' => (int) env('TRACING_N_PLUS_ONE_THRESHOLD', 5),
            'critical_count' => 20,
        ],

        // Identical SQL + identical bindings repeated at least this many times.
        'duplicate_query' => [
            'min_count' => 2,
        ],

        // Total query count per trace.
        'excessive_queries' => [
            'warning_count' => (int) env('TRACING_MAX_QUERIES', 50),
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
            'warning_mb' => (int) env('TRACING_MEMORY_MB', 128),
            'critical_mb' => 512,
        ],

        // Outgoing HTTP call duration.
        'slow_http' => [
            'warning_ms' => (int) env('TRACING_SLOW_HTTP_MS', 1000),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyzers
    |--------------------------------------------------------------------------
    |
    | Analyzer pipeline (Strategy). Each class implements
    | Anabil\Tracing\Contracts\Analyzer. Add your own or remove any.
    |
    */

    'analyzers' => [
        'Anabil\Tracing\Analysis\Analyzers\SlowQueryAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\NPlusOneAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\DuplicateQueryAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\ExcessiveQueriesAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\SlowEntryAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\HighMemoryAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\SlowExternalCallAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\CacheMissRateAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\ExceptionAnalyzer',
        'Anabil\Tracing\Analysis\Analyzers\MissingIndexAnalyzer',
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
        'enabled' => env('TRACING_EXPLAIN', true),
        'only_select' => true,
        // Minimum query time to explain; null = thresholds.slow_query.warning_ms.
        'min_query_ms' => null,
        'cache_minutes' => 1440,
        // Cache store for the "already explained" markers; null = default store.
        'cache_store' => null,
        'max_per_trace' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Watch window (php artisan tracing:watch)
    |--------------------------------------------------------------------------
    |
    | Temporary full tracing stored in cache. Use a shared store (redis) so all
    | servers see it. Expires automatically.
    |
    */

    'watch' => [
        'cache_store' => env('TRACING_WATCH_STORE'),
        'cache_key' => 'tracing:watch',
        'default_minutes' => 30,
        'max_minutes' => 1440,
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
        'enabled' => env('TRACING_HEADER_ENABLED', true),
        'name' => 'X-Tracing',
        'secret' => env('TRACING_HEADER_SECRET'),
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
            'tracing*',
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
            'Anabil\Tracing\Jobs\*',
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
            'package:discover',
            'vendor:publish',
            'migrate*',
            'tracing:*',
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
    | Tables are prefixed "tracing_". The package's own queries are never recorded.
    | Persistence: "after_response" (in the terminating callback, after the
    | response is sent) or "queue" (a PersistTraceJob on the queue below).
    |
    */

    'storage' => [
        'connection' => env('TRACING_DB_CONNECTION'),
        'persistence' => env('TRACING_PERSISTENCE', 'after_response'),
        // Max query rows stored per trace (the slowest / most relevant first).
        'max_queries_per_trace' => 200,
        // Example traces kept per issue (tracing_issue_traces).
        'issue_traces_cap' => 10,
    ],

    // Queue used by all package jobs (persist, AI analysis, notifications, digest).
    'queue' => [
        'connection' => env('TRACING_QUEUE_CONNECTION'),
        'name' => env('TRACING_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Days to keep; null keeps forever. tracing:prune is scheduled automatically
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

        'store_bindings' => env('TRACING_STORE_BINDINGS', 'auto'),
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
        'enabled' => env('TRACING_NOTIFICATIONS', false),

        // Any combination of: slack, telegram, mail (comma separated in env).
        'channels' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRACING_NOTIFICATION_CHANNELS', ''))
        ))),

        // Global minimum severity; each channel may raise it.
        'min_severity' => env('TRACING_NOTIFY_MIN_SEVERITY', 'warning'),

        'throttle_minutes' => (int) env('TRACING_THROTTLE_MINUTES', 60),

        // What triggers a notification.
        'notify_on' => [
            'new' => true,
            'regressed' => true,
            'exceptions' => true,
        ],

        // Base URL for dashboard links; null = app.url.
        'dashboard_url' => env('TRACING_DASHBOARD_URL'),

        // HTTP timeout (seconds) for webhook / bot API calls.
        'timeout' => 10,

        'slack' => [
            'webhook_url' => env('TRACING_SLACK_WEBHOOK_URL'),
            'min_severity' => env('TRACING_SLACK_MIN_SEVERITY', 'warning'),
            'username' => 'Tracing',
            'channel' => env('TRACING_SLACK_CHANNEL'),
        ],

        'telegram' => [
            'bot_token' => env('TRACING_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('TRACING_TELEGRAM_CHAT_ID'),
            'min_severity' => env('TRACING_TELEGRAM_MIN_SEVERITY', 'warning'),
            'api_url' => 'https://api.telegram.org',
        ],

        'mail' => [
            'to' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('TRACING_MAIL_TO', ''))
            ))),
            'min_severity' => env('TRACING_MAIL_MIN_SEVERITY', 'critical'),
            // Mailer name; null = default mailer.
            'mailer' => env('TRACING_MAILER'),
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
        'enabled' => env('TRACING_AI_ENABLED', false),
        'driver' => env('TRACING_AI_DRIVER', 'anthropic'),
        // null = the driver's default model below.
        'model' => env('TRACING_AI_MODEL'),
        'api_key' => env('TRACING_AI_KEY'),
        // null = the driver's default base URL below (required for openai_compatible).
        'base_url' => env('TRACING_AI_BASE_URL'),
        // Language of the generated analysis.
        'language' => env('TRACING_AI_LANGUAGE', 'en'),
        'analyze' => [
            'performance' => true,
            'exceptions' => false,
        ],
        'min_severity' => env('TRACING_AI_MIN_SEVERITY', 'critical'),
        'daily_limit' => (int) env('TRACING_AI_DAILY_LIMIT', 50),
        'max_tokens' => 1500,
        // HTTP timeout in seconds; bounds the wait before the notification is sent.
        'timeout' => 30,
        // Opt-in: send ±snippet_lines of redacted source around the location.
        'send_code_snippet' => false,
        'snippet_lines' => 15,
        // Cache store used for the daily budget counter; null = default store.
        'budget_store' => null,

        // Per-driver defaults / presets. OpenAI-compatible presets share one driver.
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
            ],
            'mistral' => [
                'base_url' => 'https://api.mistral.ai/v1',
                'model' => 'mistral-small-latest',
            ],
            'ollama' => [
                'base_url' => env('TRACING_OLLAMA_URL', 'http://localhost:11434'),
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
        'enabled' => env('TRACING_DIGEST', false),
        'every_days' => 1,
        'at' => '09:00',
        'channels' => ['mail'],
        // Items per digest section.
        'limit' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | Access is guarded by the "viewTracing" gate (default: local env only).
    |
    */

    'dashboard' => [
        'enabled' => env('TRACING_DASHBOARD', true),
        'path' => env('TRACING_PATH', 'tracing'),
        'domain' => env('TRACING_DOMAIN'),
        'middleware' => ['web'],
        'per_page' => 25,
    ],

];
