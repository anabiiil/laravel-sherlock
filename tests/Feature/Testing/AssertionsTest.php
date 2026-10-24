<?php

declare(strict_types=1);

use Anabil\Sherlock\Testing\InteractsWithSherlock;
use Anabil\Sherlock\Testing\ProbeThresholds;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeAuthor;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbePost;
use Anabil\Sherlock\Tests\Feature\Testing\Support\ProbeFixtures;
use Illuminate\Support\Facades\DB;

uses(InteractsWithSherlock::class, ProbeFixtures::class);

beforeEach(function (): void {
    config()->set('sherlock.explain.enabled', false);
    self::createProbeSchema();
});

afterEach(fn () => self::dropProbeSchema());

describe('assertNoNPlusOne', function (): void {
    it('passes for eager loaded relations and returns the callback value', function (): void {
        $counts = $this->assertNoNPlusOne(fn () => ProbeAuthor::query()->with('probePosts')->get()->map(fn (ProbeAuthor $a): int => $a->probePosts->count())->all());

        expect($counts)->toBe([2, 2, 2]);
    });

    it('fails for a lazy loaded relation, naming the table, SQL, count, file:line and the eager load fix', function (): void {
        $message = self::failureOf(fn () => $this->assertNoNPlusOne(function (): void {
            foreach (ProbeAuthor::query()->get() as $author) {
                $author->probePosts->count(); // @lazy-posts
            }
        }));

        expect($message)
            ->toContain('Failed asserting that the code ran no N+1 queries (the same query 2+ times from one line)')
            ->toContain('Sherlock found 1 issue')
            ->toContain('N+1 query on `probe_posts` (3×)')
            ->toContain('select * from `probe_posts` where `probe_posts`.`probe_author_id` = ?')
            ->toContain('Count:    3 times')
            ->toContain('1) N+1 query on `probe_posts` (3×)'."\n")
            ->toContain('Location: '.self::locationOf(__FILE__, 'lazy-posts').' (closure)')
            ->toContain("ProbeAuthor::with('probePosts')->get()")
            ->not->toContain('author-1'); // normalized SQL only, never values
    });

    it('defaults to 2 repetitions in tests and accepts a per-assertion threshold', function (): void {
        $lazy = function (): void {
            foreach (ProbeAuthor::query()->get() as $author) {
                $author->probePosts->count();
            }
        };

        // 3 lazy loads: an N+1 at the test default (2), not with a threshold of 4.
        expect(self::failureOf(fn () => $this->assertNoNPlusOne($lazy)))->toContain('(3×)');
        $this->assertNoNPlusOne($lazy, threshold: 4);

        config()->set('sherlock.testing.n_plus_one', 4);
        $this->assertNoNPlusOne($lazy);
    });

    it('finds the N+1 inside a nested HTTP request, at the controller line', function (): void {
        $response = $this->assertNoNPlusOne(fn () => $this->get('/probe/eager'));
        $response->assertOk()->assertJson(['author-1' => 2]);

        $message = self::failureOf(fn () => $this->assertNoNPlusOne(fn () => $this->get('/probe/lazy')));

        expect($message)
            ->toContain('1) N+1 query on `probe_posts` (3×)'."\n")
            ->toContain('Location: '.self::locationOf(self::controllerFile(), 'controller-n-plus-one').' (Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeController::lazy)');
    });
});

describe('assertNoDuplicateQueries', function (): void {
    it('passes when every query is distinct', function (): void {
        $this->assertNoDuplicateQueries(fn () => ProbeAuthor::query()->with('probePosts')->get());
    });

    it('fails for identical SQL and bindings, with the redundant count and location', function (): void {
        $message = self::failureOf(fn () => $this->assertNoDuplicateQueries(function (): void {
            foreach (ProbePost::query()->where('probe_author_id', 1)->get() as $post) {
                $post->probeAuthor->name; // @duplicate-author
            }
        }));

        expect($message)
            ->toContain('ran no duplicate queries (identical SQL and bindings 2+ times)')
            ->toContain('Duplicate query on `probe_authors` (2× with identical bindings)')
            ->toContain('select * from `probe_authors` where `probe_authors`.`id` = ? limit ?')
            ->toContain('Location: '.self::locationOf(__FILE__, 'duplicate-author'))
            ->toContain('Run the query once and reuse the result');
    });
});

