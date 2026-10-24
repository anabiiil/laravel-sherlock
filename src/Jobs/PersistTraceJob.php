<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Jobs;

use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Storage\Persistence\TraceWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Persists a recorded trace on the queue (storage.persistence = "queue").
 *
 * The payload is the RecordedTrace itself: a graph of readonly value objects, enums,
 * DateTimeImmutable and scalar arrays (no closures, models or resources), already
 * redacted at capture time, so native serialization is safe and lossless.
 * One try only: the writer never throws, and a retry would duplicate query rows.
 */
final class PersistTraceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly RecordedTrace $trace,
    ) {
        QueueTarget::apply($this);
    }

    public function handle(TraceWriter $writer): void
    {
        $writer->write($this->trace);
    }
}
