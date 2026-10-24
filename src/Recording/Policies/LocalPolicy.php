<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording\Policies;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Enums\RecordReason;

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
