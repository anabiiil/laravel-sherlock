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
