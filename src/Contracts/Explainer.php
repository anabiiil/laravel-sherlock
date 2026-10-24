<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\QueryRecord;

/**
 * Per-driver EXPLAIN strategy (mysql/mariadb, pgsql, sqlite). Read-only, SELECT only,
 * never EXPLAIN ANALYZE. Returns null when the query cannot be explained.
 */
interface Explainer
{
    /**
     * @param  string  $driver  PDO driver name, e.g. "mysql", "mariadb", "pgsql", "sqlite"
     */
    public function supports(string $driver): bool;

    public function explain(QueryRecord $query): ?ExplainPlan;
}
