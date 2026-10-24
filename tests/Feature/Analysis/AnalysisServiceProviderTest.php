<?php

declare(strict_types=1);

use Anabil\Tracing\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Tracing\Analysis\DefaultBottleneckClassifier;
use Anabil\Tracing\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Tracing\Analysis\Explain\ExplainService;
use Anabil\Tracing\Analysis\Explain\MySqlExplainer;
use Anabil\Tracing\Analysis\Explain\PostgresExplainer;
use Anabil\Tracing\Analysis\Explain\SqliteExplainer;
use Anabil\Tracing\Analysis\TraceAnalysisPipeline;
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Contracts\PlanInterpreter;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Providers\AnalysisServiceProvider;

/**
 * @return list<Analyzer>
 */
function configuredAnalyzers(): array
{
    $pipeline = app(TraceAnalyzer::class);
    $property = new ReflectionProperty(TraceAnalysisPipeline::class, 'analyzers');

    return $property->getValue($pipeline);
}

function freshAnalysisServices(): void
{
    foreach ([TraceAnalyzer::class, ExplainService::class, PlanInterpreter::class, BottleneckClassifier::class] as $abstract) {
        app()->forgetInstance($abstract);
    }
}

it('overrides the null defaults with the real analysis services', function (): void {
    expect(app(TraceAnalyzer::class))->toBeInstanceOf(TraceAnalysisPipeline::class)
        ->and(app(BottleneckClassifier::class))->toBeInstanceOf(DefaultBottleneckClassifier::class)
        ->and(app(PlanInterpreter::class))->toBeInstanceOf(DefaultPlanInterpreter::class)
        ->and(app(TraceAnalyzer::class))->toBe(app(TraceAnalyzer::class))
        ->and(array_map(fn ($e) => $e::class, iterator_to_array(app()->tagged(AnalysisServiceProvider::EXPLAINER_TAG))))
        ->toBe([MySqlExplainer::class, PostgresExplainer::class, SqliteExplainer::class]);
});

it('resolves every analyzer named in the config', function (): void {
    $classes = array_map(fn (Analyzer $a): string => $a::class, configuredAnalyzers());

    expect($classes)->toBe(config('tracing.analyzers'))
        ->and(config('tracing.analyzers'))->toHaveCount(10);

    foreach (config('tracing.analyzers') as $class) {
        expect(class_exists($class))->toBeTrue()
            ->and(app($class))->toBeInstanceOf(Analyzer::class);
    }
});

it('skips disabled, missing and invalid analyzers', function (): void {
    config()->set('tracing.analyzers', [
        SlowQueryAnalyzer::class => true,
        NPlusOneAnalyzer::class => false,
        'App\\Missing\\Analyzer',
        stdClass::class,
    ]);
    freshAnalysisServices();

    expect(array_map(fn (Analyzer $a): string => $a::class, configuredAnalyzers()))->toBe([SlowQueryAnalyzer::class]);
});

it('applies configured thresholds to the built-in analyzers', function (): void {
    config()->set('tracing.thresholds.slow_query', ['warning_ms' => 10, 'critical_ms' => 20]);
    config()->set('tracing.explain.enabled', false);
    freshAnalysisServices();

    $trace = new TraceData('01J', EntryType::Request, '/x', new DateTimeImmutable, 100, method: 'GET', queries: [
        new QueryRecord('select * from `orders`', null, 25, 'mysql', 'mysql'),
    ]);

    $findings = array_values(array_filter(
        app(TraceAnalyzer::class)->analyze($trace)->findings,
        fn ($f) => $f->type === IssueType::SlowQuery,
    ));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(Severity::Critical);
});

it('honours explain.enabled and explain.environments', function (): void {
    expect(app(ExplainService::class)->enabled())->toBeTrue();

    config()->set('tracing.explain.environments', ['production']);
    freshAnalysisServices();
    expect(app(ExplainService::class)->enabled())->toBeFalse();

    config()->set('tracing.explain.environments', 'production, testing');
    freshAnalysisServices();
    expect(app(ExplainService::class)->enabled())->toBeTrue();

    config()->set('tracing.explain.enabled', false);
    freshAnalysisServices();
    expect(app(ExplainService::class)->enabled())->toBeFalse();
});
