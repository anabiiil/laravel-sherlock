<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Enums;

/**
 * What an occurrence means for its issue: first time seen, repeat, or a resolved issue coming back.
 */
enum OccurrenceType: string
{
    case New = 'new';
    case Recurring = 'recurring';
    case Regressed = 'regressed';

    /**
     * New and regressed occurrences trigger AI analysis and immediate notification.
     */
    public function isNoteworthy(): bool
    {
        return $this !== self::Recurring;
    }
}
