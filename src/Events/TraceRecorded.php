<?php

declare(strict_types=1);

namespace Anabil\Tracing\Events;

use Anabil\Tracing\Data\RecordedTrace;

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
