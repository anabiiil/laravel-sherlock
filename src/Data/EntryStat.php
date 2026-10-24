<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\EntryType;

/**
 * Aggregated statistics of one route / job / command over a period.
 */
final readonly class EntryStat
{
    public function __construct(
        public EntryType $type,
        public string $name,
        public int $count,
        public float $avgMs,
        public float $maxMs,
        public float $avgDbMs = 0.0,
        public float $avgQueries = 0.0,
    ) {}
}
