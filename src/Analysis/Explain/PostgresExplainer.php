<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Explain;

use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * PostgreSQL: EXPLAIN (FORMAT JSON), NEVER ANALYZE (the query is not executed).
 *
 * Runs in its own READ ONLY transaction with SET LOCAL statement_timeout, always
 * rolled back. The base class guarantees no application transaction is open.
 */
final class PostgresExplainer extends PdoExplainer
{
    protected function drivers(): array
    {
        return ['pgsql'];
    }

    protected function run(Connection $connection, PDO $pdo, string $sql, array $bindings): ?array
    {
        $pdo->beginTransaction();

        try {
            $pdo->exec('SET TRANSACTION READ ONLY');
            if ($this->timeoutMs > 0) {
                $pdo->exec('SET LOCAL statement_timeout = '.$this->timeoutMs);
            }

            $result = $this->execute($connection, $pdo, 'EXPLAIN (FORMAT JSON) '.$sql, $bindings);
            $json = $result->fetchColumn();
            $result->closeCursor();
        } finally {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
                // nothing to roll back
            }
        }

        return self::decode($json);
    }
}
