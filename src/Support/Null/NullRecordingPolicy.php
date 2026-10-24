<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;

final class NullRecordingPolicy implements RecordingPolicy
{
    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        return RecordingDecision::skip();
    }
}
