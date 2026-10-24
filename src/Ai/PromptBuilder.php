<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai;

use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Enums\IssueType;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use SplFileObject;
use Throwable;

/**
 * Builds the AI request for an issue (Builder: one section per kind of evidence, a
 * per-issue-type task template). Only whitelisted, already-redacted fields of the issue
 * context are used: normalized SQL with placeholders (never bindings), EXPLAIN insights
 * (never the raw plan: it was produced with real bindings), table schema (column names /
 * types / indexes, never rows or defaults), metrics, verdict, source location, exception
 * class / message / app frames. No request data (URI, headers, body), env or config.
 * The optional code snippet is read from base_path() only, never from .env* files.
 */
final class PromptBuilder
{
    private const MAX_SQL = 4000;

    private const MAX_TABLES = 5;

    private const MAX_COLUMNS = 40;

    private const MAX_INDEXES = 20;

    private const MAX_FRAMES = 8;

    private const MAX_HTTP_CALLS = 5;

    private const MAX_SNIPPET_LINES = 50;

    private const MAX_SNIPPET_FILE_BYTES = 1_048_576;

    private const LANGUAGES = [
        'en' => 'English', 'ar' => 'Arabic', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
        'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch', 'tr' => 'Turkish', 'ru' => 'Russian',
        'zh' => 'Chinese', 'ja' => 'Japanese', 'ko' => 'Korean', 'id' => 'Indonesian', 'fa' => 'Persian',
        'ur' => 'Urdu', 'hi' => 'Hindi',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly Redactor $redactor,
        private readonly ?ConnectionResolverInterface $db,
        private readonly string $basePath,
    ) {}

    public function build(IssueRecord $issue): AiRequest
    {
        return new AiRequest(
            $this->systemPrompt(),
            $this->userPrompt($issue),
            $this->int('tracing.ai.max_tokens', 1500, 64),
            null,
            $this->int('tracing.ai.timeout', 30, 1),
            null,
            ['issue_id' => $issue->id, 'fingerprint' => $issue->fingerprint, 'type' => $issue->type->value],
        );
    }

    public function systemPrompt(): string
    {
        $language = $this->language();
        $maxTokens = $this->int('tracing.ai.max_tokens', 1500, 64);

        return implode("\n", [
            'You are a senior Laravel performance engineer. You receive a diagnosis report produced by a self-hosted tracing tool for one recurring problem in a Laravel application.',
            'The data is privacy-redacted on purpose: SQL uses placeholders (?) and secrets appear as [REDACTED]. Never ask for, guess or invent real values.',
            'Base every statement on the evidence given; if it is not enough, say what to measure next instead of speculating.',
            'Answer in Markdown with exactly these sections, in this order:',
            '## Root cause',
            '## Fix',
            '## Code example',
            '## Expected impact',
            'Be concise and concrete. Prefer idiomatic Laravel fixes (eager loading, indexes added through migrations, select only needed columns, chunking / lazy collections, caching, queues, timeouts). The code example should be a short, ready-to-adapt PHP or migration snippet.',
            "Keep the whole answer under {$maxTokens} tokens.",
            "Write the answer in {$language}; keep code, SQL, identifiers and section headings' Markdown syntax as-is.",
        ]);
    }

    public function userPrompt(IssueRecord $issue): string
    {
        $context = $issue->context;

        $sections = array_filter([
            $this->issueSection($issue),
            $this->section('Task', $this->task($issue->type)),
            $this->entrySection($context),
            $this->metricsSection($context),
            $this->verdictSection($context),
            $this->sqlSection($context),
            $this->explainSection($context),
            $this->schemaSection($context),
            $this->locationSection($context),
            $this->snippetSection($context),
            $this->exceptionSection($context),
            $this->httpSection($context),
            $this->section('Detector recommendation', self::str($context['recommendation'] ?? null) ?? ''),
        ], static fn (string $section): bool => $section !== '');

        return implode("\n\n", $sections);
    }

