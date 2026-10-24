<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\PlanInterpreter;

final class NullPlanInterpreter implements PlanInterpreter
{
    public function interpret(string $driver, array $raw): array
    {
        return [];
    }
}
