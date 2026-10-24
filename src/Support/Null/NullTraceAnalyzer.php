<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Data\AnalysisResult;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;

final class NullTraceAnalyzer implements TraceAnalyzer
{
    public function analyze(TraceData $trace): AnalysisResult
    {
        return new AnalysisResult([], Verdict::unknown(), [], $trace);
    }
}
