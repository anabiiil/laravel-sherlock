<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Explain;

use Anabil\Tracing\Analysis\Support\Describe;
use Anabil\Tracing\Analysis\Support\Sql;
use Anabil\Tracing\Data\ExplainInsight;
use Anabil\Tracing\Enums\ExplainInsightType;
use Anabil\Tracing\Enums\Severity;

/**
 * Turns raw EXPLAIN output into insights.
 *
 * Severity of scans follows the estimated rows: >= criticalRows critical, >= warningRows
 * warning, otherwise info (small tables are cheap to scan). PostgreSQL only estimates
 * the rows a node returns (not the rows it reads), so a filtered sequential scan is at
 * least a warning (only slow queries are explained). SQLite gives no estimates: a
 * filtered full scan is a warning.
 *
 *  - MySQL / MariaDB (FORMAT=JSON): access_type ALL (full scan / unused possible keys),
 *    full index scans, filesort, temporary tables, very large row estimates
 *  - PostgreSQL (FORMAT JSON): Seq Scan, Sort
 *  - SQLite (QUERY PLAN): SCAN without index, USE TEMP B-TREE
 */
final class DefaultPlanInterpreter implements SqlAwarePlanInterpreter
{
    private const MAX_DEPTH = 64;

    public function __construct(
        private readonly int $warningRows = 1000,
        private readonly int $criticalRows = 100000,
        private readonly IndexSuggester $suggester = new IndexSuggester,
    ) {}

    public function interpret(string $driver, array $raw): array
    {
        return $this->interpretFor($driver, $raw, null);
    }

    public function interpretFor(string $driver, array $raw, ?string $sql): array
    {
        return match (strtolower($driver)) {
            'mysql', 'mariadb' => $this->mysql($raw, $sql),
            'pgsql' => $this->postgres($raw, $sql),
            'sqlite' => $this->sqlite($raw, $sql),
            default => [],
        };
    }

    // ---------------------------------------------------------------- MySQL / MariaDB

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<ExplainInsight>
     */
    private function mysql(array $raw, ?string $sql): array
    {
        $state = ['tables' => [], 'filesort' => false, 'temporary' => false];
        $this->walkMysql($raw, $state, 0);

        $insights = [];
        $maxRows = 0;

        foreach ($state['tables'] as $table) {
            [$name, $access, $rows, $possibleKeys, $key] = $table;
            $maxRows = max($maxRows, $rows);

            if ($access === 'ALL') {
                $suggested = $key === null ? $this->suggester->suggest($sql, $name) : null;
                $message = 'Full table scan on `'.$name.'` (≈'.Describe::count($rows).' rows)'
                    .($possibleKeys !== [] ? '; possible keys ('.implode(', ', $possibleKeys).') were not used' : '; no usable index')
                    .($suggested !== null ? '; consider an index on `'.$suggested.'`' : '');

                $insights[] = new ExplainInsight(
                    $possibleKeys !== [] ? ExplainInsightType::NoIndexUsed : ExplainInsightType::FullTableScan,
                    $this->rowsSeverity($rows),
                    $message,
                    $name,
                    $suggested,
                    $rows,
                );
            } elseif ($access === 'index' && $rows >= $this->warningRows) {
                $insights[] = new ExplainInsight(
                    ExplainInsightType::LargeRowsExamined,
                    $this->rowsSeverity($rows),
                    'Full index scan on `'.$name.'`'.($key !== null ? ' via `'.$key.'`' : '').' (≈'.Describe::count($rows).' rows)',
                    $name,
                    null,
                    $rows,
                );
            } elseif ($rows >= $this->criticalRows) {
                $insights[] = new ExplainInsight(
                    ExplainInsightType::LargeRowsExamined,
                    Severity::Warning,
                    'Examines ≈'.Describe::count($rows).' rows of `'.$name.'` per scan'.($key !== null ? ' using `'.$key.'`' : '').'; the index is not selective enough',
                    $name,
                    null,
                    $rows,
                );
            }
        }

        $first = $state['tables'][0][0] ?? null;
        if ($state['filesort']) {
            $insights[] = $this->sortInsight($first, $maxRows, $sql);
        }
        if ($state['temporary']) {
            $insights[] = new ExplainInsight(
                ExplainInsightType::TemporaryTable,
                $maxRows >= $this->warningRows ? Severity::Warning : Severity::Info,
                'Uses a temporary table (GROUP BY / DISTINCT / UNION) over ≈'.Describe::count($maxRows).' rows; an index on the grouped columns can avoid it',
                $first,
                null,
                $maxRows,
            );
        }

        return $insights;
    }

