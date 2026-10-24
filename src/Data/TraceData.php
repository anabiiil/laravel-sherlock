<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Immutable snapshot of one request / job / command, built at terminate and handed
 * to analyzers, the bottleneck classifier and storage. All values are already redacted.
 */
final readonly class TraceData
{
    /** Total database time in ms (includes queries dropped by capture caps when known). */
    public float $dbMs;

    /** Total outgoing HTTP time in ms. */
    public float $httpMs;

    /**
     * @param  string  $id  ULID generated when the unit started
     * @param  string  $name  route pattern / URI for requests, job class, command name
     * @param  int|null  $statusCode  HTTP status (requests) or exit code (commands)
     * @param  list<QueryRecord>  $queries
     * @param  list<HttpCallRecord>  $httpCalls
     * @param  list<LogRecord>  $logs
     * @param  list<RecordReason>  $recordReasons  reasons known before analysis (local/watch/header/sample)
     * @param  bool  $full  full capture (bindings, source locations) vs lightweight
     * @param  array<string, scalar|null>  $tags  user tags (Sherlock::tag())
     * @param  array<string, scalar|null>  $metadata  e.g. queue, connection, attempts, route action
     * @param  int|null  $totalQueryCount  real count when capture caps dropped records; null = count($queries)
     * @param  float|null  $totalDbMs  real db time when capture caps dropped records; null = sum of $queries
     * @param  string|null  $release  release / deploy identifier (config "sherlock.release")
     * @param  float|null  $cpuMs  CPU time (user + system, getrusage) consumed by the process during the unit
     * @param  float|null  $loadAvg  1-minute system load average when the unit ended
     * @param  int|null  $cpuCores  CPU cores of the server (resolved lazily, only when needed)
     * @param  int|null  $memoryFreeMb  available system memory (Linux MemAvailable), persisted traces only
     * @param  float|null  $queueWaitMs  queue jobs: time between becoming available and starting
     * @param  list<LazyLoadRecord>  $lazyLoads  relations lazy loaded on collections (Model::preventLazyLoading())
     * @param  MemoryLeakSignal|null  $memoryLeak  queue workers: steady memory growth detected after this job
     */
    public function __construct(
        public string $id,
        public EntryType $type,
        public string $name,
        public DateTimeImmutable $startedAt,
        public float $durationMs,
        public ?string $method = null,
        public ?string $uri = null,
        public ?string $route = null,
        public ?int $statusCode = null,
        public bool $failed = false,
        public float $bootstrapMs = 0.0,
        public int $memoryPeakBytes = 0,
        public array $queries = [],
        public array $httpCalls = [],
        public int $cacheHits = 0,
        public int $cacheMisses = 0,
        public int $cacheWrites = 0,
        public float $cacheMs = 0.0,
        public array $logs = [],
        public int|string|null $userId = null,
        public array $recordReasons = [],
        public bool $full = false,
        public array $tags = [],
        public array $metadata = [],
        public ?int $totalQueryCount = null,
        public ?float $totalDbMs = null,
        public ?string $release = null,
        public ?float $cpuMs = null,
        public ?float $loadAvg = null,
        public ?int $cpuCores = null,
        public ?int $memoryFreeMb = null,
        public ?float $queueWaitMs = null,
        public array $lazyLoads = [],
        public ?MemoryLeakSignal $memoryLeak = null,
    ) {
        $db = 0.0;
        foreach ($queries as $query) {
            $db += $query->timeMs;
        }
        $this->dbMs = $totalDbMs ?? $db;

        $http = 0.0;
        foreach ($httpCalls as $call) {
            $http += $call->durationMs;
        }
        $this->httpMs = $http;
    }

    public function queryCount(): int
    {
        return $this->totalQueryCount ?? count($this->queries);
    }

    public function httpCount(): int
    {
        return count($this->httpCalls);
    }

    public function cacheLookups(): int
    {
        return $this->cacheHits + $this->cacheMisses;
    }

    public function cacheMissRatio(): float
    {
        $lookups = $this->cacheLookups();

        return $lookups === 0 ? 0.0 : $this->cacheMisses / $lookups;
    }

    public function memoryPeakMb(): float
    {
        return $this->memoryPeakBytes / 1048576;
    }

    public function hasException(): bool
    {
        foreach ($this->logs as $log) {
            if ($log->isException()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<LogRecord>
     */
    public function exceptions(): array
    {
        return array_values(array_filter($this->logs, static fn (LogRecord $log): bool => $log->isException()));
    }

    /** Load average per CPU core (1.0 = every core busy), when both are known. */
    public function loadPerCore(): ?float
    {
        if ($this->loadAvg === null || $this->cpuCores === null || $this->cpuCores < 1) {
            return null;
        }

        return $this->loadAvg / $this->cpuCores;
    }

    public function hasReason(RecordReason $reason): bool
    {
        return in_array($reason, $this->recordReasons, true);
    }

    /**
     * @param  list<QueryRecord>  $queries
     */
    public function withQueries(array $queries): self
    {
        return $this->copy(['queries' => $queries]);
    }

    /**
     * Server signals resolved after capture (core count, available memory); null keeps the current value.
     */
    public function withServerSignals(?int $cpuCores, ?int $memoryFreeMb = null): self
    {
        return $this->copy([
            'cpuCores' => $cpuCores ?? $this->cpuCores,
            'memoryFreeMb' => $memoryFreeMb ?? $this->memoryFreeMb,
        ]);
    }

    public function withRecordReason(RecordReason $reason): self
    {
        if ($this->hasReason($reason)) {
            return $this;
        }

        return $this->copy(['recordReasons' => [...$this->recordReasons, $reason]]);
    }

    /**
     * @param  array<string, mixed>  $changes  constructor argument name => value
     */
    private function copy(array $changes): self
    {
        $args = [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'startedAt' => $this->startedAt,
            'durationMs' => $this->durationMs,
            'method' => $this->method,
            'uri' => $this->uri,
            'route' => $this->route,
            'statusCode' => $this->statusCode,
            'failed' => $this->failed,
            'bootstrapMs' => $this->bootstrapMs,
            'memoryPeakBytes' => $this->memoryPeakBytes,
            'queries' => $this->queries,
            'httpCalls' => $this->httpCalls,
            'cacheHits' => $this->cacheHits,
            'cacheMisses' => $this->cacheMisses,
            'cacheWrites' => $this->cacheWrites,
            'cacheMs' => $this->cacheMs,
            'logs' => $this->logs,
            'userId' => $this->userId,
            'recordReasons' => $this->recordReasons,
            'full' => $this->full,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'totalQueryCount' => $this->totalQueryCount,
            'totalDbMs' => $this->totalDbMs,
            'release' => $this->release,
            'cpuMs' => $this->cpuMs,
            'loadAvg' => $this->loadAvg,
            'cpuCores' => $this->cpuCores,
            'memoryFreeMb' => $this->memoryFreeMb,
            'queueWaitMs' => $this->queueWaitMs,
            'lazyLoads' => $this->lazyLoads,
            'memoryLeak' => $this->memoryLeak,
        ];

        return new self(...array_merge($args, $changes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'method' => $this->method,
            'uri' => $this->uri,
            'route' => $this->route,
            'status_code' => $this->statusCode,
            'failed' => $this->failed,
            'started_at' => $this->startedAt->format(DateTimeInterface::ATOM),
            'duration_ms' => $this->durationMs,
            'bootstrap_ms' => $this->bootstrapMs,
            'memory_peak' => $this->memoryPeakBytes,
            'db_ms' => $this->dbMs,
            'db_count' => $this->queryCount(),
            'http_ms' => $this->httpMs,
            'http_count' => $this->httpCount(),
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_writes' => $this->cacheWrites,
            'cache_ms' => $this->cacheMs,
            'user_id' => $this->userId,
            'record_reasons' => array_map(static fn (RecordReason $r): string => $r->value, $this->recordReasons),
            'full' => $this->full,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'queries' => array_map(static fn (QueryRecord $q): array => $q->toArray(), $this->queries),
            'http_calls' => array_map(static fn (HttpCallRecord $c): array => $c->toArray(), $this->httpCalls),
            'logs' => array_map(static fn (LogRecord $l): array => $l->toArray(), $this->logs),
            'release' => $this->release,
            'cpu_ms' => $this->cpuMs,
            'load_avg' => $this->loadAvg,
            'cpu_cores' => $this->cpuCores,
            'memory_free_mb' => $this->memoryFreeMb,
            'queue_wait_ms' => $this->queueWaitMs,
            'lazy_loads' => array_map(static fn (LazyLoadRecord $l): array => $l->toArray(), $this->lazyLoads),
            'memory_leak' => $this->memoryLeak?->toArray(),
        ];
    }
}
