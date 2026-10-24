<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\DigestService;
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
