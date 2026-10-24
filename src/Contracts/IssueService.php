<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\IssueOutcome;
use Anabil\Tracing\Data\RecordedTrace;
use Anabil\Tracing\Enums\IssueStatus;

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
