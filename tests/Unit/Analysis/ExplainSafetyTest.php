<?php

declare(strict_types=1);

use Anabil\Tracing\Analysis\Explain\ExplainGuard;
use Anabil\Tracing\Analysis\Explain\MySqlExplainer;
use Anabil\Tracing\Analysis\Explain\PostgresExplainer;
use Anabil\Tracing\Analysis\Explain\SqliteExplainer;
use Anabil\Tracing\Tests\Unit\Analysis\AnalysisFactory as F;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * A resolver that fails the test if an explainer ever touches the database.
 */
function forbiddenResolver(): ConnectionResolverInterface
{
    return new class implements ConnectionResolverInterface
    {
        public function connection($name = null): ConnectionInterface
        {
            throw new LogicException('EXPLAIN must not reach the database for this query');
        }

        public function getDefaultConnection(): string
        {
            return 'mysql';
        }

        public function setDefaultConnection($name): void {}
    };
}

it('accepts plain read-only selects', function (string $sql): void {
    expect(ExplainGuard::isSafe($sql))->toBeTrue();
})->with([
    'select * from `orders` where `user_id` = ?',
    '  SELECT id FROM orders o JOIN users u ON u.id = o.user_id WHERE u.email LIKE ? ORDER BY o.id LIMIT 10',
    '(select id from a) union (select id from b)',
    'with recent as (select * from orders where created_at > ?) select * from recent',
    "select * from logs where message = 'insert into x, drop table y -- comment'",
    'select `updated_at`, `deleted_at`, `created_at` from `orders`',
]);

it('rejects anything that is not a single read-only select', function (string $sql): void {
    expect(ExplainGuard::isSafe($sql))->toBeFalse();
})->with([
    'update' => ['update `orders` set `status` = ? where `id` = ?'],
    'delete' => ['delete from orders where id = 1'],
    'insert' => ['insert into orders (id) values (1)'],
    'multiple statements' => ['select 1; drop table orders'],
    'trailing semicolon' => ['select * from orders;'],
    'semicolon even inside a literal (conservative)' => ["select * from logs where m = 'a;b'"],
    'for update' => ['select * from `orders` where `id` = ? for update'],
    'for share' => ['select * from orders where id = 1 for share'],
    'lock in share mode' => ['select * from orders where id = 1 lock in share mode'],
    'select into' => ['select * into new_orders from orders'],
    'into outfile' => ['select * from orders into outfile \'/tmp/x\''],
    'writing cte' => ['with moved as (delete from orders returning *) select * from moved'],
    'sleep' => ['select sleep(10)'],
    'pg_sleep' => ['select pg_sleep(10)'],
    'advisory lock' => ['select pg_advisory_lock(1)'],
    'set_config' => ["select set_config('role', 'admin', false)"],
    'nextval' => ["select nextval('orders_id_seq')"],
    'comment' => ['select * from orders /* hidden */'],
    'explain analyze' => ['explain analyze select * from orders'],
    'empty' => ['   '],
    'too long' => ['select '.str_repeat('a, ', 7000).'b from t'],
]);

it('requires bindings for placeholders and never invents values', function (): void {
    expect(ExplainGuard::hasUsableBindings(F::query('select * from t where id = ?')))->toBeFalse()
        ->and(ExplainGuard::hasUsableBindings(F::query('select * from t where id = :id')))->toBeFalse()
        ->and(ExplainGuard::hasUsableBindings(F::query('select * from t where id = $1')))->toBeFalse()
        ->and(ExplainGuard::hasUsableBindings(F::query('select * from t where id = ? and b = ?', bindings: [1])))->toBeFalse()
        ->and(ExplainGuard::hasUsableBindings(F::query('select * from t where id = ?', bindings: [1])))->toBeTrue()
        ->and(ExplainGuard::hasUsableBindings(F::query("select * from t where note = 'why?'")))->toBeTrue()
        ->and(ExplainGuard::hasUsableBindings(F::query('select * from t where id = 5')))->toBeTrue();
});

it('explainers reject unsafe or unbound queries before touching the database', function (string $sql, ?array $bindings): void {
    foreach ([new MySqlExplainer(forbiddenResolver()), new PostgresExplainer(forbiddenResolver()), new SqliteExplainer(forbiddenResolver())] as $explainer) {
        $driver = match (true) {
            $explainer instanceof MySqlExplainer => 'mysql',
            $explainer instanceof PostgresExplainer => 'pgsql',
            default => 'sqlite',
        };

        expect($explainer->explain(F::query($sql, 500, $bindings, driver: $driver)))->toBeNull();
    }
})->with([
    'update' => ['update orders set status = ? where id = ?', [1, 2]],
    'multiple statements' => ['select * from orders where id = ?; delete from orders', [1]],
    'select for update' => ['select * from orders where id = ? for update', [1]],
    'unbound placeholder' => ['select * from orders where id = ?', null],
]);

it('explainers only support their drivers and never throw on connection errors', function (): void {
    $mysql = new MySqlExplainer(forbiddenResolver());

    expect($mysql->supports('mysql'))->toBeTrue()
        ->and($mysql->supports('mariadb'))->toBeTrue()
        ->and($mysql->supports('pgsql'))->toBeFalse()
        ->and((new PostgresExplainer(forbiddenResolver()))->supports('pgsql'))->toBeTrue()
        ->and((new SqliteExplainer(forbiddenResolver()))->supports('sqlite'))->toBeTrue()
        // eligible query, but the resolver throws: swallowed, no plan
        ->and($mysql->explain(F::query('select * from orders where id = ?', 500, [1])))->toBeNull()
        // driver mismatch
        ->and($mysql->explain(F::query('select 1', 500, driver: 'pgsql')))->toBeNull();
});
