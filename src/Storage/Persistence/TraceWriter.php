<?php

declare(strict_types=1);

namespace Anabil\Tracing\Storage\Persistence;

use Anabil\Tracing\Contracts\IssueService;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Data\RecordedTrace;
use Anabil\Tracing\Events\TraceRecorded;
use Anabil\Tracing\Storage\Support\QuietReporter;
use Anabil\Tracing\Storage\Support\RecordingPauser;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * The actual write shared by every persistence strategy:
 * store trace -> IssueService::recordFindings -> TraceRecorded. Runs with capture paused
 * and never throws.
 */
final class TraceWriter
{
    public function __construct(
        private readonly TraceRepository $traces,
        private readonly IssueService $issues,
        private readonly Dispatcher $events,
        private readonly RecordingPauser $pauser,
        private readonly QuietReporter $reporter,
    ) {}

    /** Returns the stored trace id, or null when storing failed. */
    public function write(RecordedTrace $trace): ?string
    {
        try {
            return $this->pauser->run(function () use ($trace): string {
                $traceId = $this->traces->store($trace);

                try {
                    $this->issues->recordFindings($traceId, $trace);
                } catch (Throwable $e) {
                    // The trace is stored; a failing issue upsert must not hide it.
                    $this->reporter->report('failed to record issues', $e);
                }

                $this->events->dispatch(new TraceRecorded($traceId, $trace));

                return $traceId;
            });
        } catch (Throwable $e) {
            $this->reporter->report('failed to persist trace', $e);

            return null;
        }
    }
}
