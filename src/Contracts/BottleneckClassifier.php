<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;

interface BottleneckClassifier
{
    public function classify(TraceData $trace): Verdict;
}
