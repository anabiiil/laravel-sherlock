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
    /**
     * @param  array<string, mixed>  $details  redacted extras: uri, route, failed, tags, metadata, verdict, http_calls, logs, findings
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
    ) {}
}
