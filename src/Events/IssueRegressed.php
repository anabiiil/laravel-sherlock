<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Events;

use Anabil\Sherlock\Data\IssueOutcome;

/**
 * Fired when a resolved issue occurs again.
 */
final class IssueRegressed
{
    public function __construct(
        public readonly IssueOutcome $outcome,
    ) {}
}
