<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Recording\Policies;

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;

/**
 * mode=off. Never consulted in practice (nothing is registered), kept for completeness.
 */
final class OffPolicy implements RecordingPolicy
{
    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        return RecordingDecision::skip();
    }
}
