<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis;

use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\TraceData;

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
