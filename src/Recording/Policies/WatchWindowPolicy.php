<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording\Policies;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Enums\RecordReason;

/**
 * Full capture while a "tracing:watch" window covers the unit. The window lookup is a
 * single memoized cache read (see CacheWatchWindow). A user filter cannot be checked at
 * start (the user is not resolved yet); the request collector re-checks it at terminate.
 */
final class WatchWindowPolicy implements RecordingPolicy
{
    public function __construct(private readonly WatchWindow $window) {}

    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        return $this->window->matches($candidate)
            ? RecordingDecision::full(RecordReason::Watch)
            : RecordingDecision::skip();
    }
}
