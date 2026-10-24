<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Events;

use Anabil\Sherlock\Data\RecordedTrace;

/**
 * Fired after a trace has been stored.
 */
final class TraceRecorded
{
    public function __construct(
        public readonly string $traceId,
        public readonly RecordedTrace $trace,
    ) {}
}
