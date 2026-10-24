<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Data\RecordedTrace;
use Anabil\Tracing\Enums\IssueStatus;

final class NullIssueService implements IssueService
{
    public function recordFindings(string $traceId, RecordedTrace $trace): array
    {
        return [];
    }

    public function changeStatus(int|string $issueId, IssueStatus $status): void {}
}