    /** What the model should focus on, per issue type. */
    public function task(IssueType $type): string
    {
        return match ($type) {
            IssueType::NPlusOne => 'The same query runs once per item of a collection (N+1). Identify the relation being lazy-loaded from the location and SQL, and show the eager loading (with / load / withCount) or a single aggregated query that replaces it.',
            IssueType::SlowQuery => 'This query is slow. Use the EXPLAIN insights and the table schema to explain why (scan type, missing / unused index, sorting, large offsets, functions on columns) and propose the most effective fix (index via migration, query rewrite, pagination, caching).',
            IssueType::MissingIndex => 'EXPLAIN shows this query cannot use an index. Using the existing indexes listed, propose the exact index (column order matters) as a Laravel migration, or explain why an existing index is not used.',
            IssueType::DuplicateQuery => 'The identical query runs several times in one unit of work. Show how to run it once (memoize the result, eager load, pass the model along, once() / cache within the request).',
            IssueType::ExcessiveQueries => 'This entry point runs too many queries. Find the likely sources from the evidence and show how to batch, eager load, or cache them.',
            IssueType::SlowRequest, IssueType::SlowJob, IssueType::SlowCommand => 'This entry point is slow. Use the time breakdown to say where the time goes (database, external HTTP, cache, bootstrap, application code) and propose the highest-impact fix first.',
            IssueType::HighMemory => 'This entry point uses too much memory. Explain the likely cause (loading large collections, hydrating many models, big arrays / files in memory) and show chunking / lazy() / cursor() / streaming alternatives.',
            IssueType::SlowExternalCall => 'Outbound HTTP calls dominate the duration. Propose timeouts, retries with backoff, caching, concurrency (Http::pool) or moving the call to a queued job.',
            IssueType::CacheMissRate => 'The cache hit rate is poor. Explain likely causes (keys too specific, short TTLs, invalidation storms, cache not warmed) and how to restructure keys / TTLs / remember() usage.',
            IssueType::Exception => 'This exception keeps happening. Using the exception class, message and application frames, explain the root cause and show a robust fix (validation, null handling, guarding, retry or failing gracefully). Do not just suggest catching and ignoring it.',
            IssueType::LogError => 'This error is logged repeatedly. Using the message and location, explain the root cause and show how to fix it at the source rather than silencing the log.',
        };
    }

