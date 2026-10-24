<?php

declare(strict_types=1);

namespace Anabil\Tracing\Context;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Contracts\TraceContext as TraceContextContract;
use Anabil\Tracing\Contracts\TraceRecorder;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Start / stop orchestration shared by the request, job and command collectors.
 *
 *  - begin(): decides through the RecordingPolicy (only at depth 0; nested units never
 *    decide) with capture paused, so the policy's own cache reads are never recorded.
 *  - end(): freezes the trace and hands it to the TraceRecorder with capture paused.
 *    While an HTTP request is in flight, any trace that completes (orphans, a standalone
 *    unit inside an ignored request) is queued and recorded at terminate, after the
 *    response was sent. Requests themselves only end in the terminating callback.
 *  - linger: a top-level command is frozen at CommandFinished but finalized at terminate
 *    (or before the next unit), so the exception the console kernel reports right after
 *    CommandFinished still lands in the command's trace.
 */
final class UnitLifecycle
{
    private ?RecordingPolicy $policy = null;

    /** @var list<TraceData> */
    private array $pending = [];

    private bool $deferRecording = false;

    private bool $lingering = false;

    private ?string $orphanName = null;

    public function __construct(
        private readonly Container $container,
        private readonly ContextManager $manager,
        private readonly FailureReporter $failures,
    ) {}

    public function manager(): ContextManager
    {
        return $this->manager;
    }

    public function failures(): FailureReporter
    {
        return $this->failures;
    }

    /**
     * Start a unit. Every call must be paired with one end().
     */
    public function begin(EntryCandidate $candidate, ?float $startedAt = null, bool $resetPeakMemory = true): TraceContextContract
    {
        if ($this->lingering) {
            $this->end();
        }

        // Nested, or started by the package's own (paused) work: never a trace of its own.
        if ($this->manager->depth() > 0 || $this->manager->isPaused()) {
            return $this->manager->startUnit($candidate->type, $candidate->name, RecordingDecision::skip());
        }

        $this->flushOrphans();

        return $this->manager->startUnit(
            $candidate->type,
            $candidate->name,
            $this->decide($candidate),
            $startedAt,
            $resetPeakMemory,
        );
    }

    /**
     * @param  bool  $linger  freeze now, finalize at terminate / next unit (top-level units only)
     */
    public function end(bool $linger = false): void
    {
        if ($linger && $this->manager->depth() === 1 && $this->manager->active() !== null) {
            $this->manager->active()->freeze();
            $this->lingering = true;

            return;
        }

        $this->lingering = false;

        try {
            $trace = $this->manager->stop();
        } catch (Throwable $e) {
            $this->manager->reset();
            $this->failures->report($e);

            return;
        }

        if ($trace !== null) {
            $this->dispatch($trace);
        }
    }

    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        try {
            return $this->manager->pause(fn (): RecordingDecision => $this->policy()->decide($candidate));
        } catch (Throwable $e) {
            $this->failures->report($e);

            return RecordingDecision::skip();
        }
    }

    /** An HTTP request is in flight: completed traces wait for terminate. */
    public function deferUntilTerminate(): void
    {
        $this->deferRecording = true;
    }

    /**
     * Terminating callback: finalize a lingering unit, orphans and every queued trace.
     */
    public function terminate(): void
    {
        if ($this->lingering) {
            $this->end();
        }

        $this->deferRecording = false;
        $this->flushOrphans();

        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $trace) {
            $this->record($trace);
        }
    }

    /** Octane / worker boundary: drop any stale state from a previous unit. */
    public function reset(): void
    {
        $this->manager->reset();
        $this->pending = [];
        $this->lingering = false;
        $this->deferRecording = false;
    }

    /**
     * Context receiving logs raised outside any unit (created on first use).
     */
    public function orphan(): TraceContextContract
    {
        $console = $this->runningInConsole();

        return $this->manager->orphan(
            $console ? EntryType::Command : EntryType::Request,
            $this->orphanName ??= $this->defaultOrphanName($console),
        );
    }

    public function flushOrphans(): void
    {
        if (! $this->manager->hasOrphan()) {
            return;
        }

        $trace = $this->manager->stopOrphan();
        // No forcing reason: persisted by the recorder because it carries an error.
        if ($trace !== null) {
            $this->dispatch($trace);
        }
    }

    public function isLingering(): bool
    {
        return $this->lingering;
    }

    private function dispatch(TraceData $trace): void
    {
        if ($this->deferRecording) {
            $this->pending[] = $trace;

            return;
        }

        $this->record($trace);
    }

    private function record(TraceData $trace): void
    {
        try {
            $recorder = $this->container->make(TraceRecorder::class);
            $this->manager->pause(static fn () => $recorder->record($trace));
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    private function policy(): RecordingPolicy
    {
        return $this->policy ??= $this->container->make(RecordingPolicy::class);
    }

    private function runningInConsole(): bool
    {
        return method_exists($this->container, 'runningInConsole') && $this->container->runningInConsole();
    }

    private function defaultOrphanName(bool $console): string
    {
        if (! $console) {
            return 'unattributed';
        }

        $argv = $_SERVER['argv'] ?? null;
        $command = is_array($argv) && isset($argv[1]) && is_string($argv[1]) && ! str_starts_with($argv[1], '-')
            ? $argv[1]
            : null;

        return $command ?? 'artisan';
    }
}
