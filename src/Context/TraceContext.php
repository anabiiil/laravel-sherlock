<?php

declare(strict_types=1);

namespace Anabil\Tracing\Context;

use Anabil\Tracing\Contracts\TraceContext as TraceContextContract;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\LogRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;
use DateTimeImmutable;

/**
 * Mutable per-unit buffer. Every write is O(1) (array push / counter); the immutable
 * TraceData is only built once, at stop.
 *
 * Caps bound memory on huge jobs: records beyond max_queries / max_http_calls / max_logs
 * are counted (totals stay exact) but not kept. Once frozen (response sent / command
 * finished), timing signals are ignored so terminate-time work does not inflate the trace;
 * logs are still accepted so errors raised while terminating stay attached.
 */
final class TraceContext implements TraceContextContract
{
    /** Distinct SQL strings remembered for the first-occurrence backtrace rule. */
    public const MAX_SEEN_KEYS = 2000;

    /** @var list<QueryRecord> */
    private array $queries = [];

    private int $queryCount = 0;

    private float $dbMs = 0.0;

    /** @var list<HttpCallRecord> */
    private array $httpCalls = [];

    private int $droppedHttpCalls = 0;

    /** @var list<LogRecord> */
    private array $logs = [];

    private int $droppedLogs = 0;

    private int $cacheHits = 0;

    private int $cacheMisses = 0;

    private int $cacheWrites = 0;

    private float $cacheMs = 0.0;

    private ?string $method = null;

    private ?string $uri = null;

    private ?string $route = null;

    private ?int $statusCode = null;

    private bool $failed = false;

    private int|string|null $userId = null;

    private float $bootstrapMs = 0.0;

    /** @var array<string, scalar|null> */
    private array $tags = [];

    /** @var array<string, scalar|null> */
    private array $metadata = [];

    /** @var array<string, true> */
    private array $seen = [];

    private int $seenCount = 0;

    private ?float $endedAt = null;

    private ?int $memoryPeak = null;

    /**
     * @param  float  $startedAt  microtime(true) of the unit start
     * @param  list<RecordReason>  $reasons
     */
    public function __construct(
        private readonly string $id,
        private readonly EntryType $type,
        private string $name,
        private readonly float $startedAt,
        private readonly bool $full,
        private array $reasons = [],
        private readonly int $maxQueries = 1000,
        private readonly int $maxHttpCalls = 200,
        private readonly int $maxLogs = 100,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function type(): EntryType
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function isFull(): bool
    {
        return $this->full;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function elapsedMs(): float
    {
        return ((microtime(true)) - $this->startedAt) * 1000;
    }

    public function addQuery(QueryRecord $query): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $this->queryCount++;
        $this->dbMs += $query->timeMs;

        if ($this->queryCount <= $this->maxQueries) {
            $this->queries[] = $query;
        }
    }

    public function addHttpCall(HttpCallRecord $call): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        if (count($this->httpCalls) < $this->maxHttpCalls) {
            $this->httpCalls[] = $call;
        } else {
            $this->droppedHttpCalls++;
        }
    }

    public function addLog(LogRecord $log): void
    {
        if (count($this->logs) < $this->maxLogs) {
            $this->logs[] = $log;
        } else {
            $this->droppedLogs++;
        }
    }

    public function cacheHit(): void
    {
        if ($this->endedAt === null) {
            $this->cacheHits++;
        }
    }

    public function cacheMiss(): void
    {
        if ($this->endedAt === null) {
            $this->cacheMisses++;
        }
    }

    public function cacheWrite(): void
    {
        if ($this->endedAt === null) {
            $this->cacheWrites++;
        }
    }

    public function addCacheTime(float $ms): void
    {
        if ($this->endedAt === null) {
            $this->cacheMs += $ms;
        }
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setRoute(?string $route): void
    {
        $this->route = $route;
    }

    public function setRequest(string $method, ?string $uri): void
    {
        $this->method = $method;
        $this->uri = $uri;
    }

    public function setStatusCode(?int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function markFailed(): void
    {
        $this->failed = true;
    }

    public function setUserId(int|string|null $userId): void
    {
        $this->userId = $userId;
    }

    public function setBootstrapMs(float $ms): void
    {
        $this->bootstrapMs = max(0.0, $ms);
    }

    public function addRecordReason(RecordReason $reason): void
    {
        if (! in_array($reason, $this->reasons, true)) {
            $this->reasons[] = $reason;
        }
    }

    public function removeRecordReason(RecordReason $reason): void
    {
        $this->reasons = array_values(array_filter(
            $this->reasons,
            static fn (RecordReason $r): bool => $r !== $reason,
        ));
    }

    public function hasRecordReason(RecordReason $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    public function tag(string $key, string|int|float|bool|null $value): void
    {
        $this->tags[$key] = $value;
    }

    public function meta(string $key, string|int|float|bool|null $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function firstOccurrence(string $key): bool
    {
        if (isset($this->seen[$key]) || $this->seenCount >= self::MAX_SEEN_KEYS) {
            return false;
        }

        $this->seen[$key] = true;
        $this->seenCount++;

        return true;
    }

    /**
     * Freeze duration and memory now (response sent / command finished). Later timing
     * signals are ignored; logs are still accepted.
     */
    public function freeze(): void
    {
        if ($this->endedAt === null) {
            $this->endedAt = microtime(true);
            $this->memoryPeak = memory_get_peak_usage();
        }
    }

    public function isFrozen(): bool
    {
        return $this->endedAt !== null;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    public function toTraceData(): TraceData
    {
        $endedAt = $this->endedAt ?? microtime(true);
        $dropped = $this->queryCount > count($this->queries);

        $metadata = $this->metadata;
        if ($dropped) {
            $metadata['dropped_queries'] = $this->queryCount - count($this->queries);
        }
        if ($this->droppedHttpCalls > 0) {
            $metadata['dropped_http_calls'] = $this->droppedHttpCalls;
        }
        if ($this->droppedLogs > 0) {
            $metadata['dropped_logs'] = $this->droppedLogs;
        }

        return new TraceData(
            id: $this->id,
            type: $this->type,
            name: $this->name,
            startedAt: self::toDateTime($this->startedAt),
            durationMs: round(max(0.0, ($endedAt - $this->startedAt) * 1000), 3),
            method: $this->method,
            uri: $this->uri,
            route: $this->route,
            statusCode: $this->statusCode,
            failed: $this->failed,
            bootstrapMs: round($this->bootstrapMs, 3),
            memoryPeakBytes: $this->memoryPeak ?? memory_get_peak_usage(),
            queries: $this->queries,
            httpCalls: $this->httpCalls,
            cacheHits: $this->cacheHits,
            cacheMisses: $this->cacheMisses,
            cacheWrites: $this->cacheWrites,
            cacheMs: round($this->cacheMs, 3),
            logs: $this->logs,
            userId: $this->userId,
            recordReasons: $this->reasons,
            full: $this->full,
            tags: $this->tags,
            metadata: $metadata,
            totalQueryCount: $dropped ? $this->queryCount : null,
            totalDbMs: $dropped ? $this->dbMs : null,
        );
    }

    private static function toDateTime(float $timestamp): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp));

        return $date === false ? new DateTimeImmutable : $date;
    }
}
