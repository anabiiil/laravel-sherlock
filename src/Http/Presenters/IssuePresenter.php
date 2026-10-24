<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Presenters;

use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Http\Support\Format;
use Anabil\Sherlock\Http\Support\Markdown;
use Anabil\Sherlock\Http\Support\SqlHighlighter;
use Anabil\Sherlock\Http\Support\Value;
use Illuminate\Support\HtmlString;

/**
 * Read-only view of an issue's stored context (see IssueContextBuilder for its shape).
 * All values stay plain strings; the views escape them.
 */
final readonly class IssuePresenter
{
    public function __construct(
        public IssueRecord $issue,
    ) {}

    public function description(): ?string
    {
        return Value::nullableString($this->issue->context['description'] ?? null);
    }

    public function recommendation(): ?string
    {
        return Value::nullableString($this->issue->context['recommendation'] ?? null);
    }

    public function location(): ?string
    {
        return Value::location($this->issue->context['location'] ?? null);
    }

    public function locationSymbol(): ?string
    {
        $location = Value::array($this->issue->context['location'] ?? null);
        $class = Value::nullableString($location['class'] ?? null);
        $function = Value::nullableString($location['function'] ?? null);

        if ($function === null) {
            return $class;
        }

        return $class === null ? $function.'()' : $class.'::'.$function.'()';
    }

    public function rawSql(): ?string
    {
        return Value::nullableString($this->issue->context['sql'] ?? null)
            ?? Value::nullableString(Value::array($this->issue->context['explain'] ?? null)['sql'] ?? null);
    }

    public function sql(): ?HtmlString
    {
        $sql = $this->rawSql();

        return $sql === null ? null : SqlHighlighter::highlight($sql);
    }

    public function queryFingerprint(): ?string
    {
        return Value::nullableString($this->issue->context['query_fingerprint'] ?? null);
    }

    /**
     * Aggregate of the query in the latest trace.
     *
     * @return array<string, string> label => value
     */
    public function queryStats(): array
    {
        $query = Value::array($this->issue->context['query'] ?? null);

        if ($query === []) {
            return [];
        }

        return array_filter([
            'Executions' => Format::number((int) Value::float($query['count'] ?? null)),
            'Total time' => Format::ms(Value::float($query['total_ms'] ?? null)),
            'Slowest' => Format::ms(Value::float($query['max_ms'] ?? null)),
            'Connection' => Value::string($query['connection'] ?? null),
            'Driver' => Value::string($query['driver'] ?? null),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @return list<array{severity: Severity, message: string, table: string|null, index: string|null, rows: int|null}>
     */
    public function insights(): array
    {
        $explain = Value::array($this->issue->context['explain'] ?? null);
        $insights = [];

        foreach (Value::rows($explain['insights'] ?? null) as $insight) {
            $insights[] = [
                'severity' => Severity::tryFrom(Value::string($insight['severity'] ?? null)) ?? Severity::Info,
                'message' => Value::string($insight['message'] ?? null),
                'table' => Value::nullableString($insight['table'] ?? null),
                'index' => Value::nullableString($insight['suggested_index'] ?? null),
                'rows' => Value::nullableInt($insight['estimated_rows'] ?? null),
            ];
        }

        return $insights;
    }

    /**
     * @return list<string> e.g. ["orders(user_id)"]
     */
    public function suggestedIndexes(): array
    {
        $indexes = [];
        foreach ($this->insights() as $insight) {
            if ($insight['index'] !== null) {
                $indexes[$insight['index']] = $insight['index'];
            }
        }

        return array_values($indexes);
    }

    public function explainDriver(): ?string
    {
        return Value::nullableString(Value::array($this->issue->context['explain'] ?? null)['driver'] ?? null);
    }

    /**
     * @return array<string, string> label => formatted value
     */
    public function metrics(): array
    {
        $metrics = [];
        foreach (Value::array($this->issue->context['metrics'] ?? null) as $key => $value) {
            // Shown in the "Relation" card (relation()), or null for non lazy-loading N+1s.
            if (in_array($key, ['model', 'relation_source'], true) || $value === null) {
                continue;
            }

            $metrics[Format::label((string) $key)] = Format::metric((string) $key, $value);
        }

        return $metrics;
    }

    public function verdictReason(): ?string
    {
        return Value::nullableString(Value::array($this->issue->context['verdict'] ?? null)['reason'] ?? null);
    }

    public function bottleneck(): ?Bottleneck
    {
        return Bottleneck::tryFrom(Value::string(Value::array($this->issue->context['verdict'] ?? null)['bottleneck'] ?? null));
    }

    public function breakdown(): Breakdown
    {
        $trace = Value::array($this->issue->context['trace'] ?? null);

        return Breakdown::fromVerdict(
            Value::array($this->issue->context['verdict'] ?? null),
            Value::float($trace['duration_ms'] ?? null),
        );
    }

    /**
     * Where it happened (route / job / command) in the latest occurrence.
     *
     * @return array<string, string> label => value
     */
    public function entry(): array
    {
        $entry = Value::array($this->issue->context['entry'] ?? null);

        return array_filter([
            'Type' => ucfirst(Value::string($entry['type'] ?? null)),
            'Name' => Value::string($entry['name'] ?? null),
            'Route' => Value::string($entry['route'] ?? null),
            'URI' => Value::string($entry['uri'] ?? null),
            'Status' => Value::string($entry['status_code'] ?? null),
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * Metrics of the latest trace (duration, db, http, memory...).
     *
     * @return array<string, string> label => formatted value
     */
    public function traceMetrics(): array
    {
        $trace = Value::array($this->issue->context['trace'] ?? null);
        $metrics = [];

        foreach (['duration_ms', 'db_ms', 'db_count', 'http_ms', 'http_count', 'cache_hits', 'cache_misses', 'memory_peak'] as $key) {
            if (array_key_exists($key, $trace)) {
                $metrics[Format::label($key)] = Format::metric($key, $trace[$key]);
            }
        }

        return $metrics;
    }

    public function latestTraceId(): ?string
    {
        return Value::nullableString(Value::array($this->issue->context['trace'] ?? null)['id'] ?? null);
    }

    /**
     * @return array{class: string|null, message: string, location: string|null, frames: list<string>}|null
     */
    public function exception(): ?array
    {
        $exception = Value::array($this->issue->context['exception'] ?? null);

        if ($exception === []) {
            return null;
        }

        return [
            'class' => Value::nullableString($exception['class'] ?? null),
            'message' => Value::string($exception['message'] ?? null),
            'location' => Value::location($exception),
            'frames' => TracePresenter::frames($exception['frames'] ?? null),
        ];
    }

    /**
     * @return list<array{method: string, url: string, status: string, failed: bool, duration: string}>
     */
    public function httpCalls(): array
    {
        return TracePresenter::calls($this->issue->context['http_calls'] ?? null);
    }

    /** Cumulative time cost of every occurrence, or null when none was attributed. */
    public function impact(): ?string
    {
        return $this->issue->impactMs > 0 ? Format::human($this->issue->impactMs) : null;
    }

    /**
     * N+1: the lazy loaded relation (exact when Eloquent reported it, otherwise guessed from the SQL).
     *
     * @return array{model: string|null, relation: string, exact: bool}|null
     */
    public function relation(): ?array
    {
        if ($this->issue->type !== IssueType::NPlusOne) {
            return null;
        }

        $metrics = Value::array($this->issue->context['metrics'] ?? null);
        $relation = Value::nullableString($metrics['relation'] ?? null);

        if ($relation === null) {
            return null;
        }

        return [
            'model' => Value::nullableString($metrics['model'] ?? null),
            'relation' => $relation,
            'exact' => Value::string($metrics['relation_source'] ?? null) === 'lazy_loading',
        ];
    }

    /**
     * Server / queue signals of the latest occurrence.
     *
     * @return array{rows: array<string, string>, cpu_percent: float|null, load_percent: float|null, waiting: bool}
     */
    public function signals(): array
    {
        $trace = Value::array($this->issue->context['trace'] ?? null);
        $stored = Value::array(Value::array($this->issue->context['verdict'] ?? null)['signals'] ?? null);
        $pick = static fn (string $key): mixed => $trace[$key] ?? $stored[$key] ?? null;

        return TracePresenter::signalRows([
            'duration_ms' => Value::nullableFloat($trace['duration_ms'] ?? null),
            'cpu_ms' => Value::nullableFloat($pick('cpu_ms')),
            'load_avg' => Value::nullableFloat($pick('load_avg')),
            'cpu_cores' => Value::nullableInt($pick('cpu_cores')),
            'memory_free_mb' => Value::nullableInt($trace['memory_free_mb'] ?? null),
            'queue_wait_ms' => Value::nullableFloat($pick('queue_wait_ms')),
        ]);
    }

    /**
     * @return list<array{model: string, relation: string, short: string, count: int, table: string|null}>
     */
    public function lazyLoads(): array
    {
        return TracePresenter::lazyLoadRows($this->issue->context['lazy_loads'] ?? null);
    }

    /**
     * @return array{rows: array<string, string>, contributors: array<string, string>}|null
     */
    public function memoryLeak(): ?array
    {
        return TracePresenter::memoryLeakRows($this->issue->context['memory_leak'] ?? null);
    }

    public function aiAnalysis(): ?HtmlString
    {
        return Markdown::render($this->issue->aiAnalysis);
    }
}
