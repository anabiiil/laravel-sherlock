<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\Severity;

/**
 * Output of the analysis stage for one trace.
 */
final readonly class AnalysisResult
{
    /**
     * @param  list<Finding>  $findings
     * @param  array<string, ExplainPlan>  $explainPlans  query fingerprint => plan
     * @param  TraceData|null  $trace  the trace enriched during analysis (normalized queries); null = unchanged
     */
    public function __construct(
        public array $findings,
        public Verdict $verdict,
        public array $explainPlans = [],
        public ?TraceData $trace = null,
    ) {}

    public static function empty(): self
    {
        return new self([], Verdict::unknown());
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }

    public function highestSeverity(): ?Severity
    {
        $highest = null;
        foreach ($this->findings as $finding) {
            $highest = $highest === null ? $finding->severity : Severity::max($highest, $finding->severity);
        }

        return $highest;
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->type->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any finding reaches $severity, or any error finding exists.
     */
    public function crosses(Severity $severity): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->type->isError() || $finding->severity->isAtLeast($severity)) {
                return true;
            }
        }

        return false;
    }
}
