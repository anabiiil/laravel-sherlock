<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Sherlock\Analysis\DefaultBottleneckClassifier;
use Anabil\Sherlock\Analysis\Explain\DefaultPlanInterpreter;
use Anabil\Sherlock\Analysis\Explain\ExplainService;
use Anabil\Sherlock\Analysis\Explain\MySqlExplainer;
use Anabil\Sherlock\Analysis\Explain\PostgresExplainer;
use Anabil\Sherlock\Analysis\Explain\SqliteExplainer;
use Anabil\Sherlock\Analysis\TraceAnalysisPipeline;
use Anabil\Sherlock\Contracts\Analyzer;
use Anabil\Sherlock\Contracts\BottleneckClassifier;
use Anabil\Sherlock\Contracts\PlanInterpreter;
use Anabil\Sherlock\Contracts\TraceAnalyzer;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Providers\AnalysisServiceProvider;

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

    expect($classes)->toBe(config('sherlock.analyzers'))
        ->and(config('sherlock.analyzers'))->toHaveCount(13);

    foreach (config('sherlock.analyzers') as $class) {
        expect(class_exists($class))->toBeTrue()
            ->and(app($class))->toBeInstanceOf(Analyzer::class);
    }
});

it('skips disabled, missing and invalid analyzers', function (): void {
    config()->set('sherlock.analyzers', [
        SlowQueryAnalyzer::class => true,
        NPlusOneAnalyzer::class => false,
        'App\\Missing\\Analyzer',
        stdClass::class,
    ]);
    freshAnalysisServices();

    expect(array_map(fn (Analyzer $a): string => $a::class, configuredAnalyzers()))->toBe([SlowQueryAnalyzer::class]);
});

it('applies configured thresholds to the built-in analyzers', function (): void {
    config()->set('sherlock.thresholds.slow_query', ['warning_ms' => 10, 'critical_ms' => 20]);
    config()->set('sherlock.explain.enabled', false);
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

    config()->set('sherlock.explain.environments', ['production']);
    freshAnalysisServices();
    expect(app(ExplainService::class)->enabled())->toBeFalse();

    config()->set('sherlock.explain.environments', 'production, testing');
    freshAnalysisServices();
    expect(app(ExplainService::class)->enabled())->toBeTrue();

    config()->set('sherlock.explain.enabled', false);
    freshAnalysisServices();
    expect(app(ExplainService::class)->enabled())->toBeFalse();
});
