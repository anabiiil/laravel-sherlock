<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\EntryType;

/**
 * One entry point (route / job / command) compared between two releases.
 *
 * $basis tells which population the numbers come from: "sample" (record_reason = sample
 * traces only: unbiased, used when both releases have enough of them) or "all" (every persisted
 * trace: in production biased towards slow traces, since threshold traces are always stored).
 */
final readonly class EntryDelta
{
    public const SLOWER = 'slower';

    public const FASTER = 'faster';

    public const UNCHANGED = 'unchanged';

    public const NEW = 'new';

    public const GONE = 'gone';

    public const BASIS_SAMPLE = 'sample';

    public const BASIS_ALL = 'all';

    /**
     * @param  string  $status  slower | faster | unchanged | new | gone
     * @param  float|null  $deltaAvgMs  current avg - previous avg (null when one side is missing)
     * @param  float|null  $deltaRatio  deltaAvgMs / previous avg (0.25 = 25% slower)
     */
    public function __construct(
        public EntryType $type,
        public string $name,
        public ?EntryStat $current,
        public ?EntryStat $previous,
        public string $status,
        public string $basis = self::BASIS_ALL,
        public ?float $deltaAvgMs = null,
        public ?float $deltaRatio = null,
        public ?float $deltaAvgDbMs = null,
        public ?float $deltaAvgQueries = null,
    ) {}

    public function isSlower(): bool
    {
        return $this->status === self::SLOWER;
    }

    public function isFaster(): bool
    {
        return $this->status === self::FASTER;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stat = static fn (?EntryStat $s): ?array => $s === null ? null : [
            'count' => $s->count, 'avg_ms' => $s->avgMs, 'max_ms' => $s->maxMs, 'avg_db_ms' => $s->avgDbMs, 'avg_queries' => $s->avgQueries,
        ];

        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'status' => $this->status,
            'basis' => $this->basis,
            'current' => $stat($this->current),
            'previous' => $stat($this->previous),
            'delta_avg_ms' => $this->deltaAvgMs,
            'delta_ratio' => $this->deltaRatio,
            'delta_avg_db_ms' => $this->deltaAvgDbMs,
            'delta_avg_queries' => $this->deltaAvgQueries,
        ];
    }
}
