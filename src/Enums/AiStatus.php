<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Enums;

enum AiStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case SkippedBudget = 'skipped_budget';
    case SkippedDisabled = 'skipped_disabled';
    case SkippedSeverity = 'skipped_severity';

    public function isSkipped(): bool
    {
        return match ($this) {
            self::SkippedBudget, self::SkippedDisabled, self::SkippedSeverity => true,
            default => false,
        };
    }

    /**
     * Whether an analysis exists and can be reused.
     */
    public function hasAnalysis(): bool
    {
        return $this === self::Done;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
