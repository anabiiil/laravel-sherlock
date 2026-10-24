<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

/**
 * Query assertions for PHPUnit / Pest test cases: catch N+1, duplicate, slow and excessive
 * queries before production. Each assertion runs the callback once, records its queries
 * (in every sherlock.mode, independently from the global capture) and returns the
 * callback's return value, so it chains:
 *
 *     $response = $this->assertNoNPlusOne(fn () => $this->get('/orders'));
 *     $response->assertOk();
 *
 * Thresholds default to "sherlock.testing.*", then "sherlock.thresholds.*" (N+1: 2 in
 * tests, see ProbeThresholds) and can be overridden per assertion.
 */
trait InteractsWithSherlock
{
    /**
     * Runs $callback and returns everything it queried, for custom checks.
     */
    public function sherlockProbe(callable $callback): ProbeResult
    {
        return SherlockAssert::probe($callback);
    }

    /**
     * Fails when the same query runs $threshold+ times from the same line (lazy loading in a loop).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function assertNoNPlusOne(callable $callback, ?int $threshold = null, string $message = ''): mixed
    {
        $result = SherlockAssert::probe($callback);
        SherlockAssert::noNPlusOne($result, $threshold, $message);

        return $result->value;
    }

    /**
     * Fails when identical SQL with identical bindings runs $minCount+ times.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function assertNoDuplicateQueries(callable $callback, ?int $minCount = null, string $message = ''): mixed
    {
        $result = SherlockAssert::probe($callback);
        SherlockAssert::noDuplicateQueries($result, $minCount, $message);

        return $result->value;
    }

    /**
     * Fails when a query takes $ms milliseconds or more.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function assertNoSlowQueries(callable $callback, ?float $ms = null, string $message = ''): mixed
    {
        $result = SherlockAssert::probe($callback);
        SherlockAssert::noSlowQueries($result, $ms, $message);

        return $result->value;
    }

    /**
     * Fails when the callback runs $max queries or more.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function assertQueryCountLessThan(int $max, callable $callback, string $message = ''): mixed
    {
        $result = SherlockAssert::probe($callback);
        SherlockAssert::queryCountLessThan($result, $max, $message);

        return $result->value;
    }

    /**
     * Fails unless the callback runs exactly $expected queries.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function assertQueryCount(int $expected, callable $callback, string $message = ''): mixed
    {
        $result = SherlockAssert::probe($callback);
        SherlockAssert::queryCount($result, $expected, $message);

        return $result->value;
    }

    /**
     * N+1, duplicates, slow queries and too many queries at once, with the configured thresholds.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function assertNoQueryIssues(callable $callback, string $message = ''): mixed
    {
        $result = SherlockAssert::probe($callback);
        SherlockAssert::noQueryIssues($result, $message);

        return $result->value;
    }
}
