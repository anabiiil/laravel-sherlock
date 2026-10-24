<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\DuplicateQueryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\ExcessiveQueriesAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;

// ---------------------------------------------------------------- SlowQueryAnalyzer

describe('SlowQueryAnalyzer', function (): void {
    beforeEach(fn () => $this->analyzer = new SlowQueryAnalyzer(F::fingerprinter(), 100, 1000));

    it('ignores fast queries and flags the threshold edge', function (): void {
        $trace = F::enrich(F::trace([
            F::query('select * from `orders` where `id` = 1', 99.9),
            F::query('select * from `users` where `id` = 1', 100.0, location: F::location()),
        ]));

        $findings = $this->analyzer->analyze($trace);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->type)->toBe(IssueType::SlowQuery)
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->title)->toBe('Slow query on `users` (100ms) in OrderController@index')
            ->and($findings[0]->sql)->toBe('select * from `users` where `id` = ?')
            ->and($findings[0]->metrics['time_ms'])->toBe(100.0)
            ->and($findings[0]->recommendation)->toContain('EXPLAIN')->toContain('instead of `*`');
    });

    it('escalates to critical at the critical threshold and aggregates repeats', function (): void {
        $loc = F::location();
        $trace = F::enrich(F::trace([
            F::query('select * from `orders` where `user_id` = 1', 300, location: $loc),
            F::query('select * from `orders` where `user_id` = 2', 1000, location: $loc),
        ]));

        $findings = $this->analyzer->analyze($trace);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->severity)->toBe(Severity::Critical)
            ->and($findings[0]->metrics['count'])->toBe(2)
            ->and($findings[0]->metrics['total_ms'])->toBe(1300.0)
            ->and($findings[0]->title)->toContain('(1.00s, 2×)');
    });

    it('has a fingerprint stable across traces with different literal values', function (): void {
        $a = $this->analyzer->analyze(F::enrich(F::trace([F::query('select * from `orders` where `user_id` = 1', 150, location: F::location())])));
        $b = $this->analyzer->analyze(F::enrich(F::trace([F::query("select * from `orders` where `user_id` = 999 and 'x' = 'x'", 150, location: F::location())])));
        $c = $this->analyzer->analyze(F::enrich(F::trace([F::query('select * from `orders` where `user_id` = 7', 900, location: F::location())])));

        expect($a[0]->fingerprint)->toBe($c[0]->fingerprint)
            ->and($a[0]->fingerprint)->not->toBe($b[0]->fingerprint)
            ->and($a[0]->queryFingerprint)->toBe($c[0]->queryFingerprint);
    });

    it('works on non-enriched queries and gives write-specific advice', function (): void {
        $findings = $this->analyzer->analyze(F::trace([F::query('update `orders` set `status` = 1 where `user_id` = 3', 500)]));

        expect($findings[0]->queryFingerprint)->toBe(F::fingerprinter()->rawQuery('update `orders` set `status` = 9 where `user_id` = 4'))
            ->and($findings[0]->recommendation)->toContain('UPDATE')->toContain('chunkById');
    });
});

// ---------------------------------------------------------------- NPlusOneAnalyzer

