<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\TraceData;

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
