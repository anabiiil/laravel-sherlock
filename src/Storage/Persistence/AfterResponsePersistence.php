<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Storage\Persistence;

use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Data\RecordedTrace;

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
