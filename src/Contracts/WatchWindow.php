<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\WatchState;

/**
 * Cache-backed temporary full tracing ("sherlock:watch").
 */
interface WatchWindow
{
    public function start(int $minutes, ?string $routePattern = null, int|string|null $userId = null): WatchState;

    public function stop(): void;

    /** The active window, or null when none / expired. */
    public function current(): ?WatchState;

    /** Whether the active window covers this unit (route pattern / user filters). */
    public function matches(EntryCandidate $candidate): bool;
}
