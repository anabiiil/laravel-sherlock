<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use DateTimeImmutable;

/**
 * Read model of a row in sherlock_traces.
 */
final readonly class StoredTrace
{
    /**
     * @param  array<string, mixed>  $details  redacted extras: uri, route, failed, tags, metadata, verdict, http_calls, logs,
     *                                         findings, lazy_loads, memory_leak
     * @param  string|null  $release  release identifier the trace ran on
     * @param  float|null  $cpuMs  CPU time (user + system) of the unit
     * @param  float|null  $loadAvg  1-minute load average when the unit ended
     * @param  float|null  $queueWaitMs  queue jobs: wait between becoming available and starting
     */
    public function __construct(
        public string $id,
        public EntryType $type,
        public string $name,
        public ?string $method,
        public ?int $statusCode,
        public float $durationMs,
        public float $dbMs,
        public int $dbCount,
        public float $httpMs,
        public int $httpCount,
        public int $cacheHits,
        public int $cacheMisses,
        public float $bootstrapMs,
        public int $memoryPeak,
        public Bottleneck $bottleneck,
        public ?string $verdictReason,
        public RecordReason $recordReason,
        public int|string|null $userId,
        public DateTimeImmutable $createdAt,
        public array $details = [],
        public ?string $release = null,
        public ?float $cpuMs = null,
        public ?float $loadAvg = null,
        public ?int $cpuCores = null,
        public ?int $memoryFreeMb = null,
        public ?float $queueWaitMs = null,
    ) {}

    /** Load average per CPU core (1.0 = every core busy), when both are known. */
    public function loadPerCore(): ?float
    {
        return $this->loadAvg === null || $this->cpuCores === null || $this->cpuCores < 1 ? null : $this->loadAvg / $this->cpuCores;
    }
}
