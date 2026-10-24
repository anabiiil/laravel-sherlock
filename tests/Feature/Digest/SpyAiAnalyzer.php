<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Digest;

use Anabil\Tracing\Contracts\AiAnalyzer;
use Anabil\Tracing\Data\AiAnalysisResult;
use Anabil\Tracing\Data\IssueRecord;
use RuntimeException;

/**
 * Fails loudly (and counts) if anything asks the AI for an analysis.
 */
final class SpyAiAnalyzer implements AiAnalyzer
{
    public int $calls = 0;

    public function analyze(int|string $issueId, bool $fresh = false): AiAnalysisResult
    {
        $this->calls++;

        throw new RuntimeException('The digest must never call the AI.');
    }

    public function shouldAnalyze(IssueRecord $issue, bool $fresh = false): bool
    {
        $this->calls++;

        return false;
    }
}
