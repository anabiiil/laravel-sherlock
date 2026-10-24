<?php

declare(strict_types=1);

namespace Anabil\Tracing\Events;

use Anabil\Tracing\Data\IssueOutcome;

/**
 * Fired when a resolved issue occurs again.
 */
final class IssueRegressed
{
    public function __construct(
        public readonly IssueOutcome $outcome,
    ) {}
}
