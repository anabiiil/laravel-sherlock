<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

/**
 * Aggregated statistics of one query fingerprint over a period.
 */
final readonly class QueryStat
{
    public function __construct(
        public string $fingerprint,
        public string $normalizedSql,
        public int $count,
        public float $totalMs,
        public float $avgMs,
        public float $maxMs,
    ) {}
}
