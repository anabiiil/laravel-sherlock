<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;

final class NullBottleneckClassifier implements BottleneckClassifier
{
    public function classify(TraceData $trace): Verdict
    {
        return Verdict::unknown();
    }
}
