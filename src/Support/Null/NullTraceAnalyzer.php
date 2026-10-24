<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\TraceAnalyzer;
use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;

final class NullTraceAnalyzer implements TraceAnalyzer
{
    public function analyze(TraceData $trace): AnalysisResult
    {
        return new AnalysisResult([], Verdict::unknown(), [], $trace);
    }
}
