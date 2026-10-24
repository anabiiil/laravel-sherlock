<?php

declare(strict_types=1);

use Anabil\Tracing\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Tracing\Analysis\DefaultBottleneckClassifier;
use Anabil\Tracing\Analysis\PlanAwareAnalyzer;
use Anabil\Tracing\Analysis\TraceAnalysisPipeline;
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;
use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Support\SqlQueryNormalizer;
use Anabil\Tracing\Tests\Unit\Analysis\AnalysisFactory as F;
use Psr\Log\AbstractLogger;

function pipelineLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = $level.': '.$message;
        }
    };
}

function throwingAnalyzer(): Analyzer
{
    return new class implements Analyzer
    {
        public function analyze(TraceData $trace): array
        {
            throw new RuntimeException('analyzer exploded');
        }
    };
}

it('enriches queries, runs every analyzer and classifies', function (): void {
    $fp = F::fingerprinter();
    $pipeline = new TraceAnalysisPipeline(new SqlQueryNormalizer, $fp, [new SlowQueryAnalyzer($fp), new NPlusOneAnalyzer($fp)], new DefaultBottleneckClassifier);

    $queries = [F::query('select * from `orders` where `id` = 1', 800, location: F::location(10))];
    for ($i = 0; $i < 6; $i++) {
        $queries[] = F::query('select * from `posts` where `user_id` = '.$i, 1, location: $i === 0 ? F::location(20) : null);
    }

    $result = $pipeline->analyze(F::trace($queries, 1000));

    expect(array_map(fn ($f) => $f->type, $result->findings))->toBe([IssueType::SlowQuery, IssueType::NPlusOne])
        ->and($result->verdict->bottleneck)->toBe(Bottleneck::Database)
        ->and($result->trace)->not->toBeNull()
        ->and($result->trace->queries[1]->normalizedSql)->toBe('select * from `posts` where `user_id` = ?')
        ->and($result->trace->queries[1]->fingerprint)->toBe($result->trace->queries[6]->fingerprint)
        ->and($result->explainPlans)->toBe([]);
});

it('normalizes each distinct raw SQL only once per trace', function (): void {
    $normalizer = new class implements QueryNormalizer
    {
        public int $calls = 0;

        public function normalize(string $sql): string
        {
            $this->calls++;

            return (new SqlQueryNormalizer)->normalize($sql);
        }
    };

    $pipeline = new TraceAnalysisPipeline($normalizer, F::fingerprinter(), [], new DefaultBottleneckClassifier);
    $sql = 'select * from `posts` where `user_id` = ?';

    $trace = $pipeline->enrich(F::trace([...F::repeat(F::query($sql), 200), F::query('select 1')]));

    expect($normalizer->calls)->toBe(2)
        ->and($trace->queries)->toHaveCount(201)
        ->and($pipeline->enrich($trace))->toBe($trace); // already enriched: untouched
});

it('keeps going when an analyzer throws and reports it once, quietly', function (): void {
    $fp = F::fingerprinter();
    $logger = pipelineLogger();
    $pipeline = new TraceAnalysisPipeline(new SqlQueryNormalizer, $fp, [throwingAnalyzer(), new SlowQueryAnalyzer($fp)], new DefaultBottleneckClassifier, null, $logger);
    $trace = F::trace([F::query('select * from orders', 500)], 1000);

    $first = $pipeline->analyze($trace);
    $pipeline->analyze($trace);

    expect($first->findings)->toHaveCount(1)
        ->and($first->findings[0]->type)->toBe(IssueType::SlowQuery)
        ->and($logger->messages)->toHaveCount(1)
        ->and($logger->messages[0])->toStartWith('warning: [tracing] analysis step failed')->toContain('analyzer exploded');
});

it('falls back to an unknown verdict when the classifier throws', function (): void {
    $classifier = new class implements BottleneckClassifier
    {
        public function classify(TraceData $trace): Verdict
        {
            throw new RuntimeException('nope');
        }
    };

    $result = (new TraceAnalysisPipeline(new SqlQueryNormalizer, F::fingerprinter(), [], $classifier))->analyze(F::trace());

    expect($result->verdict->bottleneck)->toBe(Bottleneck::Unknown);
});

it('hands explain plans only to plan-aware analyzers', function (): void {
    $aware = new class implements PlanAwareAnalyzer
    {
        public ?array $plans = null;

        public function analyze(TraceData $trace): array
        {
            return [];
        }

        public function analyzeWithPlans(TraceData $trace, array $plans): array
        {
            $this->plans = $plans;

            return [];
        }
    };

    (new TraceAnalysisPipeline(new SqlQueryNormalizer, F::fingerprinter(), [$aware], new DefaultBottleneckClassifier))->analyze(F::trace());

    expect($aware->plans)->toBe([]);
});

it('drops the transient EXPLAIN-only bindings before analyzers run', function (): void {
    $seen = new class implements Analyzer
    {
        /** @var list<mixed> */
        public array $explainBindings = [];

        public function analyze(TraceData $trace): array
        {
            foreach ($trace->queries as $query) {
                $this->explainBindings[] = $query->explainBindings;
            }

            return [];
        }
    };
    $query = new QueryRecord('select * from t where id = ?', null, 500.0, 'mysql', 'mysql', explainBindings: [7]);

    $result = (new TraceAnalysisPipeline(new SqlQueryNormalizer, F::fingerprinter(), [$seen], new DefaultBottleneckClassifier))->analyze(F::trace([$query]));

    expect($seen->explainBindings)->toBe([null])
        ->and($result->trace?->queries[0]->explainBindings)->toBeNull()
        ->and($result->trace?->queries[0]->fingerprint)->not->toBeNull();
});
