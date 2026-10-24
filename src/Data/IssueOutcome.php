<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Enums\Severity;

/**
 * Result of recording one finding against the issues table.
 */
final readonly class IssueOutcome
{
    /**
     * @param  IssueStatus|null  $status  issue status after recording (null = unknown, e.g. Null repository)
     */
    public function __construct(
        public int|string $issueId,
        public string $fingerprint,
        public IssueType $issueType,
        public Severity $severity,
        public OccurrenceType $occurrence,
        public int $occurrences = 1,
        public ?string $traceId = null,
        public ?IssueStatus $status = null,
    ) {}

    /** Ignored issues only count occurrences: no events, no notifications, no AI. */
    public function isIgnored(): bool
    {
        return $this->status === IssueStatus::Ignored;
    }
}
