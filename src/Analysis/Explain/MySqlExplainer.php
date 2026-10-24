<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis\Explain;

use Illuminate\Database\Connection;
use PDO;

/**
 * MySQL / MariaDB: EXPLAIN FORMAT=JSON (never ANALYZE).
 *
 * Statement timeout: MySQL gets a MAX_EXECUTION_TIME optimizer hint, MariaDB runs
 * the statement under SET STATEMENT max_statement_time=... FOR.
 */
final class MySqlExplainer extends PdoExplainer
{
    protected function drivers(): array
    {
        return ['mysql', 'mariadb'];
    }

    protected function run(Connection $connection, PDO $pdo, string $sql, array $bindings): ?array
    {
        $statement = $this->isMariaDb($connection, $pdo)
            ? sprintf('SET STATEMENT max_statement_time=%s FOR EXPLAIN FORMAT=JSON %s', $this->seconds(), $sql)
            : 'EXPLAIN FORMAT=JSON '.$this->withTimeoutHint($sql);

        $result = $this->execute($connection, $pdo, $statement, $bindings);
        $json = $result->fetchColumn();
        $result->closeCursor();

        return self::decode($json);
    }

    private function isMariaDb(Connection $connection, PDO $pdo): bool
    {
        if ($connection->getDriverName() === 'mariadb') {
            return true;
        }

        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) && stripos($version, 'mariadb') !== false;
    }

    private function withTimeoutHint(string $sql): string
    {
        if ($this->timeoutMs <= 0) {
            return $sql;
        }

        // Only a leading SELECT (not a WITH / parenthesized query) can carry the hint.
        return (string) preg_replace('/^select\b/i', 'SELECT /*+ MAX_EXECUTION_TIME('.$this->timeoutMs.') */', $sql, 1);
    }

    private function seconds(): string
    {
        return rtrim(rtrim(sprintf('%.3F', max($this->timeoutMs, 1) / 1000), '0'), '.');
    }
}
