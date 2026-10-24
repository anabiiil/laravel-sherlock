<?php

declare(strict_types=1);

namespace Anabil\Tracing\Storage\Persistence;

use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Data\RecordedTrace;

/**
 * Writes synchronously. The recorder already runs in a terminating callback (after the
 * response was sent / the job or command finished), so nothing here delays the user.
 */
final class AfterResponsePersistence implements PersistenceStrategy
{
    public function __construct(
        private readonly TraceWriter $writer,
    ) {}

    public function persist(RecordedTrace $trace): void
    {
        $this->writer->write($trace);
    }
}
