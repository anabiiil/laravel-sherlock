<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\DuplicateQueryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ExceptionAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ExcessiveQueriesAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\HighMemoryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowEntryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowExternalCallAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\LazyLoadRecord;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;

/**
 * @param  list<LazyLoadRecord>  $lazyLoads
 */
function withLazyLoads(TraceData $trace, array $lazyLoads): TraceData
{
    return new TraceData(
        id: $trace->id, type: $trace->type, name: $trace->name, startedAt: $trace->startedAt, durationMs: $trace->durationMs,
        method: $trace->method, queries: $trace->queries, lazyLoads: $lazyLoads,
    );
}

describe('impact', function (): void {
    it('attributes the time each problem cost to its finding', function (): void {
        $loc = F::location();
        $n1 = F::enrich(F::trace(F::repeat(F::query('select * from `posts` where `user_id` = 1', 3.0, location: $loc), 10)));

        expect((new NPlusOneAnalyzer(F::fingerprinter(), 5, 20))->analyze($n1)[0]->impactMs)->toBe(30.0)
            ->and((new SlowQueryAnalyzer(F::fingerprinter(), 100, 1000))->analyze(F::enrich(F::trace([
                F::query('select * from `orders` where `id` = 1', 300.0, location: $loc),
                F::query('select * from `orders` where `id` = 2', 200.0, location: $loc),
            ])))[0]->impactMs)->toBe(500.0)
            ->and((new SlowEntryAnalyzer(F::fingerprinter()))->analyze(F::trace(durationMs: 1750.0))[0]->impactMs)->toBe(750.0)
            ->and((new ExcessiveQueriesAnalyzer(F::fingerprinter(), 3, 10))->analyze(F::enrich(F::trace(F::repeat(F::query('select 1', 2.5), 4))))[0]->impactMs)->toBe(10.0)
            ->and((new SlowExternalCallAnalyzer(F::fingerprinter(), 1000, 5000))->analyze(F::trace(httpCalls: [
                new HttpCallRecord('GET', 'https://api.example.com/a', 200, 1200),
                new HttpCallRecord('GET', 'https://api.example.com/b', 200, 300),
            ]))[0]->impactMs)->toBe(1200.0)
            ->and((new HighMemoryAnalyzer(F::fingerprinter(), 1, 2))->analyze(F::trace(memoryPeakBytes: 5 * 1048576))[0]->impactMs)->toBe(0.0);
    });

    it('counts the wasted executions of duplicate queries', function (): void {
        $loc = F::location();
        $trace = F::enrich(F::trace(F::repeat(F::query('select * from `settings` where `id` = ?', 4.0, ['1'], $loc), 3)));

        expect((new DuplicateQueryAnalyzer(F::fingerprinter(), 2))->analyze($trace)[0]->impactMs)->toBe(8.0);
    });

    it('charges a configurable cost per exception', function (): void {
        $trace = F::trace(logs: [new LogRecord('error', 'boom', RuntimeException::class, 'app/Jobs/Sync.php', 10)]);

        expect((new ExceptionAnalyzer(F::fingerprinter()))->analyze($trace)[0]->impactMs)->toBe(0.0)
            ->and((new ExceptionAnalyzer(F::fingerprinter(), null, ExceptionAnalyzer::DEFAULT_LOG_LEVELS, 250.0))->analyze($trace)[0]->impactMs)->toBe(250.0);
    });

    it('keeps the impact through copies and serialization', function (): void {
        $finding = new Finding(IssueType::SlowQuery, Severity::Warning, 't', 'd', 'fp', impactMs: 12.5);

        expect($finding->withSeverity(Severity::Critical)->impactMs)->toBe(12.5)
            ->and($finding->withRecommendation('x')->impactMs)->toBe(12.5)
            ->and($finding->toArray()['impact_ms'])->toBe(12.5)
            ->and(Finding::fromArray($finding->toArray())->impactMs)->toBe(12.5)
            ->and(Finding::fromArray(['type' => 'slow_query', 'severity' => 'info', 'title' => 't', 'fingerprint' => 'f'])->impactMs)->toBe(0.0);
    });
});

