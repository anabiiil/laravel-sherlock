<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

/**
 * Daily AI call budget (ai.daily_limit).
 */
interface AiBudget
{
    public function limit(): int;

    public function used(): int;

    public function remaining(): int;

    /** Atomically take one unit; false when over budget. */
    public function consume(): bool;
}
