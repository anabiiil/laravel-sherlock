<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\BottleneckClassifier;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;

final class NullBottleneckClassifier implements BottleneckClassifier
{
    public function classify(TraceData $trace): Verdict
    {
        return Verdict::unknown();
    }
}
