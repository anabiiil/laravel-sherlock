<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\AiAnalyzer;
use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\AiStatus;

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