describe('N+1 with lazy-loading violations', function (): void {
    beforeEach(fn () => $this->analyzer = new NPlusOneAnalyzer(F::fingerprinter(), 5, 20));

    it('names the exact relation recorded by Eloquent instead of guessing it', function (): void {
        $loc = F::location();
        $trace = withLazyLoads(
            F::enrich(F::trace(F::repeat(F::query('select * from `articles` where `articles`.`author_id` = 1', 2.0, location: $loc), 8))),
            [new LazyLoadRecord('App\\Models\\User', 'posts', 8, 'articles')],
        );

        $findings = $this->analyzer->analyze($trace);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->title)->toBe('N+1 query on `articles` (8×) lazy loading User::posts in OrderController@index')
            ->and($findings[0]->metrics['relation'])->toBe('posts')
            ->and($findings[0]->metrics['model'])->toBe('App\\Models\\User')
            ->and($findings[0]->metrics['relation_source'])->toBe('lazy_loading')
            ->and($findings[0]->description)->toContain('App\\Models\\User::posts')
            ->and($findings[0]->recommendation)->toContain("User::with('posts')");
    });

    it('keeps the issue fingerprint of a guessed N+1 when the relation becomes known', function (): void {
        $loc = F::location();
        $plain = F::enrich(F::trace(F::repeat(F::query('select * from `posts` where `posts`.`user_id` = 1', 2.0, location: $loc), 6)));

        $guessed = $this->analyzer->analyze($plain)[0];
        $named = $this->analyzer->analyze(withLazyLoads($plain, [new LazyLoadRecord('App\\Models\\User', 'posts', 6, 'posts')]))[0];

        expect($guessed->metrics['relation_source'])->toBe('guess')
            ->and($named->fingerprint)->toBe($guessed->fingerprint);
    });

    it('reports a lazy load on a collection below the repeat threshold as an info N+1', function (): void {
        $loc = F::location();
        $trace = withLazyLoads(
            F::enrich(F::trace(F::repeat(F::query('select * from `comments` where `comments`.`post_id` = 1', 1.5, location: $loc), 3))),
            [new LazyLoadRecord('App\\Models\\Post', 'comments', 3, 'comments'), new LazyLoadRecord('App\\Models\\Post', 'tags', 2, null)],
        );

        $findings = $this->analyzer->analyze($trace);

        expect($findings)->toHaveCount(2)
            ->and($findings[0]->type)->toBe(IssueType::NPlusOne)
            ->and($findings[0]->severity)->toBe(Severity::Info)
            ->and($findings[0]->title)->toBe('Lazy loading of Post::comments on a collection (3×) in OrderController@index')
            ->and($findings[0]->sql)->toBe('select * from `comments` where `comments`.`post_id` = ?')
            ->and($findings[0]->impactMs)->toBe(4.5)
            ->and($findings[0]->recommendation)->toContain("Post::with('comments')")
            ->and($findings[1]->title)->toBe('Lazy loading of Post::tags on a collection (2×) in GET /orders')
            ->and($findings[1]->impactMs)->toBe(0.0)
            ->and($findings[0]->fingerprint)->not->toBe($findings[1]->fingerprint);
    });

    it('matches several lazy loads on the same table by count', function (): void {
        $a = F::location(10);
        $b = F::location(20);
        $trace = withLazyLoads(F::enrich(F::trace([
            ...F::repeat(F::query('select * from `users` where `users`.`id` = 1', 1.0, location: $a), 6),
            ...F::repeat(F::query('select * from `users` where `users`.`id` = 2 limit 1', 1.0, location: $b), 12),
        ])), [
            new LazyLoadRecord('App\\Models\\Post', 'author', 6, 'users'),
            new LazyLoadRecord('App\\Models\\Comment', 'user', 12, 'users'),
        ]);

        $titles = array_map(static fn (Finding $f): string => $f->title, $this->analyzer->analyze($trace));

        expect($titles)->toHaveCount(2)
            ->and($titles[0])->toContain('lazy loading Post::author')
            ->and($titles[1])->toContain('lazy loading Comment::user');
    });
});
