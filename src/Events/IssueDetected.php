<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Events;

use Anabil\Sherlock\Data\IssueOutcome;

/**
 * Fired when a finding creates a new issue.
 */
final class IssueDetected
{
    public function __construct(
        public readonly IssueOutcome $outcome,
    ) {}
}
