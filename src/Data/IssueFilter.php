<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use DateTimeImmutable;

/**
 * Filters for listing issues. Null = no filter.
 */
final readonly class IssueFilter
{
    /**
     * @param  string  $sort  last_seen | first_seen | occurrences | severity
     */
    public function __construct(
        public ?IssueType $type = null,
        public ?Severity $severity = null,
        public ?IssueStatus $status = null,
        public ?string $search = null,
        public ?DateTimeImmutable $since = null,
        public string $sort = 'last_seen',
    ) {}
}
