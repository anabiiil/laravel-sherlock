<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Explain;

use Anabil\Tracing\Contracts\PlanInterpreter;
use Anabil\Tracing\Data\ExplainInsight;

/**
 * A plan interpreter that can also read the explained (normalized) SQL, e.g. to
 * suggest index columns from its WHERE / JOIN / ORDER BY clauses.
 */
interface SqlAwarePlanInterpreter extends PlanInterpreter
{
    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<ExplainInsight>
     */
    public function interpretFor(string $driver, array $raw, ?string $sql): array;
}
