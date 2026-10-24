<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

use Anabil\Sherlock\Testing\Constraints\RunsNoQueryIssues;
use Anabil\Sherlock\Testing\Constraints\RunsQueryCount;
use PHPUnit\Framework\Assert;

/**
 * The assertions behind InteractsWithSherlock and the Pest expectations, on a ProbeResult.
 * A null threshold means the configured one (ProbeThresholds).
 */
final class SherlockAssert
{
    public static function probe(callable $callback): ProbeResult
    {
        return QueryProbe::make()->run($callback);
    }

    public static function noNPlusOne(ProbeResult $result, ?int $threshold = null, string $message = ''): void
    {
        $threshold = max(2, $threshold ?? $result->thresholds->nPlusOne);

        Assert::assertThat($result, new RunsNoQueryIssues(
            sprintf('ran no N+1 queries (the same query %d+ times from one line)', $threshold),
            static fn (ProbeResult $result): array => $result->nPlusOnes($threshold),
        ), $message);
    }

    public static function noDuplicateQueries(ProbeResult $result, ?int $minCount = null, string $message = ''): void
    {
        $minCount = max(2, $minCount ?? $result->thresholds->duplicates);

        Assert::assertThat($result, new RunsNoQueryIssues(
            sprintf('ran no duplicate queries (identical SQL and bindings %d+ times)', $minCount),
            static fn (ProbeResult $result): array => $result->duplicates($minCount),
        ), $message);
    }

    public static function noSlowQueries(ProbeResult $result, ?float $ms = null, string $message = ''): void
    {
        $ms = max(0.0, $ms ?? $result->thresholds->slowQueryMs);

        Assert::assertThat($result, new RunsNoQueryIssues(
            sprintf('ran no slow queries (none taking %sms or more)', self::number($ms)),
            static fn (ProbeResult $result): array => $result->slowQueries($ms),
        ), $message);
    }

    public static function noQueryIssues(ProbeResult $result, string $message = ''): void
    {
        $thresholds = $result->thresholds;

        Assert::assertThat($result, new RunsNoQueryIssues(
            sprintf(
                'ran no query issues (N+1: %d+ from one line, duplicates: %d+ identical, slow: %sms+, total: fewer than %d queries)',
                $thresholds->nPlusOne,
                $thresholds->duplicates,
                self::number($thresholds->slowQueryMs),
                $thresholds->maxQueries,
            ),
            static fn (ProbeResult $result): array => $result->findings(),
        ), $message);
    }

    public static function queryCountLessThan(ProbeResult $result, int $max, string $message = ''): void
    {
        Assert::assertThat($result, new RunsQueryCount($max, lessThan: true), $message);
    }

    public static function queryCount(ProbeResult $result, int $expected, string $message = ''): void
    {
        Assert::assertThat($result, new RunsQueryCount($expected), $message);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');
    }
}
