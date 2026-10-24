<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\EntryType;
use DateTimeImmutable;

/**
 * Filters for listing traces. Null = no filter.
 */
final readonly class TraceFilter
{
    /**
     * @param  string  $sort  created_at | duration
     */
    public function __construct(
        public ?EntryType $type = null,
        public ?string $name = null,
        public ?Bottleneck $bottleneck = null,
        public ?float $minDurationMs = null,
        public ?DateTimeImmutable $since = null,
        public int|string|null $issueId = null,
        public string $sort = 'created_at',
    ) {}
}
