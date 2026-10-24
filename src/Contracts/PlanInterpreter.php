<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\ExplainInsight;

/**
 * Turns a raw EXPLAIN plan into human readable insights.
 */
interface PlanInterpreter
{
    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<ExplainInsight>
     */
    public function interpret(string $driver, array $raw): array;
}
