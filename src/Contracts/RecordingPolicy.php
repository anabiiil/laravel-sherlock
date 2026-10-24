<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;

/**
 * Decides at unit start whether / how to capture (Strategy; combined by CompositeRecordingPolicy).
 * Must be cheap: called for every request / job / command.
 */
interface RecordingPolicy
{
    public function decide(EntryCandidate $candidate): RecordingDecision;
}
