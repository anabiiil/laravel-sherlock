<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;

final class NullRecordingPolicy implements RecordingPolicy
{
    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        return RecordingDecision::skip();
    }
}
