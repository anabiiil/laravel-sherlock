<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis;

use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Data\ExplainPlan;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\TraceData;

/**
 * An analyzer that also needs the EXPLAIN plans gathered for the trace
 * (e.g. MissingIndexAnalyzer). The pipeline calls analyzeWithPlans() on it;
 * analyze() alone sees no plans.
 */
interface PlanAwareAnalyzer extends Analyzer
{
    /**
     * @param  array<string, ExplainPlan>  $plans  query fingerprint => plan
     * @return list<Finding>
     */
    public function analyzeWithPlans(TraceData $trace, array $plans): array;
}
