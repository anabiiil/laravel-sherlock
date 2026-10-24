<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Data\IssueRecord;

/**
 * Once-per-issue AI analysis (AiAnalysisService). Never throws: failures are recorded as "failed".
 */
interface AiAnalyzer
{
    /**
     * Analyze (or reuse the stored analysis unless $fresh) and store the result on the issue.
     */
    public function analyze(int|string $issueId, bool $fresh = false): AiAnalysisResult;

    /** Enabled, issue type allowed, severity >= min_severity, not already analyzed (unless $fresh). */
    public function shouldAnalyze(IssueRecord $issue, bool $fresh = false): bool;
}
