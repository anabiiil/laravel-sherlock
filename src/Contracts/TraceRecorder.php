<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\TraceData;

/**
 * Terminate-time orchestration: analyze, decide whether to persist, hand to the PersistenceStrategy.
 * Must never throw into the host application.
 */
interface TraceRecorder
{
    public function record(TraceData $trace): void;
}
