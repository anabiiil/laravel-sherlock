<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\WatchState;

/**
 * Cache-backed temporary full tracing ("tracing:watch").
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
