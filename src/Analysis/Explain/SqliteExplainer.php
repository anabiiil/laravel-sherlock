<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Explain;

use Illuminate\Database\Connection;
use PDO;

/**
 * SQLite: EXPLAIN QUERY PLAN (rows of id / parent / notused / detail).
 * SQLite has no statement timeout; the plan is computed without running the query.
 */
final class SqliteExplainer extends PdoExplainer
{
    protected function drivers(): array
    {
        return ['sqlite'];
    }

    protected function run(Connection $connection, PDO $pdo, string $sql, array $bindings): ?array
    {
        $rows = $this->execute($connection, $pdo, 'EXPLAIN QUERY PLAN '.$sql, $bindings)->fetchAll(PDO::FETCH_ASSOC);

        $plan = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $plan[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'parent' => (int) ($row['parent'] ?? 0),
                    'detail' => (string) ($row['detail'] ?? ''),
                ];
            }
        }

        return $plan === [] ? null : $plan;
    }
}
