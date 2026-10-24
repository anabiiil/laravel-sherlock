<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use DateTimeImmutable;

/**
 * Read model of a row in sherlock_queries.
 */
final readonly class StoredQuery
{
    /**
     * @param  array<int|string, scalar|null>|null  $bindings  redacted; null when not stored (production)
     * @param  float  $offsetMs  start time relative to the trace start (timeline)
     */
    public function __construct(
        public int|string $id,
        public string $traceId,
        public string $fingerprint,
        public string $normalizedSql,
        public ?array $bindings,
        public float $durationMs,
        public string $connection,
        public ?string $file,
        public ?int $line,
        public DateTimeImmutable $createdAt,
        public float $offsetMs = 0.0,
    ) {}
}
