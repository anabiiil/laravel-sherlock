<?php

declare(strict_types=1);

namespace Anabil\Tracing\Http\Presenters;

use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Http\Support\Format;
use Anabil\Tracing\Http\Support\Markdown;
use Anabil\Tracing\Http\Support\SqlHighlighter;
use Anabil\Tracing\Http\Support\Value;
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

    public function aiAnalysis(): ?HtmlString
    {
        return Markdown::render($this->issue->aiAnalysis);
    }
}
