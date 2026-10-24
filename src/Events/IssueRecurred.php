<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Events;

use Anabil\Sherlock\Data\IssueOutcome;

/**
 * Fired when an open issue occurs again (not for ignored issues). Hook for throttled
 * re-notifications ("occurred 340 times in the last hour").
 */
final class IssueRecurred
{
    public function __construct(
        public readonly IssueOutcome $outcome,
    ) {}
}
