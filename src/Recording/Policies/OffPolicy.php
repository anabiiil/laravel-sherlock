<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording\Policies;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;

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
