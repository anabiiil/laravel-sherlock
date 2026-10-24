<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Explain;

use Anabil\Sherlock\Contracts\PlanInterpreter;
use Anabil\Sherlock\Data\ExplainInsight;

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
