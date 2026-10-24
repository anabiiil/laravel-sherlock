<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Storage\Persistence;

use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Jobs\PersistTraceJob;
use Anabil\Sherlock\Storage\Support\QuietReporter;
use Anabil\Sherlock\Storage\Support\RecordingPauser;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

/**
 * Hands the trace to a PersistTraceJob on config("sherlock.queue"). Dispatching runs with
 * capture paused (a database queue driver would otherwise record its own INSERT).
 */
final class QueuedPersistence implements PersistenceStrategy
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly RecordingPauser $pauser,
        private readonly QuietReporter $reporter,
    ) {}

    public function persist(RecordedTrace $trace): void
    {
        try {
            $this->pauser->run(fn (): mixed => $this->bus->dispatch(new PersistTraceJob($trace)));
        } catch (Throwable $e) {
            $this->reporter->report('failed to queue trace', $e);
        }
    }
}
