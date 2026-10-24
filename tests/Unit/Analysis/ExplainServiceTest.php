<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\MissingIndexAnalyzer;
use Anabil\Sherlock\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Sherlock\Analysis\Explain\ExplainService;
use Anabil\Sherlock\Contracts\Explainer;
use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Enums\ExplainInsightType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * Explainer double returning the recorded MySQL full-scan plan and counting calls.
 */
function countingExplainer(bool $fail = false): Explainer
{
    return new class($fail) implements Explainer
    {
        /** @var list<string> */
        public array $explained = [];

        public function __construct(private readonly bool $fail) {}

        public function supports(string $driver): bool
        {
            return $driver === 'mysql';
        }

        public function explain(QueryRecord $query): ?ExplainPlan
        {
            $this->explained[] = $query->sql;
            if ($this->fail) {
                throw new RuntimeException('boom');
            }

            return new ExplainPlan($query->connection, 'mysql', (string) $query->normalizedSql, F::fixture('mysql-full-scan')['plan']);
        }
    };
}

function explainService(Explainer $explainer, ?Repository $cache = null, bool $enabled = true, int $maxPerTrace = 3): ExplainService
{
    return new ExplainService([$explainer], new DefaultPlanInterpreter, $cache ?? new Repository(new ArrayStore), $enabled, 100.0, 3600, $maxPerTrace);
}

it('explains a slow bound select once per fingerprint and reuses the cached plan', function (): void {
    $explainer = countingExplainer();
    $cache = new Repository(new ArrayStore);
    $service = explainService($explainer, $cache);

    $first = F::enrich(F::trace([F::query('select * from `orders` where `user_id` = ? order by `created_at` desc', 300, [5])]));
    $second = F::enrich(F::trace([F::query('select * from `orders` where `user_id` = ? order by `created_at` desc', 900, [6])]));

    $plans1 = $service->explainTrace($first);
    $plans2 = $service->explainTrace($second);
    $fingerprint = (string) $first->queries[0]->fingerprint;

    expect($explainer->explained)->toHaveCount(1)
        ->and(array_keys($plans1))->toBe([$fingerprint])
        ->and($plans2[$fingerprint]->insights)->toEqual($plans1[$fingerprint]->insights)
        ->and($plans1[$fingerprint]->insights[0]->type)->toBe(ExplainInsightType::FullTableScan)
        ->and($plans1[$fingerprint]->insights[0]->suggestedIndex)->toBe('orders(user_id, created_at)')
        ->and($cache->has(ExplainService::CACHE_PREFIX.$fingerprint))->toBeTrue();
});

it('explains a lightweight query through its EXPLAIN-only bindings, keeping no literal in the plan or the cache', function (): void {
    $explainer = new class implements Explainer
    {
        /** @var list<array<int|string, scalar|null>|null> */
        public array $bindings = [];

        public function supports(string $driver): bool
        {
            return true;
        }

        public function explain(QueryRecord $query): ?ExplainPlan
        {
            $this->bindings[] = $query->bindingsForExplain();
            $plan = F::fixture('mysql-full-scan')['plan'];
            $plan['query_block']['table']['attached_condition'] = "((`orders`.`user_id` = 4242) and (`orders`.`ref` = _utf8mb4'secret-ref'))";

            return new ExplainPlan($query->connection, 'mysql', (string) $query->normalizedSql, $plan);
        }
    };
    $cache = new Repository(new ArrayStore);
    $service = explainService($explainer, $cache);
    $query = new QueryRecord('select * from `orders` where `user_id` = ? and `ref` = ?', null, 300.0, 'mysql', 'mysql', explainBindings: [4242, 'secret-ref']);

    $trace = F::enrich(F::trace([$query]));
    $plans = $service->explainTrace($trace);
    $fingerprint = (string) $trace->queries[0]->fingerprint;
    $cached = serialize($cache->get(ExplainService::CACHE_PREFIX.$fingerprint));

    expect($explainer->bindings)->toBe([[4242, 'secret-ref']])
        ->and($plans[$fingerprint]->insights[0]->type)->toBe(ExplainInsightType::FullTableScan)
        ->and($plans[$fingerprint]->raw['query_block']['table']['attached_condition'])->toBe('((`orders`.`user_id` = ?) and (`orders`.`ref` = ?))')
        ->and(serialize($plans))->not->toContain('4242')->not->toContain('secret-ref')
        ->and($cached)->not->toContain('4242')->not->toContain('secret-ref')
        ->and($cached)->toContain('attached_condition');
});

it('scrubs literals from plans cached before scrubbing existed', function (): void {
    $plan = ExplainService::hydrate([
        'connection' => 'mysql', 'driver' => 'mysql', 'sql' => 'select ?',
        'raw' => ['query_block' => ['table' => ['attached_condition' => "(`t`.`email` = 'ann@example.com')"]]],
        'insights' => [],
    ]);

    expect($plan?->raw['query_block']['table']['attached_condition'])->toBe('(`t`.`email` = ?)');
});

it('explains again once the TTL expired', function (): void {
    $explainer = countingExplainer();
    $cache = new Repository(new ArrayStore);
    $service = explainService($explainer, $cache);
    $trace = F::enrich(F::trace([F::query('select * from `orders` where `user_id` = ?', 300, [5])]));

    $service->explainTrace($trace);
    $cache->forget(ExplainService::CACHE_PREFIX.$trace->queries[0]->fingerprint);
    $cache->forget(ExplainService::CACHE_PREFIX.$trace->queries[0]->fingerprint.':lock');
    $service->explainTrace($trace);

    expect($explainer->explained)->toHaveCount(2);
});

