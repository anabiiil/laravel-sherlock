<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\AiBudget;

final class NullAiBudget implements AiBudget
{
    public function limit(): int
    {
        return 0;
    }

    public function used(): int
    {
        return 0;
    }

    public function remaining(): int
    {
        return 0;
    }

    public function consume(): bool
    {
        return false;
    }
}
