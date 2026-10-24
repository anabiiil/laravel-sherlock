<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;

/**
 * Decides at unit start whether / how to capture (Strategy; combined by CompositeRecordingPolicy).
 * Must be cheap: called for every request / job / command.
 */
interface RecordingPolicy
{
    public function decide(EntryCandidate $candidate): RecordingDecision;
}
