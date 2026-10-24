<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Issues;

use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;

/**
 * Builds the issue context stored on sherlock_issues.context: the latest Finding::toArray()
 * plus entry info, trace metrics, verdict, EXPLAIN plan / insights and exception details.
 * Everything here is already redacted at capture time; this is what notifications, the
 * dashboard and the AI prompt read.
 */
final class IssueContextBuilder
{
    private const MAX_FRAMES = 15;

    private const MAX_RAW_PLAN_BYTES = 16384;

    /**
     * @return array<string, mixed>
     */
    public function build(Finding $finding, RecordedTrace $recorded, string $traceId): array
    {
        $trace = $recorded->trace;

        $context = $finding->toArray() + [
            'entry' => [
                'type' => $trace->type->value,
                'name' => $trace->name,
                'method' => $trace->method,
                'route' => $trace->route,
                'uri' => $trace->uri,
                'status_code' => $trace->statusCode,
                'failed' => $trace->failed,
            ],
            'trace' => [
                'id' => $traceId,
                'record_reason' => $recorded->reason->value,
                'duration_ms' => round($trace->durationMs, 3),
                'db_ms' => round($trace->dbMs, 3),
                'db_count' => $trace->queryCount(),
                'http_ms' => round($trace->httpMs, 3),
                'http_count' => $trace->httpCount(),
                'cache_hits' => $trace->cacheHits,
                'cache_misses' => $trace->cacheMisses,
                'bootstrap_ms' => round($trace->bootstrapMs, 3),
                'memory_peak' => $trace->memoryPeakBytes,
                'started_at' => $trace->startedAt->format(DATE_ATOM),
            ],
            'verdict' => $recorded->analysis->verdict->toArray(),
        ];

        $plan = $this->explainPlan($finding, $recorded);
        if ($plan !== null) {
            $context['explain'] = $this->explain($plan);
        }

        $query = $this->queryDetails($finding, $trace);
        if ($query !== null) {
            $context['query'] = $query;
        }

        if ($finding->type->isError()) {
            $log = $this->matchingLog($finding, $trace);
            if ($log !== null) {
                $context['exception'] = $this->log($log);
            }
        }

        if ($finding->type === IssueType::SlowExternalCall) {
            $calls = array_map(
                static fn (HttpCallRecord $call): array => $call->toArray(),
                array_slice($this->slowestCalls($trace), 0, 5),
            );
            if ($calls !== []) {
                $context['http_calls'] = $calls;
            }
        }

        return $context;
    }

    private function explainPlan(Finding $finding, RecordedTrace $recorded): ?ExplainPlan
    {
        if ($finding->queryFingerprint === null) {
            return null;
        }

        return $recorded->analysis->explainPlans[$finding->queryFingerprint] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function explain(ExplainPlan $plan): array
    {
        $data = $plan->toArray();
        $raw = json_encode($plan->raw, JSON_PARTIAL_OUTPUT_ON_ERROR);

        // Keep the plan for the AI prompt, but never let a huge plan bloat the issue row.
        if ($raw === false || strlen($raw) > self::MAX_RAW_PLAN_BYTES) {
            $data['raw'] = [];
            $data['raw_truncated'] = true;
        }

        return $data;
    }

    /**
     * Aggregate of the finding's query fingerprint in this trace (count / total / max time).
     *
     * @return array<string, mixed>|null
     */
    private function queryDetails(Finding $finding, TraceData $trace): ?array
    {
        if ($finding->queryFingerprint === null) {
            return null;
        }

        $count = 0;
        $total = 0.0;
        $max = 0.0;
        $connection = null;
        $driver = null;

        foreach ($trace->queries as $query) {
            if ($query->fingerprint !== $finding->queryFingerprint) {
                continue;
            }

            $count++;
            $total += $query->timeMs;
            $max = max($max, $query->timeMs);
            $connection ??= $query->connection;
            $driver ??= $query->driver;
        }

        if ($count === 0) {
            return null;
        }

        return [
            'fingerprint' => $finding->queryFingerprint,
            'count' => $count,
            'total_ms' => round($total, 3),
            'max_ms' => round($max, 3),
            'connection' => $connection,
            'driver' => $driver,
        ];
    }

    private function matchingLog(Finding $finding, TraceData $trace): ?LogRecord
    {
        $candidates = $finding->type === IssueType::Exception ? $trace->exceptions() : $trace->logs;

        if ($finding->location !== null) {
            foreach ($candidates as $log) {
                if ($log->file === $finding->location->file && $log->line === $finding->location->line) {
                    return $log;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function log(LogRecord $log): array
    {
        return [
            'level' => $log->level,
            'class' => $log->exceptionClass,
            'message' => $log->message,
            'file' => $log->file,
            'line' => $log->line,
            'frames' => array_slice($log->frames, 0, self::MAX_FRAMES),
        ];
    }

    /**
     * @return list<HttpCallRecord>
     */
    private function slowestCalls(TraceData $trace): array
    {
        $calls = $trace->httpCalls;
        usort($calls, static fn (HttpCallRecord $a, HttpCallRecord $b): int => $b->durationMs <=> $a->durationMs);

        return $calls;
    }
}
