<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Sherlock\Analysis\Explain\SqliteExplainer;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Enums\ExplainInsightType;
use Anabil\Sherlock\Support\SqlQueryNormalizer;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (! extension_loaded('pdo_sqlite')) {
        $this->markTestSkipped('pdo_sqlite is not available.');
    }

    config()->set('database.connections.sherlock_sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    DB::connection('sherlock_sqlite')->statement('create table orders (id integer primary key, user_id integer, created_at text)');
});

it('explains with EXPLAIN QUERY PLAN and detects the full scan', function (): void {
    $sql = 'select * from "orders" where "orders"."user_id" = ? order by "created_at" desc';
    $query = new QueryRecord($sql, [5], 300, 'sherlock_sqlite', 'sqlite');

    $plan = app(SqliteExplainer::class)->explain($query);
    expect($plan)->not->toBeNull();

    $insights = (new DefaultPlanInterpreter)->interpretFor('sqlite', $plan->raw, (new SqlQueryNormalizer)->normalize($sql));
    expect($insights[0]->type)->toBe(ExplainInsightType::FullTableScan)
        ->and($insights[0]->suggestedIndex)->toBe('orders(user_id, created_at)');

    DB::connection('sherlock_sqlite')->statement('create index orders_user_id on orders (user_id, created_at)');
    $plan = app(SqliteExplainer::class)->explain($query);

    expect((new DefaultPlanInterpreter)->interpretFor('sqlite', $plan->raw, $sql))->toBe([]);
});

it('rejects unsafe queries', function (): void {
    expect(app(SqliteExplainer::class)->explain(new QueryRecord('delete from orders', null, 300, 'sherlock_sqlite', 'sqlite')))->toBeNull();
});
