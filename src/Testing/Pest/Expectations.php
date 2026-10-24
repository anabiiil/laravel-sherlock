<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing\Pest;

use Anabil\Sherlock\Testing\ProbeResult;
use Anabil\Sherlock\Testing\SherlockAssert;
use Pest\Expectation;
use PHPUnit\Framework\Assert;

/**
 * Pest expectations on a callback, registered with one line in tests/Pest.php:
 *
 *     Anabil\Sherlock\Testing\Pest\Expectations::register();
 *
 *     expect(fn () => $this->get('/orders'))->toHaveNoNPlusOne();
 *     expect(fn () => $this->get('/orders'))->toRunQueriesLessThan(10);
 *
 * Nothing here is loaded unless a test calls register(); without Pest installed it is a no-op.
 * ->not works as usual (the expectation fails with PHPUnit's ExpectationFailedException).
 */
final class Expectations
{
    public const NAMES = [
        'toHaveNoNPlusOne',
        'toHaveNoDuplicateQueries',
        'toHaveNoSlowQueries',
        'toHaveNoQueryIssues',
        'toRunQueriesLessThan',
        'toRunQueries',
    ];

    public static function register(): void
    {
        if (! class_exists(Expectation::class)) {
            return;
        }

        // Closures are built in an instance method: Pest rebinds them to the expectation,
        // which a closure created in a static scope would refuse.
        (new self)->extend(new Expectation(null));
    }

    /**
     * @param  Expectation<null>  $pest
     */
    private function extend(Expectation $pest): void
    {
        $pest->extend('toHaveNoNPlusOne', function (?int $threshold = null): Expectation {
            SherlockAssert::noNPlusOne(Expectations::probe($this->value), $threshold);

            return $this;
        });

        $pest->extend('toHaveNoDuplicateQueries', function (?int $minCount = null): Expectation {
            SherlockAssert::noDuplicateQueries(Expectations::probe($this->value), $minCount);

            return $this;
        });

        $pest->extend('toHaveNoSlowQueries', function (int|float|null $ms = null): Expectation {
            SherlockAssert::noSlowQueries(Expectations::probe($this->value), $ms === null ? null : (float) $ms);

            return $this;
        });

        $pest->extend('toHaveNoQueryIssues', function (): Expectation {
            SherlockAssert::noQueryIssues(Expectations::probe($this->value));

            return $this;
        });

        $pest->extend('toRunQueriesLessThan', function (int $max): Expectation {
            SherlockAssert::queryCountLessThan(Expectations::probe($this->value), $max);

            return $this;
        });

        $pest->extend('toRunQueries', function (int $expected): Expectation {
            SherlockAssert::queryCount(Expectations::probe($this->value), $expected);

            return $this;
        });
    }

    /**
     * @internal used by the registered expectations
     */
    public static function probe(mixed $value): ProbeResult
    {
        Assert::assertIsCallable($value, 'Sherlock query expectations need a callback: expect(fn () => ...).');

        return SherlockAssert::probe($value);
    }
}
