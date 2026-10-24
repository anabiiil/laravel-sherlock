<?php

declare(strict_types=1);

use Anabil\Tracing\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Tracing\Analysis\Explain\IndexSuggester;
use Anabil\Tracing\Data\ExplainInsight;
use Anabil\Tracing\Enums\ExplainInsightType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Support\SqlQueryNormalizer;
use Anabil\Tracing\Tests\Unit\Analysis\AnalysisFactory as F;

/**
 * @return list<ExplainInsight>
 */
function interpretFixture(string $name, string $driver, ?DefaultPlanInterpreter $interpreter = null): array
{
    $fixture = F::fixture($name);

    return ($interpreter ?? new DefaultPlanInterpreter(1000, 100000))
        ->interpretFor($driver, $fixture['plan'], (new SqlQueryNormalizer)->normalize($fixture['sql']));
}

/**
 * @param  list<ExplainInsight>  $insights
 */
function insightOf(array $insights, ExplainInsightType $type): ?ExplainInsight
{
    foreach ($insights as $insight) {
        if ($insight->type === $type) {
            return $insight;
        }
    }

    return null;
}

describe('MySQL plans (recorded from MySQL 8.4)', function (): void {
    it('reports a full table scan with rows and a suggested index, plus the filesort', function (): void {
        $insights = interpretFixture('mysql-full-scan', 'mysql');

        $scan = insightOf($insights, ExplainInsightType::FullTableScan);
        expect($scan)->not->toBeNull()
            ->and($scan->severity)->toBe(Severity::Warning)
            ->and($scan->table)->toBe('orders')
            ->and($scan->estimatedRows)->toBe(5000)
            ->and($scan->suggestedIndex)->toBe('orders(user_id, created_at)')
            ->and($scan->message)->toBe('Full table scan on `orders` (≈5k rows); no usable index; consider an index on `orders(user_id, created_at)`');

        $sort = insightOf($insights, ExplainInsightType::Filesort);
        expect($sort?->severity)->toBe(Severity::Warning)
            ->and($sort?->suggestedIndex)->toBe('orders(user_id, created_at)');
    });

    it('resolves aliases in joins and reports temporary tables', function (): void {
        $insights = interpretFixture('mysql-join-group', 'mysql');

        $scan = insightOf($insights, ExplainInsightType::FullTableScan);
        expect($scan?->table)->toBe('o')
            ->and($scan?->suggestedIndex)->toBe('orders(user_id, total)')
            ->and(insightOf($insights, ExplainInsightType::TemporaryTable)?->severity)->toBe(Severity::Warning)
            ->and(insightOf($insights, ExplainInsightType::Filesort))->not->toBeNull();
    });

    it('reports possible keys that were not used', function (): void {
        $insight = insightOf(interpretFixture('mysql-unused-key', 'mysql'), ExplainInsightType::NoIndexUsed);

        expect($insight?->message)->toContain('possible keys (orders_status_index) were not used');
    });

    it('has no insights for an indexed lookup', function (): void {
        expect(interpretFixture('mysql-indexed', 'mysql'))->toBe([]);
    });

    it('grades scans by row thresholds', function (): void {
        $fixture = F::fixture('mysql-full-scan');

        $small = (new DefaultPlanInterpreter(10000, 100000))->interpret('mysql', $fixture['plan']);
        $huge = (new DefaultPlanInterpreter(100, 4000))->interpret('mysql', $fixture['plan']);

        expect(insightOf($small, ExplainInsightType::FullTableScan)?->severity)->toBe(Severity::Info)
            ->and(insightOf($small, ExplainInsightType::FullTableScan)?->suggestedIndex)->toBeNull() // no SQL given
            ->and(insightOf($huge, ExplainInsightType::FullTableScan)?->severity)->toBe(Severity::Critical);
    });

    it('reads MariaDB plans', function (): void {
        $insights = interpretFixture('mariadb-full-scan', 'mariadb');

        expect(insightOf($insights, ExplainInsightType::FullTableScan)?->severity)->toBe(Severity::Critical)
            ->and(insightOf($insights, ExplainInsightType::FullTableScan)?->estimatedRows)->toBe(150000)
            ->and(insightOf($insights, ExplainInsightType::Filesort))->not->toBeNull();
    });
});

