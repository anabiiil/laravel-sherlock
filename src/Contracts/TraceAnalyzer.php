<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\AnalysisResult;
use Anabil\Tracing\Data\TraceData;

/**
 * Whole analysis stage: normalize/fingerprint queries, run the Analyzer pipeline,
 * EXPLAIN slow selects, classify the bottleneck.
 */
interface TraceAnalyzer
{
    public function analyze(TraceData $trace): AnalysisResult;
}
