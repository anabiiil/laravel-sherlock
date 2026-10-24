<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Data\RecordedTrace;

final class NullPersistenceStrategy implements PersistenceStrategy
{
    public function persist(RecordedTrace $trace): void {}
}
