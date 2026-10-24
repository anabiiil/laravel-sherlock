<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Context;

use Anabil\Sherlock\Contracts\ServerProbe;
use Anabil\Sherlock\Contracts\TraceContext as TraceContextContract;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\LazyLoadRecord;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\MemoryLeakSignal;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use Closure;
use DateTimeImmutable;
use Throwable;

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
    /** Distinct SQL strings remembered for the first / second-occurrence backtrace rules. */
    public const MAX_SEEN_KEYS = 2000;

    /** Distinct lazy-loaded relations remembered per trace. */
    public const MAX_LAZY_LOADS = 100;

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

    /** @var array<string, int> key => occurrences seen (capped at 2) */
    private array $seen = [];

    private int $seenCount = 0;

    private ?float $endedAt = null;

    private ?int $memoryPeak = null;

    private ?float $cpuStart = null;

    private ?float $cpuEnd = null;

    private ?float $queueWaitMs = null;

    /** @var array<string, array{0: string, 1: string, 2: int, 3: string|null}> "Model::relation" => [model, relation, count, table] */
    private array $lazyLoads = [];

    private ?MemoryLeakSignal $memoryLeak = null;

    /**
     * @param  float  $startedAt  microtime(true) of the unit start
     * @param  list<RecordReason>  $reasons
     * @param  ServerProbe|null  $probe  CPU time at start / end and load average at the end (null = not measured)
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
        private readonly ?ServerProbe $probe = null,
        private readonly ?string $release = null,
    ) {
        $this->cpuStart = $probe?->cpuTimeMs();
    }

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

    /**
     * Record a lazy-loading violation (O(1), deduplicated per "Model::relation"). $table resolves the
     * related table once, on the first occurrence only.
     *
     * @param  (Closure(): ?string)|null  $table
     */
    public function addLazyLoad(string $model, string $relation, ?Closure $table = null): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $key = $model.'::'.$relation;
        if (isset($this->lazyLoads[$key])) {
            $this->lazyLoads[$key][2]++;

            return;
        }

        if (count($this->lazyLoads) >= self::MAX_LAZY_LOADS) {
            return;
        }

        $resolved = null;
        if ($table !== null) {
            try {
                $resolved = $table();
            } catch (Throwable) {
                $resolved = null;
            }
        }

        $this->lazyLoads[$key] = [$model, $relation, 1, $resolved];
    }

    public function setQueueWaitMs(?float $ms): void
    {
        $this->queueWaitMs = $ms === null ? null : max(0.0, $ms);
    }

    public function setMemoryLeak(?MemoryLeakSignal $signal): void
    {
        $this->memoryLeak = $signal;
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

        $this->seen[$key] = 1;
        $this->seenCount++;

        return true;
    }

    public function secondOccurrence(string $key): bool
    {
        $seen = $this->seen[$key] ?? 0;
        if ($seen >= 2) {
            return false;
        }

        if ($seen === 0) {
            if ($this->seenCount >= self::MAX_SEEN_KEYS) {
                return false;
            }
            $this->seenCount++;
        }

        $this->seen[$key] = $seen + 1;

        return $seen === 1;
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
            $this->cpuEnd = $this->probe?->cpuTimeMs();
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

        $cpuEnd = $this->cpuEnd ?? $this->probe?->cpuTimeMs();
        $cpuMs = $cpuEnd !== null && $this->cpuStart !== null ? round(max(0.0, $cpuEnd - $this->cpuStart), 3) : null;

        $lazyLoads = [];
        foreach ($this->lazyLoads as [$model, $relation, $count, $table]) {
            $lazyLoads[] = new LazyLoadRecord($model, $relation, $count, $table);
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
            release: $this->release,
            cpuMs: $cpuMs,
            loadAvg: $this->probe?->loadAverage(),
            queueWaitMs: $this->queueWaitMs,
            lazyLoads: $lazyLoads,
            memoryLeak: $this->memoryLeak,
        );
    }

    private static function toDateTime(float $timestamp): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp));

        return $date === false ? new DateTimeImmutable : $date;
    }
}