describe('NPlusOneAnalyzer', function (): void {
    beforeEach(fn () => $this->analyzer = new NPlusOneAnalyzer(F::fingerprinter(), 5, 20));

    $loop = function (int $times, ?int $line = 42, int $offset = 0): array {
        $queries = [];
        for ($i = 0; $i < $times; $i++) {
            // capture only locates the first occurrence of a fingerprint
            $location = $i === 0 && $line !== null ? F::location($line) : null;
            $queries[] = F::query('select * from `posts` where `posts`.`user_id` = '.($i + $offset).' and `posts`.`user_id` is not null', 2.0, location: $location);
        }

        return $queries;
    };

    it('detects the N+1 at the threshold with a useful title and eager loading advice', function () use ($loop): void {
        $findings = $this->analyzer->analyze(F::enrich(F::trace($loop(5))));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->type)->toBe(IssueType::NPlusOne)
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->title)->toBe('N+1 query on `posts` (5×) in OrderController@index')
            ->and($findings[0]->metrics['count'])->toBe(5)
            ->and($findings[0]->metrics['relation'])->toBe('posts')
            ->and($findings[0]->recommendation)->toContain("User::with('posts')")->toContain('with()')
            ->and($findings[0]->location?->line)->toBe(42);
    });

    it('counts the unlocated first execution of a light-capture loop (located on its second)', function (): void {
        $queries = [];
        for ($i = 0; $i < 5; $i++) {
            $queries[] = F::query('select * from `posts` where `posts`.`user_id` = ?', 2.0, location: $i === 1 ? F::location(42) : null);
        }

        $findings = $this->analyzer->analyze(F::enrich(F::trace($queries)));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->metrics['count'])->toBe(5)
            ->and($findings[0]->location?->line)->toBe(42)
            ->and($findings[0]->title)->toBe('N+1 query on `posts` (5×) in OrderController@index');
    });

    it('stays silent below the threshold', function () use ($loop): void {
        expect($this->analyzer->analyze(F::enrich(F::trace($loop(4)))))->toBe([]);
    });

    it('escalates to critical by count', function () use ($loop): void {
        expect($this->analyzer->analyze(F::enrich(F::trace($loop(19))))[0]->severity)->toBe(Severity::Warning)
            ->and($this->analyzer->analyze(F::enrich(F::trace($loop(37))))[0]->severity)->toBe(Severity::Critical)
            ->and($this->analyzer->analyze(F::enrich(F::trace($loop(37))))[0]->title)->toContain('(37×)');
    });

    it('separates the same query from different source locations', function () use ($loop): void {
        $findings = $this->analyzer->analyze(F::enrich(F::trace([...$loop(4, 10), ...$loop(4, 20)])));

        expect($findings)->toBe([]);
    });

    it('groups by fingerprint alone when no location was captured', function () use ($loop): void {
        $findings = $this->analyzer->analyze(F::enrich(F::trace($loop(6, null))));

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->location)->toBeNull()
            ->and($findings[0]->title)->toEndWith('in GET /orders');
    });

    it('has a fingerprint stable across traces with different ids', function () use ($loop): void {
        $a = $this->analyzer->analyze(F::enrich(F::trace($loop(6, 42, 0))));
        $b = $this->analyzer->analyze(F::enrich(F::trace($loop(30, 42, 1000))));

        expect($a[0]->fingerprint)->toBe($b[0]->fingerprint);
    });

    it('guesses belongsTo relations and batches repeated writes', function (): void {
        expect(NPlusOneAnalyzer::guessRelation('select * from `users` where `users`.`id` = ? limit ?', 'users'))->toBe(['user', null])
            ->and(NPlusOneAnalyzer::guessRelation('select * from `order_items` where `order_items`.`order_id` IN (?+)', 'order_items'))->toBe(['orderItems', 'Order']);

        $writes = array_map(fn (int $i) => F::query("insert into `logs` (`msg`) values ('m$i')"), range(1, 6));
        $finding = $this->analyzer->analyze(F::enrich(F::trace($writes)))[0];

        expect($finding->recommendation)->toContain('insert([...])');
    });
});

// ---------------------------------------------------------------- DuplicateQueryAnalyzer

describe('DuplicateQueryAnalyzer', function (): void {
    beforeEach(fn () => $this->analyzer = new DuplicateQueryAnalyzer(F::fingerprinter(), 2));

    it('flags identical SQL with identical bindings', function (): void {
        $sql = 'select * from `settings` where `key` = ?';
        $trace = F::enrich(F::trace([
            F::query($sql, 3, ['theme'], F::location(12)),
            F::query($sql, 3, ['theme']),
            F::query($sql, 3, ['theme']),
            F::query($sql, 3, ['locale']),
        ], full: true));

        $findings = $this->analyzer->analyze($trace);

        expect($findings)->toHaveCount(1)
            ->and($findings[0]->type)->toBe(IssueType::DuplicateQuery)
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->metrics['count'])->toBe(3)
            ->and($findings[0]->metrics['redundant'])->toBe(2)
            ->and($findings[0]->title)->toBe('Duplicate query on `settings` (3× with identical bindings) in OrderController@index')
            ->and($findings[0]->recommendation)->toContain('once()');
    });

    it('does not flag the same SQL with different bindings', function (): void {
        $sql = 'select * from `users` where `id` = ?';

        expect($this->analyzer->analyze(F::enrich(F::trace([F::query($sql, 1, [1]), F::query($sql, 1, [2])]))))->toBe([]);
    });

    it('skips silently without bindings (light traces)', function (): void {
        $sql = 'select * from `users` where `id` = ?';

        expect($this->analyzer->analyze(F::enrich(F::trace(F::repeat(F::query($sql), 10)))))->toBe([]);
    });

    it('skips redacted bindings that would look identical', function (): void {
        $sql = 'select * from `users` where `password` = ?';

        expect($this->analyzer->analyze(F::enrich(F::trace(F::repeat(F::query($sql, 1, ['[REDACTED]']), 3)))))->toBe([]);
    });
});