describe('query counts', function (): void {
    it('assertQueryCount / assertQueryCountLessThan pass on the right count and return the value', function (): void {
        $authors = $this->assertQueryCount(2, fn () => ProbeAuthor::query()->with('probePosts')->get());
        expect($authors)->toHaveCount(3);

        $this->assertQueryCountLessThan(3, fn () => ProbeAuthor::query()->with('probePosts')->get());
        $this->assertQueryCount(0, fn () => 'no queries');
    });

    it('fails with the grouped query list and the likely N+1 cause', function (): void {
        $lazy = function (): void {
            foreach (ProbeAuthor::query()->get() as $author) {
                $author->probePosts->count(); // @count-lazy
            }
        };

        $message = self::failureOf(fn () => $this->assertQueryCountLessThan(3, $lazy));

        expect($message)
            ->toContain('Failed asserting that the code ran fewer than 3 queries (it ran 4)')
            ->toContain('Queries (4, ')
            ->toContain('3×  select * from `probe_posts` where `probe_posts`.`probe_author_id` = ? and `probe_posts`.`probe_author_id` is not null  ('.self::locationOf(__FILE__, 'count-lazy').' (closure))')
            ->toContain('1×  select * from `probe_authors`')
            ->toContain('Likely cause:')
            ->toContain("with('probePosts')");

        expect(self::failureOf(fn () => $this->assertQueryCount(2, $lazy)))
            ->toContain('the code ran exactly 2 queries (it ran 4)');
    });
});

describe('assertNoSlowQueries', function (): void {
    it('passes under the threshold and fails over it with the location and EXPLAIN hint', function (): void {
        $this->assertNoSlowQueries(fn () => DB::select('select 1'), 50);

        $message = self::failureOf(fn () => $this->assertNoSlowQueries(function (): void {
            DB::select('select sleep(0.05)'); // @slow-sleep
        }, ms: 30));

        expect($message)
            ->toContain('ran no slow queries (none taking 30ms or more)')
            ->toContain('Slow query on')
            ->toContain('select sleep(?)')
            ->toContain('Location: '.self::locationOf(__FILE__, 'slow-sleep'))
            ->toContain('Run EXPLAIN on this query');
    });

    it('defaults to sherlock.thresholds.slow_query.warning_ms, then sherlock.testing.slow_query_ms', function (): void {
        $slow = fn () => DB::select('select sleep(0.04)');

        config()->set('sherlock.thresholds.slow_query.warning_ms', 1000);
        $this->assertNoSlowQueries($slow);

        config()->set('sherlock.thresholds.slow_query.warning_ms', 20);
        expect(self::failureOf(fn () => $this->assertNoSlowQueries($slow)))->toContain('none taking 20ms or more');

        config()->set('sherlock.testing.slow_query_ms', 1000);
        $this->assertNoSlowQueries($slow);
    });
});

describe('assertNoQueryIssues', function (): void {
    it('passes for clean code', function (): void {
        $response = $this->assertNoQueryIssues(fn () => $this->get('/probe/eager'));

        $response->assertOk();
    });

    it('reports every issue at once: N+1, duplicates, slow and excessive queries', function (): void {
        config()->set('sherlock.testing.max_queries', 5);
        config()->set('sherlock.testing.slow_query_ms', 30);

        $message = self::failureOf(fn () => $this->assertNoQueryIssues(function (): void {
            foreach (ProbePost::query()->get() as $post) {
                $post->probeAuthor->name;
            }
            DB::select('select sleep(0.04)');
        }));

        expect($message)
            ->toContain('ran no query issues (N+1: 2+ from one line, duplicates: 2+ identical, slow: 30ms+, total: fewer than 5 queries)')
            ->toContain('N+1 query on `probe_authors` (6×)')
            ->toContain('Duplicate query on `probe_authors`')
            ->toContain('Slow query on')
            ->toContain('Excessive queries: 8 queries in the test callback')
            ->toContain('Count:    8 queries, ');
    });
});

it('exposes the raw probe for custom checks', function (): void {
    $probe = $this->sherlockProbe(fn () => ProbeAuthor::query()->where('name', 'author-2')->first());

    expect($probe->value)->toBeInstanceOf(ProbeAuthor::class)
        ->and($probe->count())->toBe(1)
        ->and($probe->totalMs())->toBeGreaterThan(0.0)
        ->and($probe->queries()[0]->bindings)->toBe(['author-2'])
        ->and($probe->queries()[0]->normalizedSql)->toBe('select * from `probe_authors` where `name` = ? limit ?')
        ->and($probe->queries()[0]->connection)->toBe('mysql')
        ->and($probe->queries()[0]->location?->file)->toBe('tests/Feature/Testing/AssertionsTest.php')
        ->and($probe->findings())->toBe([])
        ->and($probe->hasIssues())->toBeFalse()
        ->and($probe->thresholds)->toEqual(new ProbeThresholds(2, 2, 100.0, 50));
});
