<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\PlanInterpreter;

final class NullPlanInterpreter implements PlanInterpreter
{
    public function interpret(string $driver, array $raw): array
    {
        return [];
    }
}