    /**
     * @param  array{tables: list<array{0: string, 1: string, 2: int, 3: list<string>, 4: string|null}>, filesort: bool, temporary: bool}  $state
     */
    private function walkMysql(mixed $node, array &$state, int $depth): void
    {
        if (! is_array($node) || $depth > self::MAX_DEPTH) {
            return;
        }

        if (isset($node['table_name'], $node['access_type']) && is_string($node['table_name'])) {
            $possible = is_array($node['possible_keys'] ?? null) ? array_values(array_filter($node['possible_keys'], 'is_string')) : [];
            $rows = $node['rows_examined_per_scan'] ?? $node['rows'] ?? 0;

            $state['tables'][] = [
                $node['table_name'],
                (string) (is_scalar($node['access_type']) ? $node['access_type'] : ''),
                is_numeric($rows) ? (int) $rows : 0,
                $possible,
                isset($node['key']) && is_string($node['key']) ? $node['key'] : null,
            ];
        }

        if (($node['using_filesort'] ?? false) === true || is_array($node['filesort'] ?? null)) {
            $state['filesort'] = true;
        }
        if (($node['using_temporary_table'] ?? false) === true || is_array($node['temporary_table'] ?? null)) {
            $state['temporary'] = true;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walkMysql($child, $state, $depth + 1);
            }
        }
    }

    // ---------------------------------------------------------------- PostgreSQL

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<ExplainInsight>
     */
    private function postgres(array $raw, ?string $sql): array
    {
        $root = $raw[0]['Plan'] ?? $raw['Plan'] ?? null;
        if (! is_array($root)) {
            return [];
        }

        $state = ['scans' => [], 'sorts' => []];
        $this->walkPostgres($root, $state, 0);

        $insights = [];
        $firstRelation = null;
        $maxRows = 0;

        foreach ($state['scans'] as [$relation, $alias, $rows, $filtered]) {
            $firstRelation ??= $relation;
            $maxRows = max($maxRows, $rows);
            $severity = $this->rowsSeverity($rows);
            if ($filtered) {
                $severity = Severity::max($severity, Severity::Warning);
            }

            $suggested = $this->suggester->suggest($sql, $alias ?? $relation) ?? ($alias !== null ? $this->suggester->suggest($sql, $relation) : null);
            $insights[] = new ExplainInsight(
                ExplainInsightType::FullTableScan,
                $severity,
                'Sequential scan on `'.$relation.'` (≈'.Describe::count($rows).' rows estimated)'
                    .($filtered ? ' filtering rows without a usable index' : '')
                    .($suggested !== null ? '; consider an index on `'.$suggested.'`' : ''),
                $relation,
                $suggested,
                $rows,
            );
        }

        foreach ($state['sorts'] as $rows) {
            $insights[] = $this->sortInsight($firstRelation, max($rows, $maxRows), $sql);
        }

        return $insights;
    }

    /**
     * @param  array{scans: list<array{0: string, 1: string|null, 2: int, 3: bool}>, sorts: list<int>}  $state
     * @param  array<array-key, mixed>  $node
     */
    private function walkPostgres(array $node, array &$state, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $type = $node['Node Type'] ?? null;
        $rows = is_numeric($node['Plan Rows'] ?? null) ? (int) $node['Plan Rows'] : 0;

        if ($type === 'Seq Scan' && is_string($node['Relation Name'] ?? null)) {
            $alias = is_string($node['Alias'] ?? null) && $node['Alias'] !== $node['Relation Name'] ? $node['Alias'] : null;
            $state['scans'][] = [$node['Relation Name'], $alias, $rows, isset($node['Filter'])];
        } elseif ($type === 'Sort' || $type === 'Incremental Sort') {
            $state['sorts'][] = $rows;
        }

        foreach (is_array($node['Plans'] ?? null) ? $node['Plans'] : [] as $child) {
            if (is_array($child)) {
                $this->walkPostgres($child, $state, $depth + 1);
            }
        }
    }

    // ---------------------------------------------------------------- SQLite

    /**
     * @param  array<array-key, mixed>  $raw
     * @return list<ExplainInsight>
     */
    private function sqlite(array $raw, ?string $sql): array
    {
        $filtered = $sql !== null && Sql::whereClause($sql) !== null;
        $insights = [];
        $first = null;

        foreach ($raw as $row) {
            $detail = is_array($row) && is_string($row['detail'] ?? null) ? $row['detail'] : null;
            if ($detail === null) {
                continue;
            }

            if (preg_match('/^SCAN (?:TABLE )?(\S+)(?: AS (\S+))?(.*)$/i', $detail, $m) === 1) {
                $first ??= $m[1];
                if (stripos($m[3], 'INDEX') !== false) {
                    continue; // index scan
                }

                $suggested = $this->suggester->suggest($sql, $m[2] !== '' ? $m[2] : $m[1]);
                $insights[] = new ExplainInsight(
                    ExplainInsightType::FullTableScan,
                    $filtered ? Severity::Warning : Severity::Info,
                    'Full table scan on `'.$m[1].'`'.($filtered ? ' filtering rows without a usable index' : '')
                        .($suggested !== null ? '; consider an index on `'.$suggested.'`' : ''),
                    $m[1],
                    $suggested,
                );
            } elseif (preg_match('/USE TEMP B-TREE FOR (.+)$/i', $detail, $m) === 1) {
                $insights[] = stripos($m[1], 'ORDER BY') !== false
                    ? $this->sortInsight($first, 0, $sql)
                    : new ExplainInsight(
                        ExplainInsightType::TemporaryTable,
                        Severity::Info,
                        'Uses a temporary B-tree for '.strtoupper($m[1]).'; an index on those columns can avoid it',
                        $first,
                    );
            }
        }

        return $insights;
    }

    // ---------------------------------------------------------------- shared

    private function sortInsight(?string $table, int $rows, ?string $sql): ExplainInsight
    {
        $suggested = $table !== null ? $this->suggester->suggest($sql, $table) : null;

        return new ExplainInsight(
            ExplainInsightType::Filesort,
            $rows >= $this->warningRows ? Severity::Warning : Severity::Info,
            'Sorts '.($rows > 0 ? '≈'.Describe::count($rows).' rows' : 'the result').' without an index (filesort)'
                .($suggested !== null ? '; an index on `'.$suggested.'` can return rows already ordered' : '; an index matching WHERE + ORDER BY can avoid it'),
            $table,
            $suggested,
            $rows > 0 ? $rows : null,
        );
    }

    private function rowsSeverity(int $rows): Severity
    {
        return match (true) {
            $rows >= $this->criticalRows => Severity::Critical,
            $rows >= $this->warningRows => Severity::Warning,
            default => Severity::Info,
        };
    }
}
