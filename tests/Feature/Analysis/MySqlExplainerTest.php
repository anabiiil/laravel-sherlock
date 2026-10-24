<?php

declare(strict_types=1);

use Anabil\Tracing\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Tracing\Analysis\Explain\ExplainService;
use Anabil\Tracing\Analysis\Explain\MySqlExplainer;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Data\ExplainInsight;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\ExplainInsightType;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Support\SqlQueryNormalizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

const EXPLAIN_TABLE = 'tracing_explain_orders';

beforeEach(function (): void {
    Schema::dropIfExists(EXPLAIN_TABLE);
    Schema::create(EXPLAIN_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->unsignedInteger('user_id');
        $table->string('status', 20);
        $table->unsignedInteger('total');
    });

    $rows = [];
    for ($i = 1; $i <= 3000; $i++) {
        $rows[] = ['user_id' => $i % 300 + 1, 'status' => $i % 3 === 0 ? 'new' : 'paid', 'total' => $i];
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table(EXPLAIN_TABLE)->insert($chunk);
    }
    DB::select('analyze table '.EXPLAIN_TABLE);
});

afterEach(function (): void {
    Schema::dropIfExists(EXPLAIN_TABLE);
});

function explainQuery(?array $bindings = [5]): QueryRecord
{
    $sql = 'select * from `'.EXPLAIN_TABLE.'` where `'.EXPLAIN_TABLE.'`.`user_id` = ?';

    return (new QueryRecord($sql, $bindings, 350.0, 'mysql', 'mysql'))
        ->withNormalization((new SqlQueryNormalizer)->normalize($sql), hash('xxh128', $sql));
}

/**
 * @return list<ExplainInsight>
 */
function explainInsights(QueryRecord $query): array
{
    $plan = app(MySqlExplainer::class)->explain($query);
    expect($plan)->not->toBeNull();

    return (new DefaultPlanInterpreter(1000, 100000))->interpretFor($plan->driver, $plan->raw, $query->normalizedSql);
}

it('explains a real query: full scan with a suggested index, which disappears once the index exists', function (): void {
    $insights = explainInsights(explainQuery());

    expect($insights)->toHaveCount(1)
        ->and($insights[0]->type)->toBe(ExplainInsightType::FullTableScan)
        ->and($insights[0]->severity)->toBe(Severity::Warning)
        ->and($insights[0]->table)->toBe(EXPLAIN_TABLE)
        ->and($insights[0]->estimatedRows)->toBeGreaterThanOrEqual(2000)
        ->and($insights[0]->suggestedIndex)->toBe(EXPLAIN_TABLE.'(user_id)');

    Schema::table(EXPLAIN_TABLE, fn (Blueprint $table) => $table->index('user_id'));
    DB::select('analyze table '.EXPLAIN_TABLE);

    expect(explainInsights(explainQuery()))->toBe([]);
});

it('runs straight on PDO without firing query events and leaves the connection usable', function (): void {
    Event::fake();

    $plan = app(MySqlExplainer::class)->explain(explainQuery());

    expect($plan?->driver)->toBe('mysql')
        ->and($plan?->connection)->toBe('mysql')
        ->and($plan?->raw)->toHaveKey('query_block');
    Event::assertNothingDispatched();

    expect(DB::table(EXPLAIN_TABLE)->count())->toBe(3000);
});

it('never explains unsafe or unbound queries on the real connection', function (string $sql, ?array $bindings): void {
    $query = new QueryRecord($sql, $bindings, 500, 'mysql', 'mysql');

    expect(app(MySqlExplainer::class)->explain($query))->toBeNull()
        ->and(DB::table(EXPLAIN_TABLE)->where('status', 'hacked')->count())->toBe(0)
        ->and(Schema::hasTable(EXPLAIN_TABLE))->toBeTrue();
})->with([
    'update' => ['update `'.EXPLAIN_TABLE.'` set `status` = ? where `id` = ?', ['hacked', 1]],
    'multiple statements' => ['select 1; drop table `'.EXPLAIN_TABLE.'`', []],
    'select for update' => ['select * from `'.EXPLAIN_TABLE.'` where `id` = ? for update', [1]],
    'unbound placeholder' => ['select * from `'.EXPLAIN_TABLE.'` where `id` = ?', null],
]);

it('skips connections inside an open transaction and invalid SQL', function (): void {
    DB::beginTransaction();
    try {
        expect(app(MySqlExplainer::class)->explain(explainQuery()))->toBeNull();
    } finally {
        DB::rollBack();
    }

    expect(app(MySqlExplainer::class)->explain(new QueryRecord('select * from `no_such_table_xyz`', null, 500, 'mysql', 'mysql')))->toBeNull()
        ->and(app(MySqlExplainer::class)->explain(new QueryRecord('select 1', null, 500, 'nope_connection', 'mysql')))->toBeNull();
});

it('produces a missing index finding end to end and explains the fingerprint only once', function (): void {
    $analyzer = app(TraceAnalyzer::class);
    $makeTrace = fn (int $userId): TraceData => new TraceData(
        '01J', EntryType::Request, '/orders', new DateTimeImmutable, 600, method: 'GET', full: true,
        queries: [new QueryRecord(
            'select * from `'.EXPLAIN_TABLE.'` where `'.EXPLAIN_TABLE.'`.`user_id` = ?',
            [$userId], 400.0, 'mysql', 'mysql',
            new SourceLocation('app/Http/Controllers/OrderController.php', 30, 'App\\Http\\Controllers\\OrderController', 'index'),
        )],
    );

    $first = $analyzer->analyze($makeTrace(5));
    $missing = array_values(array_filter($first->findings, fn ($f) => $f->type === IssueType::MissingIndex));

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->title)->toStartWith('Missing index on `'.EXPLAIN_TABLE.'` (full scan ≈')
        ->and($missing[0]->title)->toEndWith('in OrderController@index')
        ->and($missing[0]->metrics['suggested_index'])->toBe(EXPLAIN_TABLE.'(user_id)')
        ->and($first->explainPlans)->toHaveCount(1);

    // The index is added, but within the TTL the cached plan is reused: no second EXPLAIN.
    Schema::table(EXPLAIN_TABLE, fn (Blueprint $table) => $table->index('user_id'));
    $second = $analyzer->analyze($makeTrace(9));

    expect(array_keys($second->explainPlans))->toBe(array_keys($first->explainPlans))
        ->and(array_values(array_filter($second->findings, fn ($f) => $f->type === IssueType::MissingIndex)))->toHaveCount(1);

    // Once the cached entry expires, it is explained again and the problem is gone.
    $fingerprint = (string) array_key_first($first->explainPlans);
    cache()->forget(ExplainService::CACHE_PREFIX.$fingerprint);
    cache()->forget(ExplainService::CACHE_PREFIX.$fingerprint.':lock');
    $third = $analyzer->analyze($makeTrace(11));

    expect(array_values(array_filter($third->findings, fn ($f) => $f->type === IssueType::MissingIndex)))->toBe([]);
});
