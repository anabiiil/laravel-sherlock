<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Data\RecordedTrace;

final class NullPersistenceStrategy implements PersistenceStrategy
{
    public function persist(RecordedTrace $trace): void {}
}
