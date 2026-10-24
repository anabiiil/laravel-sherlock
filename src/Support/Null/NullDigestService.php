<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\DigestService;
use DateTimeInterface;

final class NullDigestService implements DigestService
{
    public function isDue(?DateTimeInterface $now = null): bool
    {
        return false;
    }

    public function send(bool $force = false): bool
    {
        return false;
    }
}
