<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Explain;

use Anabil\Tracing\Contracts\Explainer;
use Anabil\Tracing\Data\ExplainPlan;
use Anabil\Tracing\Data\QueryRecord;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Template Method base for the driver explainers.
 *
 * Runs on the connection name the query used, straight on its (read) PDO: no
 * QueryExecuted event is fired, so EXPLAIN never shows up in traces, Telescope or
 * user listeners. Skips connections inside an open transaction (a failing statement
 * must never affect application work). Any error yields null; never throws.
 */
abstract class PdoExplainer implements Explainer
{
    public function __construct(
        protected readonly ConnectionResolverInterface $connections,
        protected readonly int $timeoutMs = 1000,
    ) {}

    /**
     * @return list<string>
     */
    abstract protected function drivers(): array;

    /**
     * Run EXPLAIN and return the decoded raw plan, or null.
     *
     * @param  array<int|string, scalar|null>  $bindings
     * @return array<array-key, mixed>|null
     */
    abstract protected function run(Connection $connection, PDO $pdo, string $sql, array $bindings): ?array;

    public function supports(string $driver): bool
    {
        return in_array(strtolower($driver), $this->drivers(), true);
    }

    public function explain(QueryRecord $query): ?ExplainPlan
    {
        if (! $this->supports($query->driver) || ! ExplainGuard::isEligible($query)) {
            return null;
        }

        try {
            $connection = $this->connections->connection($query->connection);
            if (! $connection instanceof Connection
                || ! $this->supports($connection->getDriverName())
                || $connection->transactionLevel() > 0) {
                return null;
            }

            $pdo = $connection->getReadPdo();
            if ($pdo->inTransaction()) {
                return null;
            }

            $raw = $this->run($connection, $pdo, trim($query->sql), $query->bindingsForExplain() ?? []);
        } catch (Throwable) {
            return null;
        }

        if ($raw === null || $raw === []) {
            return null;
        }

        return new ExplainPlan($query->connection, $connection->getDriverName(), $query->normalizedSql ?? $query->sql, $raw);
    }

    /**
     * @param  array<int|string, scalar|null>  $bindings
     */
    protected function execute(Connection $connection, PDO $pdo, string $sql, array $bindings): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        $connection->bindValues($statement, $connection->prepareBindings($bindings));
        $statement->execute();

        return $statement;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    protected static function decode(mixed $json): ?array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
