<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * An active "sherlock:watch" window (temporary full tracing).
 */
final readonly class WatchState
{
    /**
     * @param  string|null  $routePattern  Str::is() pattern on the request path, e.g. "api/orders*"
     */
    public function __construct(
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $expiresAt,
        public ?string $routePattern = null,
        public int|string|null $userId = null,
    ) {}

    public function isActive(?DateTimeInterface $now = null): bool
    {
        return ($now ?? new DateTimeImmutable) < $this->expiresAt;
    }

    public function remainingSeconds(?DateTimeInterface $now = null): int
    {
        return max(0, $this->expiresAt->getTimestamp() - ($now ?? new DateTimeImmutable)->getTimestamp());
    }

    /**
     * @return array{started_at: int, expires_at: int, route: string|null, user: int|string|null}
     */
    public function toArray(): array
    {
        return [
            'started_at' => $this->startedAt->getTimestamp(),
            'expires_at' => $this->expiresAt->getTimestamp(),
            'route' => $this->routePattern,
            'user' => $this->userId,
        ];
    }

    /**
     * @param  array{started_at: int, expires_at: int, route?: string|null, user?: int|string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (new DateTimeImmutable)->setTimestamp($data['started_at']),
            (new DateTimeImmutable)->setTimestamp($data['expires_at']),
            $data['route'] ?? null,
            $data['user'] ?? null,
        );
    }
}
