<?php

declare(strict_types=1);

namespace Anabil\Tracing\Http\Presenters;

use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Data\StoredQuery;
use Anabil\Tracing\Data\StoredTrace;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Http\Support\Format;
use Anabil\Tracing\Http\Support\SqlHighlighter;
use Anabil\Tracing\Http\Support\Value;
use Illuminate\Support\HtmlString;

/**
 * Read-only view of a stored trace: summary, time breakdown, queries grouped by
 * fingerprint, HTTP calls,
 * logs / exceptions and tags. All values stay plain strings; the views escape them.
 *
 * N+1 here is "same fingerprint >= threshold times": source locations are only captured
 * for the first execution of a fingerprint, so repeats cannot be grouped by location.
 */
final readonly class TracePresenter
{
    /**
     * @param  list<StoredQuery>  $queries
     * @param  array<string, IssueRecord>  $issues  issue fingerprint => issue (for the findings' links)
     */
    public function __construct(
        public StoredTrace $trace,
        public array $queries,
        public int $nPlusOneThreshold = 5,
        public array $issues = [],
    ) {}

    /**
     * @return array<string, string> label => value
     */
    public function summary(): array
    {
        $trace = $this->trace;
        $details = $trace->details;

        return array_filter([
            'Type' => $trace->type->label(),
            'Method' => (string) $trace->method,
            'URI' => Value::string($details['uri'] ?? null),
            'Route' => Value::string($details['route'] ?? null),
            'Status' => $trace->statusCode === null ? '' : (string) $trace->statusCode,
            'Duration' => Format::ms($trace->durationMs),
            'Queries' => Format::number($trace->dbCount).' ('.Format::ms($trace->dbMs).')',
            'HTTP calls' => Format::number($trace->httpCount).' ('.Format::ms($trace->httpMs).')',
            'Cache' => $trace->cacheHits.' hits / '.$trace->cacheMisses.' misses',
            'Memory peak' => Format::bytes($trace->memoryPeak),
            'Recorded because' => $trace->recordReason->value,
            'User' => $trace->userId === null ? '' : (string) $trace->userId,
            'Recorded at' => Format::date($trace->createdAt),
        ], static fn (string $value): bool => $value !== '');
    }

    public function failed(): bool
    {
        return (bool) ($this->trace->details['failed'] ?? false);
    }

    public function breakdown(): Breakdown
    {
        return Breakdown::fromTrace($this->trace);
    }

    /**
     * Queries in execution order, each tagged with its repeat count and N+1 flag.
     *
     * @return list<array{query: StoredQuery, location: string|null, repeats: int, n_plus_one: bool}>
     */
    public function queryRows(): array
    {
        $counts = $this->fingerprintCounts();
        $rows = [];

        foreach ($this->queries as $query) {
            $repeats = $counts[$query->fingerprint];
            $rows[] = [
                'query' => $query,
                'location' => $this->location($query),
                'repeats' => $repeats,
                'n_plus_one' => $repeats >= $this->nPlusOneThreshold,
            ];
        }

        return $rows;
    }

    /**
     * One row per fingerprint, slowest total first.
     *
     * @return list<array{fingerprint: string, sql: HtmlString, count: int, total_ms: float, max_ms: float, locations: list<string>, n_plus_one: bool}>
     */
    public function groups(): array
    {
        $counts = $this->fingerprintCounts();
        $groups = [];

        foreach ($this->queries as $query) {
            $group = $groups[$query->fingerprint] ?? [
                'fingerprint' => $query->fingerprint,
                'sql' => $query->normalizedSql,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'locations' => [],
                'n_plus_one' => false,
            ];

            $group['count']++;
            $group['total_ms'] += $query->durationMs;
            $group['max_ms'] = max($group['max_ms'], $query->durationMs);
            $group['n_plus_one'] = $counts[$query->fingerprint] >= $this->nPlusOneThreshold;

            $location = $this->location($query);
            if ($location !== null && ! in_array($location, $group['locations'], true)) {
                $group['locations'][] = $location;
            }

            $groups[$query->fingerprint] = $group;
        }

        usort($groups, static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']);

        return array_map(static fn (array $group): array => [
            ...$group,
            'sql' => SqlHighlighter::highlight($group['sql'], false),
        ], $groups);
    }

    public function hasNPlusOne(): bool
    {
        foreach ($this->fingerprintCounts() as $count) {
            if ($count >= $this->nPlusOneThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{method: string, url: string, status: string, failed: bool, duration: string}>
     */
    public function httpCalls(): array
    {
        return self::calls($this->trace->details['http_calls'] ?? null);
    }

    /**
     * @return list<array{level: string, message: string, class: string|null, location: string|null, frames: list<string>, context: string|null, error: bool}>
     */
    public function logs(): array
    {
        $logs = [];

        foreach (Value::rows($this->trace->details['logs'] ?? null) as $log) {
            $level = Value::string($log['level'] ?? null, 'error');
            $context = Value::array($log['context'] ?? null);
            $class = Value::nullableString($log['exception_class'] ?? null);

            $logs[] = [
                'level' => $level,
                'message' => Value::string($log['message'] ?? null),
                'class' => $class,
                'location' => Value::location($log),
                'frames' => self::frames($log['frames'] ?? null),
                'context' => $context === [] ? null : (string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'error' => $class !== null || in_array($level, ['error', 'critical', 'alert', 'emergency'], true),
            ];
        }

        return $logs;
    }

    /**
     * @return array<string, string>
     */
    public function tags(): array
    {
        return self::pairs($this->trace->details['tags'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    public function metadata(): array
    {
        return self::pairs($this->trace->details['metadata'] ?? null);
    }

    /**
     * Findings of this trace, linked to their issue when it still exists.
     *
     * @return list<array{severity: Severity, type: string, title: string, description: string, issue: IssueRecord|null}>
     */
    public function findings(): array
    {
        $findings = [];

        foreach ($this->findingRows() as $finding) {
            $fingerprint = Value::string($finding['fingerprint'] ?? null);

            $findings[] = [
                'severity' => Severity::tryFrom(Value::string($finding['severity'] ?? null)) ?? Severity::Info,
                'type' => IssueType::tryFrom(Value::string($finding['type'] ?? null))?->label() ?? Value::string($finding['type'] ?? null),
                'title' => Value::string($finding['title'] ?? null),
                'description' => Value::string($finding['description'] ?? null),
                'issue' => $this->issues[$fingerprint] ?? null,
            ];
        }

        return $findings;
    }

    /**
     * Issue fingerprints of this trace's findings (for linking them to issues).
     *
     * @return list<string>
     */
    public static function findingFingerprints(StoredTrace $trace, int $limit = 20): array
    {
        $fingerprints = [];
        foreach (Value::rows($trace->details['findings'] ?? null) as $finding) {
            $fingerprint = Value::nullableString($finding['fingerprint'] ?? null);
            if ($fingerprint !== null) {
                $fingerprints[$fingerprint] = $fingerprint;
            }
        }

        return array_slice(array_values($fingerprints), 0, $limit);
    }

    public function verdictReason(): ?string
    {
        return $this->trace->verdictReason
            ?? Value::nullableString(Value::array($this->trace->details['verdict'] ?? null)['reason'] ?? null);
    }

    /**
     * Stored query rows are capped per trace; this tells the view when some were dropped.
     */
    public function storedQueries(): int
    {
        return count($this->queries);
    }

    /**
     * @return list<array{method: string, url: string, status: string, failed: bool, duration: string}>
     */
    public static function calls(mixed $calls): array
    {
        $rows = [];

        foreach (Value::rows($calls) as $call) {
            $status = Value::nullableInt($call['status_code'] ?? null);
            $error = Value::nullableString($call['error'] ?? null);

            $rows[] = [
                'method' => Value::string($call['method'] ?? null, 'GET'),
                'url' => Value::string($call['url'] ?? null),
                'status' => $error ?? ($status === null ? 'no response' : (string) $status),
                'failed' => $error !== null || $status === null || $status >= 500,
                'duration' => Format::ms(Value::float($call['duration_ms'] ?? null)),
            ];
        }

        return $rows;
    }

    /**
     * Stack frames as "Class::function() file:line" lines.
     *
     * @return list<string>
     */
    public static function frames(mixed $frames): array
    {
        $lines = [];

        foreach (Value::rows($frames) as $frame) {
            $location = Value::location($frame) ?? '';
            $class = Value::nullableString($frame['class'] ?? null);
            $function = Value::nullableString($frame['function'] ?? null);
            $symbol = $function === null ? '' : ($class === null ? $function : $class.'::'.$function).'() ';

            $lines[] = trim($symbol.$location);
        }

        return $lines;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function findingRows(): array
    {
        return Value::rows($this->trace->details['findings'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private static function pairs(mixed $values): array
    {
        $pairs = [];
        foreach (Value::array($values) as $key => $value) {
            $pairs[(string) $key] = Value::display($value);
        }

        return $pairs;
    }

    /**
     * @return array<string, int> fingerprint => executions
     */
    private function fingerprintCounts(): array
    {
        $counts = [];
        foreach ($this->queries as $query) {
            $counts[$query->fingerprint] = ($counts[$query->fingerprint] ?? 0) + 1;
        }

        return $counts;
    }

    private function location(StoredQuery $query): ?string
    {
        if ($query->file === null) {
            return null;
        }

        return $query->line === null ? $query->file : $query->file.':'.$query->line;
    }
}
