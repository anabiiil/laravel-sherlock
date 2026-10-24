<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\IssueService;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Enums\IssueStatus;

final class NullIssueService implements IssueService
{
    public function recordFindings(string $traceId, RecordedTrace $trace): array
    {
        return [];
    }

    public function changeStatus(int|string $issueId, IssueStatus $status): void {}
}
