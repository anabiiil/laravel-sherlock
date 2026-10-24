<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Context;

use Anabil\Sherlock\Contracts\ContextManager as ContextManagerContract;
use Anabil\Sherlock\Contracts\ServerProbe;
use Anabil\Sherlock\Contracts\TraceContext as TraceContextContract;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Support\Null\NullTraceContext;
use Closure;
use Illuminate\Support\Str;

/**
 * Owns the current TraceContext (one per process; Octane / worker safe).
 *
 * Nested units: the outermost unit owns the trace. A unit started while another one is
 * active (a sync job dispatched in a request, Artisan::call() inside a job...) only bumps
 * a depth counter: its queries / logs belong to the outer trace, its stop() just pops the
 * depth and returns null. Callers must pair every start() with one stop(). A start() at
 * depth 0 whose decision does not capture registers nothing, so its stop() is a no-op.
 *
 * Orphans: logs raised outside any unit (scheduler closures, worker loop) go to a
 * standalone "orphan" context, flushed by UnitLifecycle before the next unit / at terminate.
 */
final class ContextManager implements ContextManagerContract
{
    private readonly NullTraceContext $null;

    private ?TraceContext $context = null;

    private ?TraceContext $orphan = null;

    private int $depth = 0;

    private int $paused = 0;

    /**
     * @param  ServerProbe|null  $probe  server signals per unit (CPU time, load average); null = none
     * @param  string|null  $release  release identifier stamped on every trace
     */
    public function __construct(
        private readonly int $maxQueries = 1000,
        private readonly int $maxHttpCalls = 200,
        private readonly int $maxLogs = 100,
        private readonly ?ServerProbe $probe = null,
        private readonly ?string $release = null,
    ) {
        $this->null = new NullTraceContext;
    }

    public function start(EntryType $type, string $name, RecordingDecision $decision, ?float $startedAt = null): TraceContextContract
    {
        return $this->startUnit($type, $name, $decision, $startedAt, $type !== EntryType::Request);
    }

    /**
     * @param  bool  $resetPeakMemory  reset the process memory peak (long-running workers) so the
     *                                 unit's peak is its own; false for FPM requests (peak already per request)
     */
    public function startUnit(EntryType $type, string $name, RecordingDecision $decision, ?float $startedAt = null, bool $resetPeakMemory = true): TraceContextContract
    {
        if ($this->depth > 0) {
            $this->depth++;

            return $this->current();
        }

        if (! $decision->capture) {
            return $this->null;
        }

        if ($resetPeakMemory) {
            memory_reset_peak_usage();
        }

        $this->depth = 1;
        $this->context = $this->newContext($type, $name, $decision->full, $decision->reasons, $startedAt);

        return $this->current();
    }

    public function current(): TraceContextContract
    {
        return $this->paused === 0 && $this->context !== null ? $this->context : $this->null;
    }

    /** The active unit context even while paused / frozen (lifecycle use only). */
    public function active(): ?TraceContext
    {
        return $this->context;
    }

    public function isRecording(): bool
    {
        return $this->paused === 0 && $this->context !== null;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function isPaused(): bool
    {
        return $this->paused > 0;
    }

    public function stop(): ?TraceData
    {
        if ($this->depth > 1) {
            $this->depth--;

            return null;
        }

        $context = $this->context;
        $this->context = null;
        $this->depth = 0;

        return $context?->toTraceData();
    }

    /** Drop the active unit (e.g. a request whose route turned out to be ignored), keep orphans. */
    public function discard(): void
    {
        $this->context = null;
        $this->depth = 0;
    }

    public function reset(): void
    {
        $this->context = null;
        $this->orphan = null;
        $this->depth = 0;
    }

    public function pause(Closure $callback): mixed
    {
        $this->paused++;

        try {
            return $callback();
        } finally {
            $this->paused--;
        }
    }

    /**
     * Pause capture until the paired resume() (a pause that spans two events, e.g. one of
     * the package's own queue jobs, from JobProcessing to JobProcessed).
     */
    public function hold(): void
    {
        $this->paused++;
    }

    public function resume(): void
    {
        if ($this->paused > 0) {
            $this->paused--;
        }
    }

    /** Standalone context for logs raised outside any unit (created on first use). */
    public function orphan(EntryType $type, string $name): TraceContext
    {
        return $this->orphan ??= $this->newContext($type, $name, false, [], null, false);
    }

    public function hasOrphan(): bool
    {
        return $this->orphan !== null;
    }

    public function stopOrphan(): ?TraceData
    {
        $orphan = $this->orphan;
        $this->orphan = null;

        return $orphan?->toTraceData();
    }

    /**
     * @param  list<\Anabil\Sherlock\Enums\RecordReason>  $reasons
     */
    private function newContext(EntryType $type, string $name, bool $full, array $reasons, ?float $startedAt, bool $measure = true): TraceContext
    {
        return new TraceContext(
            (string) Str::ulid(),
            $type,
            $name,
            $startedAt ?? microtime(true),
            $full,
            $reasons,
            $this->maxQueries,
            $this->maxHttpCalls,
            $this->maxLogs,
            $measure ? $this->probe : null,
            $this->release,
        );
    }
}