describe('PostgreSQL plans', function (): void {
    it('reports a filtered sequential scan as at least a warning with a suggested index', function (): void {
        $insights = interpretFixture('postgres-seq-scan', 'pgsql');

        $scan = insightOf($insights, ExplainInsightType::FullTableScan);
        expect($scan?->severity)->toBe(Severity::Warning)
            ->and($scan?->table)->toBe('orders')
            ->and($scan?->estimatedRows)->toBe(42)
            ->and($scan?->suggestedIndex)->toBe('orders(user_id, created_at)')
            ->and($scan?->message)->toContain('Sequential scan on `orders`')
            ->and(insightOf($insights, ExplainInsightType::Filesort)?->severity)->toBe(Severity::Info);
    });

    it('grades unfiltered scans by estimated rows', function (): void {
        $scan = insightOf(interpretFixture('postgres-large-seq-scan', 'pgsql'), ExplainInsightType::FullTableScan);

        expect($scan?->severity)->toBe(Severity::Critical)
            ->and($scan?->suggestedIndex)->toBeNull();
    });

    it('resolves aliases in joins', function (): void {
        $insights = interpretFixture('postgres-join', 'pgsql');

        expect($insights)->toHaveCount(2)
            ->and($insights[0]->table)->toBe('orders')
            ->and($insights[0]->suggestedIndex)->toBe('orders(user_id, total)')
            ->and($insights[0]->severity)->toBe(Severity::Warning)
            ->and($insights[1]->table)->toBe('users')
            ->and($insights[1]->severity)->toBe(Severity::Info);
    });

    it('has no insights for an index scan', function (): void {
        expect(interpretFixture('postgres-index-scan', 'pgsql'))->toBe([]);
    });
});

describe('SQLite plans', function (): void {
    it('reports scans and temp b-trees', function (): void {
        $insights = interpretFixture('sqlite-scan', 'sqlite');

        expect(insightOf($insights, ExplainInsightType::FullTableScan)?->severity)->toBe(Severity::Warning)
            ->and(insightOf($insights, ExplainInsightType::FullTableScan)?->suggestedIndex)->toBe('orders(user_id, created_at)')
            ->and(insightOf($insights, ExplainInsightType::Filesort))->not->toBeNull()
            ->and(interpretFixture('sqlite-search', 'sqlite'))->toBe([]);
    });
});

it('ignores unknown drivers and malformed plans', function (): void {
    $interpreter = new DefaultPlanInterpreter;

    expect($interpreter->interpret('sqlsrv', ['x' => 1]))->toBe([])
        ->and($interpreter->interpret('pgsql', ['nope']))->toBe([])
        ->and($interpreter->interpret('mysql', ['query_block' => 'x']))->toBe([]);
});

describe('IndexSuggester', function (): void {
    it('orders equality, join, range then order by columns', function (string $sql, string $table, ?string $expected): void {
        expect((new IndexSuggester)->suggest((new SqlQueryNormalizer)->normalize($sql), $table))->toBe($expected);
    })->with([
        'equality + order' => ['select * from `orders` where `status` = 1 and `user_id` = 2 order by `created_at` desc', 'orders', 'orders(status, user_id, created_at)'],
        'range after equality' => ['select * from orders where created_at > 5 and user_id = 2', 'orders', 'orders(user_id, created_at)'],
        'in list' => ['select * from `posts` where `posts`.`user_id` in (1, 2, 3)', 'posts', 'posts(user_id)'],
        'is null' => ['select * from `posts` where `posts`.`deleted_at` is null', 'posts', 'posts(deleted_at)'],
        'other table columns ignored' => ['select * from `orders` inner join `users` on `users`.`id` = `orders`.`user_id` where `users`.`country` = 1', 'orders', 'orders(user_id)'],
        'alias' => ['select * from orders as o where o.user_id = 1', 'o', 'orders(user_id)'],
        'not equal is not indexable' => ['select * from orders where status != 1', 'orders', null],
        'no where' => ['select * from orders', 'orders', null],
        'caps at three columns' => ['select * from t where a = 1 and b = 2 and c = 3 and d = 4', 't', 't(a, b, c)'],
    ]);
});