it('only explains eligible queries', function (QueryRecord $query): void {
    $explainer = countingExplainer();

    expect(explainService($explainer)->explainTrace(F::enrich(F::trace([$query]))))->toBe([])
        ->and($explainer->explained)->toBe([]);
})->with([
    'below the slow threshold' => [F::query('select * from orders where id = ?', 99, [1])],
    'unbound placeholder (light trace)' => [F::query('select * from orders where id = ?', 500)],
    'update' => [F::query('update orders set a = ? where id = ?', 500, [1, 2])],
    'select for update' => [F::query('select * from orders where id = ? for update', 500, [1])],
    'multiple statements' => [F::query('select * from orders; select 1', 500, [])],
    'unsupported driver' => [F::query('select * from orders where id = ?', 500, [1], driver: 'sqlsrv')],
]);

it('explains a slow query without placeholders even without bindings', function (): void {
    $explainer = countingExplainer();

    $plans = explainService($explainer)->explainTrace(F::enrich(F::trace([F::query('select * from orders where status = 1', 500)])));

    expect($plans)->toHaveCount(1);
});

it('caps explains per trace, slowest first', function (): void {
    $explainer = countingExplainer();
    $trace = F::enrich(F::trace([
        F::query('select * from a where x = ?', 200, [1]),
        F::query('select * from b where x = ?', 900, [1]),
        F::query('select * from c where x = ?', 500, [1]),
        F::query('select * from b where x = ?', 950, [2]),
    ]));

    explainService($explainer, maxPerTrace: 2)->explainTrace($trace);

    expect($explainer->explained)->toBe(['select * from b where x = ?', 'select * from c where x = ?']);
});

it('does nothing when disabled or without a cache store', function (): void {
    $explainer = countingExplainer();
    $trace = F::enrich(F::trace([F::query('select * from a where x = ?', 500, [1])]));

    expect(explainService($explainer, enabled: false)->explainTrace($trace))->toBe([])
        ->and((new ExplainService([$explainer], new DefaultPlanInterpreter, null))->explainTrace($trace))->toBe([])
        ->and($explainer->explained)->toBe([]);
});

it('caches failures too so a broken query is not retried every request', function (): void {
    $explainer = countingExplainer(fail: true);
    $service = explainService($explainer);
    $trace = F::enrich(F::trace([F::query('select * from a where x = ?', 500, [1])]));

    expect($service->explainTrace($trace))->toBe([])
        ->and($service->explainTrace($trace))->toBe([])
        ->and($explainer->explained)->toHaveCount(1);
});

it('skips while another worker holds the explain lock', function (): void {
    $explainer = countingExplainer();
    $cache = new Repository(new ArrayStore);
    $trace = F::enrich(F::trace([F::query('select * from a where x = ?', 500, [1])]));
    $cache->add(ExplainService::CACHE_PREFIX.$trace->queries[0]->fingerprint.':lock', 1, 60);

    expect(explainService($explainer, $cache)->explainTrace($trace))->toBe([])
        ->and($explainer->explained)->toBe([]);
});

it('hydrates cached plans defensively', function (): void {
    expect(ExplainService::hydrate(['driver' => 'mysql']))->toBeNull()
        ->and(ExplainService::hydrate(['connection' => 'c', 'driver' => 'mysql', 'sql' => 's', 'insights' => [['type' => 'weird', 'message' => 'm', 'severity' => 'x'], 'bad']])?->insights[0]->type)
        ->toBe(ExplainInsightType::Other);
});

describe('MissingIndexAnalyzer', function (): void {
    it('turns warning-level insights into one finding per fingerprint with migration advice', function (): void {
        $trace = F::enrich(F::trace([F::query('select * from `orders` where `user_id` = ? order by `created_at` desc', 300, [5], F::location())]));
        $plans = explainService(countingExplainer())->explainTrace($trace);

        $findings = (new MissingIndexAnalyzer(F::fingerprinter()))->analyzeWithPlans($trace, $plans);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->type)->toBe(IssueType::MissingIndex)
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->title)->toBe('Missing index on `orders` (full scan ≈5k rows) in OrderController@index')
            ->and($findings[0]->metrics['suggested_index'])->toBe('orders(user_id, created_at)')
            ->and($findings[0]->recommendation)->toContain("\$table->index(['user_id', 'created_at']);")
            ->and($findings[0]->recommendation)->toContain('CREATE INDEX orders_user_id_created_at_index ON orders (user_id, created_at)')
            ->and($findings[0]->fingerprint)->toBe(F::fingerprinter()->issue(IssueType::MissingIndex, $trace->queries[0]->fingerprint))
            ->and((new MissingIndexAnalyzer(F::fingerprinter()))->analyze($trace))->toBe([]);
    });

    it('ignores info-level insights such as scans of tiny tables', function (): void {
        $trace = F::enrich(F::trace([F::query('select * from `orders` where `user_id` = ?', 300, [5])]));
        $service = new ExplainService([countingExplainer()], new DefaultPlanInterpreter(100000, 1000000), new Repository(new ArrayStore));

        expect((new MissingIndexAnalyzer(F::fingerprinter()))->analyzeWithPlans($trace, $service->explainTrace($trace)))->toBe([]);
    });

    it('recommends generically without a suggested index', function (): void {
        expect(MissingIndexAnalyzer::recommend(null))->toContain('WHERE / JOIN')
            ->and(MissingIndexAnalyzer::recommend('orders(user_id)'))->toContain("\$table->index('user_id');");
    });
});
