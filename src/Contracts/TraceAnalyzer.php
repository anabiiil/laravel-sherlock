<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\TraceData;

/**
 * Whole analysis stage: normalize/fingerprint queries, run the Analyzer pipeline,
 * EXPLAIN slow selects, classify the bottleneck.
 */
interface TraceAnalyzer
{
    public function analyze(TraceData $trace): AnalysisResult;
}
