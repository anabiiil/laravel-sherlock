<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\OccurrenceType;
use Anabil\Tracing\Enums\Severity;

/**
 * Result of recording one finding against the issues table.
 */
final readonly class IssueOutcome
{
    public function __construct(
        public int|string $issueId,
        public string $fingerprint,
        public IssueType $issueType,
        public Severity $severity,
        public OccurrenceType $occurrence,
        public int $occurrences = 1,
        public ?string $traceId = null,
    ) {}
}
