<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use DateTimeImmutable;

/**
 * Read model of a row in sherlock_releases: when a release identifier was first / last seen
 * by a persisted trace.
 */
final readonly class ReleaseRecord
{
    public function __construct(
        public string $release,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
    ) {}

    /**
     * @return array{release: string, first_seen_at: string, last_seen_at: string}
     */
    public function toArray(): array
    {
        return [
            'release' => $this->release,
            'first_seen_at' => $this->firstSeenAt->format(DATE_ATOM),
            'last_seen_at' => $this->lastSeenAt->format(DATE_ATOM),
        ];
    }
}