    private function issueSection(IssueRecord $issue): string
    {
        return $this->section('Issue', $this->list([
            'Type' => $issue->type->label(),
            'Title' => $issue->title,
            'Severity' => $issue->severity->label(),
            'Occurrences' => (string) $issue->occurrences,
            'First seen' => $issue->firstSeenAt->format('Y-m-d H:i T'),
            'Last seen' => $issue->lastSeenAt->format('Y-m-d H:i T'),
            'Regressed' => $issue->regressedAt !== null ? 'yes, it came back after being resolved' : null,
            'Description' => self::str($issue->context['description'] ?? null),
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function entrySection(array $context): string
    {
        $entry = self::arr($context['entry'] ?? null);

        // Entry names are route patterns ("GET /users/{user}"), never full URLs; the URI is not sent.
        return $this->section('Entry point', $this->list([
            'Kind' => self::str($entry['type'] ?? null),
            'Name' => self::str($entry['name'] ?? null),
            'Route name' => self::str($entry['route'] ?? null),
            'HTTP status' => self::scalar($entry['status_code'] ?? null),
            'Failed' => ($entry['failed'] ?? false) === true ? 'yes' : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function metricsSection(array $context): string
    {
        $metrics = [];
        foreach (self::arr($context['metrics'] ?? null) as $key => $value) {
            $metrics[(string) $key] = self::scalar($value);
        }

        $trace = self::arr($context['trace'] ?? null);
        $memory = $trace['memory_peak'] ?? null;

        $metrics += [
            'Total duration (ms)' => self::scalar($trace['duration_ms'] ?? null),
            'Database time (ms)' => self::scalar($trace['db_ms'] ?? null),
            'Query count' => self::scalar($trace['db_count'] ?? null),
            'External HTTP time (ms)' => self::scalar($trace['http_ms'] ?? null),
            'External HTTP calls' => self::scalar($trace['http_count'] ?? null),
            'Cache hits / misses' => isset($trace['cache_hits'], $trace['cache_misses'])
                ? self::scalar($trace['cache_hits']).' / '.self::scalar($trace['cache_misses'])
                : null,
            'Bootstrap (ms)' => self::scalar($trace['bootstrap_ms'] ?? null),
            'Peak memory (MB)' => is_numeric($memory) ? (string) round((float) $memory / 1_048_576, 1) : null,
        ];

        return $this->section('Metrics (latest occurrence)', $this->list($metrics));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function verdictSection(array $context): string
    {
        $verdict = self::arr($context['verdict'] ?? null);
        if ($verdict === []) {
            return '';
        }

        $breakdown = [];
        $percentages = self::arr($verdict['percentages'] ?? null);
        foreach (self::arr($verdict['breakdown_ms'] ?? null) as $segment => $ms) {
            $pct = $percentages[$segment] ?? null;
            $breakdown[] = "{$segment}: ".self::scalar($ms).' ms'.(is_numeric($pct) ? ' ('.round((float) $pct, 1).'%)' : '');
        }

        return $this->section('Time breakdown (verdict)', $this->list([
            'Bottleneck' => self::str($verdict['bottleneck'] ?? null),
            'Reason' => self::str($verdict['reason'] ?? null),
            'Breakdown' => $breakdown === [] ? null : implode(', ', $breakdown),
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function sqlSection(array $context): string
    {
        $sql = self::str($context['sql'] ?? null);
        if ($sql === null) {
            return '';
        }

        $query = self::arr($context['query'] ?? null);
        $stats = $this->list([
            'Executions in the trace' => self::scalar($query['count'] ?? null),
            'Total time (ms)' => self::scalar($query['total_ms'] ?? null),
            'Slowest execution (ms)' => self::scalar($query['max_ms'] ?? null),
            'Database driver' => self::str($query['driver'] ?? null),
        ]);

        return $this->section('SQL (normalized, placeholders only)', "```sql\n".self::cut($sql, self::MAX_SQL)."\n```".($stats === '' ? '' : "\n".$stats));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function explainSection(array $context): string
    {
        $explain = self::arr($context['explain'] ?? null);
        $lines = [];

        foreach (self::arr($explain['insights'] ?? null) as $insight) {
            $insight = self::arr($insight);
            $message = self::str($insight['message'] ?? null);
            if ($message === null) {
                continue;
            }

            $extra = array_filter([
                ($s = self::str($insight['severity'] ?? null)) !== null ? $s : null,
                ($rows = self::scalar($insight['estimated_rows'] ?? null)) !== null ? "≈{$rows} rows" : null,
                ($index = self::str($insight['suggested_index'] ?? null)) !== null ? "suggested index: {$index}" : null,
            ]);

            $lines[] = '- '.$message.($extra === [] ? '' : ' ('.implode('; ', $extra).')');
        }

        $driver = self::str($explain['driver'] ?? null);

        return $lines === [] ? '' : $this->section('EXPLAIN insights'.($driver !== null ? " ({$driver})" : ''), implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function schemaSection(array $context): string
    {
        if (! $this->config->get('tracing.ai.include_schema', true) || $this->db === null) {
            return '';
        }

        $tables = $this->tables($context);
        if ($tables === []) {
            return '';
        }

        try {
            $connection = $this->db->connection($this->connectionName($context));
        } catch (Throwable) {
            return '';
        }

        if (! $connection instanceof Connection) {
            return '';
        }

        $blocks = [];
        foreach ($tables as $table) {
            $block = $this->tableSchema($connection, $table);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks === [] ? '' : $this->section('Table schema (columns and existing indexes, no data)', implode("\n\n", $blocks));
    }

    private function tableSchema(Connection $connection, string $table): ?string
    {
        try {
            $schema = $connection->getSchemaBuilder();
            $columns = $schema->getColumns($table);
            $indexes = $schema->getIndexes($table);
        } catch (Throwable) {
            return null;
        }

        if ($columns === []) {
            return null;
        }

        $cols = [];
        foreach (array_slice($columns, 0, self::MAX_COLUMNS) as $column) {
            $name = self::str($column['name'] ?? null);
            if ($name === null) {
                continue;
            }

            $type = self::str($column['type'] ?? null) ?? self::str($column['type_name'] ?? null) ?? '?';
            $cols[] = $name.' '.$type.(($column['nullable'] ?? false) === true ? ' null' : '');
        }

        $idx = [];
        foreach (array_slice($indexes, 0, self::MAX_INDEXES) as $index) {
            $columnsOf = array_filter(self::arr($index['columns'] ?? null), 'is_string');
            $kind = ($index['primary'] ?? false) === true ? 'primary' : (($index['unique'] ?? false) === true ? 'unique' : 'index');
            $idx[] = (self::str($index['name'] ?? null) ?? '?')." {$kind}(".implode(', ', $columnsOf).')';
        }

        $more = count($columns) > self::MAX_COLUMNS ? ' (+'.(count($columns) - self::MAX_COLUMNS).' more)' : '';

        return "`{$table}`\n- Columns{$more}: ".implode(', ', $cols)."\n- Indexes: ".($idx === [] ? 'none' : implode('; ', $idx));
    }

    /**
     * Tables referenced by the SQL and the EXPLAIN insights (identifiers only).
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public function tables(array $context): array
    {
        $tables = [];
        $sql = self::str($context['sql'] ?? null) ?? '';

        if (preg_match_all('/\b(?:from|join|update|into)\s+((?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?\w+[`"\]]?)/i', $sql, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $parts = explode('.', str_replace(['`', '"', '[', ']'], '', $match));
                $tables[] = (string) end($parts);
            }
        }

        foreach (self::arr(self::arr($context['explain'] ?? null)['insights'] ?? null) as $insight) {
            $table = self::str(self::arr($insight)['table'] ?? null);
            if ($table !== null) {
                $tables[] = $table;
            }
        }

        $tables = array_filter(
            array_unique($tables),
            static fn (string $table): bool => preg_match('/^\w{1,64}$/', $table) === 1 && strtolower($table) !== 'select',
        );

        return array_slice(array_values($tables), 0, self::MAX_TABLES);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function connectionName(array $context): ?string
    {
        $name = self::str(self::arr($context['query'] ?? null)['connection'] ?? null)
            ?? self::str(self::arr($context['explain'] ?? null)['connection'] ?? null);

        if ($name === null) {
            return null;
        }

        // Only connections the app configures; never let context pick an arbitrary one.
        return is_array($this->config->get("database.connections.{$name}")) ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function locationSection(array $context): string
    {
        $location = self::arr($context['location'] ?? null);
        $file = self::str($location['file'] ?? null);
        if ($file === null) {
            return '';
        }

        $class = self::str($location['class'] ?? null);
        $function = self::str($location['function'] ?? null);
        $symbol = $class !== null && $function !== null ? "{$class}::{$function}()" : ($function !== null ? "{$function}()" : $class);

        return $this->section('Source location', $file.':'.(self::scalar($location['line'] ?? null) ?? '?').($symbol !== null ? " in {$symbol}" : ''));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function snippetSection(array $context): string
    {
        if (! $this->config->get('tracing.ai.send_code_snippet', false)) {
            return '';
        }

        $location = self::arr($context['location'] ?? null);
        $file = self::str($location['file'] ?? null);
        $line = $location['line'] ?? null;

        if ($file === null || ! is_int($line) || $line < 1) {
            return '';
        }

        $snippet = $this->snippet($file, $line, $this->int('tracing.ai.snippet_lines', 15, 1));

        return $snippet === null ? '' : $this->section("Code around {$file}:{$line} (redacted)", "```php\n{$snippet}\n```");
    }

    /**
     * ±$radius lines of a file inside base_path(), redacted. Null when the file is outside the
     * base path (symlinks resolved), is a .env* file, too big or unreadable.
     */
    public function snippet(string $file, int $line, int $radius): ?string
    {
        $path = $this->safePath($file);
        if ($path === null) {
            return null;
        }

        $radius = min($radius, intdiv(self::MAX_SNIPPET_LINES, 2));
        $from = max(1, $line - $radius);
        $to = $line + $radius;

        try {
            $handle = new SplFileObject($path, 'r');
            $handle->seek($from - 1);

            $lines = [];
            for ($current = $from; $current <= $to && ! $handle->eof(); $current++) {
                $raw = $handle->current();
                $text = is_string($raw) ? rtrim($raw, "\r\n") : '';
                $lines[] = sprintf('%s%4d | %s', $current === $line ? '>' : ' ', $current, $this->redactor->redactString($text));
                $handle->next();
            }
        } catch (Throwable) {
            return null;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function safePath(string $file): ?string
    {
        if ($file === '' || str_contains($file, "\0")) {
            return null;
        }

        $base = realpath($this->basePath);
        if ($base === false) {
            return null;
        }

        $candidate = str_starts_with($file, DIRECTORY_SEPARATOR) ? $file : $base.DIRECTORY_SEPARATOR.$file;
        $path = realpath($candidate);

        if ($path === false || ! str_starts_with($path, rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        // No .env / .env.* anywhere in the (resolved or requested) path.
        foreach ([...explode(DIRECTORY_SEPARATOR, substr($path, strlen($base))), ...explode('/', $file)] as $segment) {
            if (str_starts_with(strtolower($segment), '.env')) {
                return null;
            }
        }

        if (! is_file($path) || ! is_readable($path) || (int) filesize($path) > self::MAX_SNIPPET_FILE_BYTES) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function exceptionSection(array $context): string
    {
        $exception = self::arr($context['exception'] ?? null);
        if ($exception === []) {
            return '';
        }

        $frames = [];
        foreach (self::arr($exception['frames'] ?? null) as $frame) {
            $frame = self::arr($frame);
            $file = self::str($frame['file'] ?? null);
            if ($file === null || str_starts_with($file, 'vendor/')) {
                continue;
            }

            $class = self::str($frame['class'] ?? null);
            $function = self::str($frame['function'] ?? null);
            $symbol = $function !== null ? ' '.($class !== null ? "{$class}::" : '')."{$function}()" : '';
            $frames[] = '- '.$file.':'.(self::scalar($frame['line'] ?? null) ?? '?').$symbol;

            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        $message = self::str($exception['message'] ?? null);

        return $this->section('Exception', $this->list([
            'Class' => self::str($exception['class'] ?? null),
            'Level' => self::str($exception['level'] ?? null),
            'Message' => $message !== null ? $this->redactor->redactString(self::cut($message, 1000)) : null,
            'Thrown at' => ($file = self::str($exception['file'] ?? null)) !== null ? $file.':'.(self::scalar($exception['line'] ?? null) ?? '?') : null,
        ]).($frames === [] ? '' : "\nApplication frames (most recent first):\n".implode("\n", $frames)));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function httpSection(array $context): string
    {
        $lines = [];

        foreach (array_slice(self::arr($context['http_calls'] ?? null), 0, self::MAX_HTTP_CALLS) as $call) {
            $call = self::arr($call);
            $url = self::str($call['url'] ?? null);
            if ($url === null) {
                continue;
            }

            // Host and path only: query strings may carry identifiers or tokens.
            $parts = parse_url($url);
            $target = is_array($parts) ? ($parts['host'] ?? '').($parts['path'] ?? '') : '';

            $lines[] = sprintf(
                '- %s %s → %s in %s ms',
                self::str($call['method'] ?? null) ?? 'GET',
                $target !== '' ? $target : '[url]',
                self::scalar($call['status_code'] ?? null) ?? 'no response',
                self::scalar($call['duration_ms'] ?? null) ?? '?',
            );
        }

        return $lines === [] ? '' : $this->section('Slowest external calls', implode("\n", $lines));
    }

    private function section(string $title, string $body): string
    {
        return trim($body) === '' ? '' : "## {$title}\n{$body}";
    }

    /**
     * @param  array<string, string|null>  $items
     */
    private function list(array $items): string
    {
        $lines = [];
        foreach ($items as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        return implode("\n", $lines);
    }

    private function language(): string
    {
        $code = $this->config->get('tracing.ai.language', 'en');
        $code = is_string($code) && trim($code) !== '' ? trim($code) : 'en';

        return self::LANGUAGES[strtolower(substr($code, 0, 2))] ?? $code;
    }

    private function int(string $key, int $default, int $min): int
    {
        $value = $this->config->get($key, $default);

        return max($min, is_numeric($value) ? (int) $value : $default);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function scalar(mixed $value): ?string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value), is_string($value) => $value === '' ? null : (string) $value,
            is_float($value) => (string) round($value, 3),
            default => null,
        };
    }

    private static function cut(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }
}