// ---------------------------------------------------------------- ExcessiveQueriesAnalyzer

describe('ExcessiveQueriesAnalyzer', function (): void {
    beforeEach(fn () => $this->analyzer = new ExcessiveQueriesAnalyzer(F::fingerprinter(), 50, 200));

    it('flags at the threshold, names the most repeated query and escalates', function (): void {
        $queries = [...F::repeat(F::query('select * from `posts` where `user_id` = ?'), 40), ...F::repeat(F::query('select * from `tags`'), 10)];

        $warning = $this->analyzer->analyze(F::enrich(F::trace($queries)));

        expect($warning)->toHaveCount(1)
            ->and($warning[0]->severity)->toBe(Severity::Warning)
            ->and($warning[0]->title)->toBe('Excessive queries: 50 queries in GET /orders')
            ->and($warning[0]->metrics['top_count'])->toBe(40)
            ->and($warning[0]->metrics['top_table'])->toBe('posts')
            ->and($warning[0]->metrics['distinct'])->toBe(2);

        expect($this->analyzer->analyze(F::enrich(F::trace(array_slice($queries, 0, 49)))))->toBe([]);

        // real count beyond capture caps
        $critical = $this->analyzer->analyze(F::enrich(F::trace($queries, totalQueryCount: 250)));
        expect($critical[0]->severity)->toBe(Severity::Critical)
            ->and($critical[0]->fingerprint)->toBe($warning[0]->fingerprint);
    });
});

// ---------------------------------------------------------------- Describe::location

describe('Describe::location', function (): void {
    it('renders closures without absolute paths, keeping fingerprints on file:line', function (?string $class, string $function, string $expected): void {
        $location = new SourceLocation('routes/web.php', 23, $class, $function);

        expect(Describe::location($location))->toBe($expected)
            ->and(Describe::location($location))->not->toContain('/var/www');
    })->with([
        'php 8.4 file closure' => ['Illuminate\Routing\RouteFileRegistrar', '{closure:routes/web.php:21}', 'routes/web.php:21 (closure)'],
        'php 8.4 method closure' => ['App\Http\Controllers\SmokeController', '{closure:App\Http\Controllers\SmokeController::closureNplusone():100}', 'SmokeController::closureNplusone (closure)'],
        'php 8.4 nested closure' => [null, '{closure:{closure:routes/web.php:21}:22}', 'routes/web.php:21 (closure)'],
        'absolute path left in the name' => ['Illuminate\Routing\RouteFileRegistrar', '{closure:/var/www/app/routes/web.php:21}', 'web.php:21 (closure)'],
        'pre-8.4 closure in a class' => ['App\Http\Controllers\SmokeController', '{closure}', 'SmokeController (closure)'],
        'pre-8.4 route file closure' => ['Illuminate\Routing\RouteFileRegistrar', '{closure}', 'routes/web.php:23 (closure)'],
        'method' => ['App\Http\Controllers\OrderController', 'index', 'OrderController@index'],
    ]);

    it('gives the same issue fingerprint whatever the closure name looks like', function (): void {
        $fingerprinter = F::fingerprinter();
        $a = $fingerprinter->issue(IssueType::NPlusOne, 'q', new SourceLocation('routes/web.php', 23, null, '{closure:/var/www/app/routes/web.php:21}'), 'GET /x');
        $b = $fingerprinter->issue(IssueType::NPlusOne, 'q', new SourceLocation('routes/web.php', 23, null, '{closure:routes/web.php:21}'), 'GET /x');
        $c = $fingerprinter->issue(IssueType::NPlusOne, 'q', new SourceLocation('routes/web.php', 23, 'X', '{closure}'), 'GET /x');

        expect($a)->toBe($b)->toBe($c);
    });
});
