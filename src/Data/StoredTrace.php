<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;
use DateTimeImmutable;

/**
 * Read model of a row in tracing_traces.
 */
final readonly class StoredTrace
{
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
    ) {}
}
