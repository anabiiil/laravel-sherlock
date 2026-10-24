<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;

interface BottleneckClassifier
{
    public function classify(TraceData $trace): Verdict;
}
