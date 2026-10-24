<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis;

use Anabil\Tracing\Analysis\Support\Describe;
use Anabil\Tracing\Analysis\Support\Sql;
use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;
use Anabil\Tracing\Enums\Bottleneck;

/**
 * Splits the duration into db / external_http / cache / bootstrap / application
 * (the remainder, never negative) and applies the share rules of
 * tracing.bottleneck, in order: database, external service, cache, bootstrap,
 * application; otherwise mixed. Traces shorter than min_duration_ms are unknown.
 *
 * When measured segments overlap and exceed the duration (e.g. concurrent HTTP
 * pools), shares are computed against their sum so they never exceed 100%.
 */
final class DefaultBottleneckClassifier implements BottleneckClassifier
{
    public const DEFAULTS = [
        'min_duration_ms' => 50.0,
        'database' => 0.5,
        'external_service' => 0.4,
        'cache' => 0.3,
        'bootstrap' => 0.4,
        'application' => 0.6,
    ];

    /** @var array<string, float> */
    private readonly array $rules;

    /**
     * @param  array<array-key, mixed>  $rules  tracing.bottleneck
     */
    public function __construct(array $rules = self::DEFAULTS)
    {
        $resolved = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $rules[$key] ?? null;
            $resolved[$key] = is_numeric($value) ? (float) $value : $default;
        }
        $this->rules = $resolved;
    }

    public function classify(TraceData $trace): Verdict
    {
        $duration = max($trace->durationMs, 0.0);
        if ($duration < max($this->rules['min_duration_ms'], 0.001)) {
            return Verdict::unknown(sprintf('Too short to classify (%s).', Describe::duration($duration)));
        }

        $db = max($trace->dbMs, 0.0);
        $http = max($trace->httpMs, 0.0);
        $cache = max($trace->cacheMs, 0.0);
        $bootstrap = max($trace->bootstrapMs, 0.0);
        $measured = $db + $http + $cache + $bootstrap;
        $application = max($duration - $measured, 0.0);
        $base = max($duration, $measured);

        $breakdown = [
            Verdict::SEGMENT_DB => round($db, 2),
            Verdict::SEGMENT_EXTERNAL_HTTP => round($http, 2),
            Verdict::SEGMENT_CACHE => round($cache, 2),
            Verdict::SEGMENT_BOOTSTRAP => round($bootstrap, 2),
            Verdict::SEGMENT_APPLICATION => round($application, 2),
        ];

        $shares = [
            Verdict::SEGMENT_DB => $db / $base,
            Verdict::SEGMENT_EXTERNAL_HTTP => $http / $base,
            Verdict::SEGMENT_CACHE => $cache / $base,
            Verdict::SEGMENT_BOOTSTRAP => $bootstrap / $base,
            Verdict::SEGMENT_APPLICATION => $application / $base,
        ];

        $percentages = array_map(static fn (float $share): float => round($share * 100, 1), $shares);

        [$bottleneck, $reason] = match (true) {
            $shares[Verdict::SEGMENT_DB] >= $this->rules['database'] => [Bottleneck::Database, $this->databaseReason($trace, $shares, $duration)],
            $shares[Verdict::SEGMENT_EXTERNAL_HTTP] >= $this->rules['external_service'] => [Bottleneck::ExternalService, $this->externalReason($trace, $shares, $duration)],
            $shares[Verdict::SEGMENT_CACHE] >= $this->rules['cache'] => [Bottleneck::Cache, sprintf(
                '%s of %s spent in cache operations (%d lookups, %s misses).',
                Describe::percent($shares[Verdict::SEGMENT_CACHE]),
                Describe::duration($duration),
                $trace->cacheLookups(),
                Describe::percent($trace->cacheMissRatio()),
            )],
            $shares[Verdict::SEGMENT_BOOTSTRAP] >= $this->rules['bootstrap'] => [Bottleneck::Bootstrap, sprintf(
                '%s of %s spent booting the framework before the handler ran (%s); check config/route/event caching and heavy service providers.',
                Describe::percent($shares[Verdict::SEGMENT_BOOTSTRAP]),
                Describe::duration($duration),
                Describe::duration($bootstrap),
            )],
            $shares[Verdict::SEGMENT_APPLICATION] >= $this->rules['application'] => [Bottleneck::Application, sprintf(
                '%s of %s spent in application code (database %s in %d queries, external HTTP %s).',
                Describe::percent($shares[Verdict::SEGMENT_APPLICATION]),
                Describe::duration($duration),
                Describe::percent($shares[Verdict::SEGMENT_DB]),
                $trace->queryCount(),
                Describe::percent($shares[Verdict::SEGMENT_EXTERNAL_HTTP]),
            )],
            default => [Bottleneck::Mixed, $this->mixedReason($shares, $duration)],
        };

        return new Verdict($bottleneck, $reason, round($duration, 2), $breakdown, $percentages);
    }

    /**
     * "72% of 2.40s spent in 38 queries (top query 900ms on `orders`)".
     *
     * @param  array<string, float>  $shares
     */
    private function databaseReason(TraceData $trace, array $shares, float $duration): string
    {
        $count = $trace->queryCount();
        $top = null;
        foreach ($trace->queries as $query) {
            if ($top === null || $query->timeMs > $top->timeMs) {
                $top = $query;
            }
        }

        return sprintf(
            '%s of %s spent in %d %s%s.',
            Describe::percent($shares[Verdict::SEGMENT_DB]),
            Describe::duration($duration),
            $count,
            $count === 1 ? 'query' : 'queries',
            $top instanceof QueryRecord
                ? ' (top query '.Describe::duration($top->timeMs).' on '.Describe::table(Sql::primaryTable($top->normalizedSql ?? $top->sql)).')'
                : '',
        );
    }

    /**
     * @param  array<string, float>  $shares
     */
    private function externalReason(TraceData $trace, array $shares, float $duration): string
    {
        $count = $trace->httpCount();
        $top = null;
        foreach ($trace->httpCalls as $call) {
            if ($top === null || $call->durationMs > $top->durationMs) {
                $top = $call;
            }
        }

        return sprintf(
            '%s of %s spent in %d external HTTP %s%s.',
            Describe::percent($shares[Verdict::SEGMENT_EXTERNAL_HTTP]),
            Describe::duration($duration),
            $count,
            $count === 1 ? 'call' : 'calls',
            $top instanceof HttpCallRecord
                ? ' (slowest '.Describe::duration($top->durationMs).' to '.($top->host() !== '' ? $top->host() : 'an unknown host').')'
                : '',
        );
    }

    /**
     * @param  array<string, float>  $shares
     */
    private function mixedReason(array $shares, float $duration): string
    {
        $labels = [
            Verdict::SEGMENT_DB => 'database',
            Verdict::SEGMENT_EXTERNAL_HTTP => 'external HTTP',
            Verdict::SEGMENT_CACHE => 'cache',
            Verdict::SEGMENT_BOOTSTRAP => 'bootstrap',
            Verdict::SEGMENT_APPLICATION => 'application',
        ];

        arsort($shares);
        $parts = [];
        foreach ($shares as $segment => $share) {
            if ($share >= 0.05) {
                $parts[] = $labels[$segment].' '.Describe::percent($share);
            }
        }

        return sprintf('No single dominant segment in %s: %s.', Describe::duration($duration), implode(', ', $parts));
    }
}
