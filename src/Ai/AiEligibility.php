<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai;

use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Illuminate\Contracts\Config\Repository;

/**
 * Whether an issue may be analyzed by AI, from config only (no I/O):
 *  - ai.enabled off, or the issue kind turned off (ai.analyze.exceptions / performance) -> skipped_disabled
 *  - severity below ai.min_severity -> skipped_severity
 *  - otherwise null (eligible; the daily budget is checked at analysis time).
 *
 * Shared by the AI analyzer and the issue service, which uses it to store an honest initial
 * ai_status instead of "pending" for issues that will never be analyzed.
 */
final class AiEligibility
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function skipReason(IssueType $type, Severity $severity): ?AiStatus
    {
        if (! $this->config->get('sherlock.ai.enabled', false)) {
            return AiStatus::SkippedDisabled;
        }

        $toggle = $type->isError() ? 'exceptions' : 'performance';
        if (! $this->config->get("sherlock.ai.analyze.{$toggle}", $toggle === 'performance')) {
            return AiStatus::SkippedDisabled;
        }

        $min = Severity::fromConfig($this->config->get('sherlock.ai.min_severity'), Severity::Critical);

        return $severity->isAtLeast($min) ? null : AiStatus::SkippedSeverity;
    }
}
