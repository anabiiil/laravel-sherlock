<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\TraceData;

/**
 * One detection rule (Strategy). Receives a trace whose queries are already
 * normalized + fingerprinted. Runs at terminate, after the response is sent.
 */
interface Analyzer
{
    /**
     * @return list<Finding>
     */
    public function analyze(TraceData $trace): array;
}
