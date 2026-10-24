<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Recording\Policies;

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Enums\RecordReason;

/**
 * mode=local: full capture (bindings, source locations) of every unit, always persisted.
 */
final class LocalPolicy implements RecordingPolicy
{
    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        return RecordingDecision::full(RecordReason::Local);
    }
}
