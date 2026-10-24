<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\RecordedTrace;

/**
 * How a recorded trace reaches storage: after the response (terminating) or via a queued job.
 */
interface PersistenceStrategy
{
    public function persist(RecordedTrace $trace): void;
}
