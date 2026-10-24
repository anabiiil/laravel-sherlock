<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\WatchState;
use DateTimeImmutable;

/**
 * Watch windows are not persisted: start() returns a state but nothing is stored.
 */
final class NullWatchWindow implements WatchWindow
{
    public function start(int $minutes, ?string $routePattern = null, int|string|null $userId = null): WatchState
    {
        $now = new DateTimeImmutable;

        return new WatchState($now, $now->modify('+'.max(0, $minutes).' minutes'), $routePattern, $userId);
    }

    public function stop(): void {}

    public function current(): ?WatchState
    {
        return null;
    }

    public function matches(EntryCandidate $candidate): bool
    {
        return false;
    }
}
