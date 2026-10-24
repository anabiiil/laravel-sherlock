<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Closure;

/**
 * Owns the current TraceContext. Octane / worker safe: every unit starts a fresh
 * context and stop()/reset() always clears state.
 */
interface ContextManager
{
    /**
     * Start a new unit. Returns a NullTraceContext when $decision does not capture.
     *
     * @param  float|null  $startedAt  microtime(true) of the real start (e.g. LARAVEL_START)
     */
    public function start(EntryType $type, string $name, RecordingDecision $decision, ?float $startedAt = null): TraceContext;

    /** The active context, or a NullTraceContext. Never null. */
    public function current(): TraceContext;

    public function isRecording(): bool;

    /** Freeze and clear the current context. Null when nothing was recorded. */
    public function stop(): ?TraceData;

    /** Drop the current context without producing a trace. */
    public function reset(): void;

    /**
     * Run $callback with capture suspended (current() is a NullTraceContext meanwhile).
     * Used for the package's own work (persisting, cache lookups) so it is never recorded.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function pause(Closure $callback): mixed;
}
