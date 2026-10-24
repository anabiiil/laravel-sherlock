<?php

declare(strict_types=1);

use Anabil\Sherlock\Testing\Pest\Expectations;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeAuthor;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbePost;
use Anabil\Sherlock\Tests\Feature\Testing\Support\ProbeFixtures;
use Illuminate\Support\Facades\DB;
use Pest\Expectation;
use PHPUnit\Framework\ExpectationFailedException;

uses(ProbeFixtures::class);

beforeEach(function (): void {
    config()->set('sherlock.explain.enabled', false);
    self::createProbeSchema();
    Expectations::register();
});

afterEach(fn () => self::dropProbeSchema());

$lazy = static function (): void {
    foreach (ProbeAuthor::query()->get() as $author) {
        $author->probePosts->count(); // @pest-lazy
    }
};

$eager = static fn () => ProbeAuthor::query()->with('probePosts')->get();

it('registers every expectation and is idempotent', function (): void {
    Expectations::register();

    foreach (Expectations::NAMES as $name) {
        expect(Expectation::hasExtend($name))->toBeTrue();
    }
});

it('toHaveNoNPlusOne passes for eager loading, fails with the location, and supports ->not', function () use ($lazy, $eager): void {
    expect($eager)->toHaveNoNPlusOne();
    expect($lazy)->toHaveNoNPlusOne(threshold: 4);
    expect($lazy)->not->toHaveNoNPlusOne();

    expect(self::failureOf(fn () => expect($lazy)->toHaveNoNPlusOne()))
        ->toContain('N+1 query on `probe_posts` (3×)')
        ->toContain('Location: '.self::locationOf(__FILE__, 'pest-lazy'))
        ->toContain("with('probePosts')");
});

it('toHaveNoDuplicateQueries', function () use ($eager): void {
    $duplicates = fn () => ProbePost::query()->get()->each(fn (ProbePost $post): string => $post->probeAuthor->name);

    expect($eager)->toHaveNoDuplicateQueries();
    expect($duplicates)->not->toHaveNoDuplicateQueries();
    expect(self::failureOf(fn () => expect($duplicates)->toHaveNoDuplicateQueries()))->toContain('Duplicate query on `probe_authors`');
});

it('toRunQueriesLessThan / toRunQueries', function () use ($lazy, $eager): void {
    expect($eager)->toRunQueriesLessThan(3)->toRunQueries(2);
    expect($lazy)->not->toRunQueriesLessThan(4);

    expect(self::failureOf(fn () => expect($lazy)->toRunQueriesLessThan(2)))
        ->toContain('the code ran fewer than 2 queries (it ran 4)')
        ->toContain('Likely cause:');
});

it('toHaveNoSlowQueries', function (): void {
    $slow = fn () => DB::select('select sleep(0.04)');

    expect(fn () => DB::select('select 1'))->toHaveNoSlowQueries(30);
    expect($slow)->not->toHaveNoSlowQueries(20);
    expect(self::failureOf(fn () => expect($slow)->toHaveNoSlowQueries(20)))->toContain('none taking 20ms or more');
});

it('toHaveNoQueryIssues, including a nested HTTP request', function (): void {
    expect(fn () => $this->get('/probe/eager')->assertOk())->toHaveNoQueryIssues();
    expect(fn () => $this->get('/probe/lazy'))->not->toHaveNoQueryIssues();

    expect(self::failureOf(fn () => expect(fn () => $this->get('/probe/lazy'))->toHaveNoQueryIssues()))
        ->toContain('Location: '.self::locationOf(self::controllerFile(), 'controller-n-plus-one'));
});

it('rejects a value that is not a callback', function (): void {
    expect(self::failureOf(fn () => expect('select 1')->toHaveNoNPlusOne()))
        ->toContain('Sherlock query expectations need a callback');
});

it('fails with PHPUnit\'s ExpectationFailedException', function () use ($lazy): void {
    expect(fn () => expect($lazy)->toHaveNoNPlusOne())->toThrow(ExpectationFailedException::class);
});
