<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Enums\IssueStatus;

/**
 * Turns findings into issues (new / recurring / regressed), fires IssueDetected / IssueRegressed
 * and dispatches the AnalyzeIssueJob -> NotifyIssueJob chain.
 */
interface IssueService
{
    /**
     * @return list<IssueOutcome>
     */
    public function recordFindings(string $traceId, RecordedTrace $trace): array;

    public function changeStatus(int|string $issueId, IssueStatus $status): void;
}
