<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use DateTimeInterface;

interface DigestService
{
    /** Enabled and "every_days" have passed since the last digest. */
    public function isDue(?DateTimeInterface $now = null): bool;

    /** Build and send the digest; returns whether it was sent. */
    public function send(bool $force = false): bool;
}
