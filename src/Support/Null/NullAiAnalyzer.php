<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Data\IssueRecord;
use Anabil\Tracing\Enums\AiStatus;

final class NullAiAnalyzer implements AiAnalyzer
{
    public function analyze(int|string $issueId, bool $fresh = false): AiAnalysisResult
    {
        return AiAnalysisResult::skipped(AiStatus::SkippedDisabled);
    }

    public function shouldAnalyze(IssueRecord $issue, bool $fresh = false): bool
    {
        return false;
    }
}
