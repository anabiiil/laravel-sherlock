<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Analysis;

use Anabil\Sherlock\Analysis\Support\Describe;
use Anabil\Sherlock\Analysis\Support\Sql;
use Anabil\Sherlock\Contracts\BottleneckClassifier;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Data\Verdict;
use Anabil\Sherlock\Enums\Bottleneck;

/**
 * Splits the duration into db / external_http / cache / bootstrap / application
 * (the remainder, never negative) and applies the share rules of
 * sherlock.bottleneck, in order:
 *
 *  1. queue backlog: a job that waited in the queue at least queue_wait_ms and longer than it ran
 *     (checked before the min_duration_ms rule: a 10ms job that waited 45s is a backlog)
 *  2. database, external service (share rules)
 *  3. server load: load average per core >= server_load while the time is not explained by this
 *     process's own CPU time (CPU time < cpu_bound x application time, or CPU time unknown)
 *  4. cache, bootstrap (share rules)
 *  5. application share >= application, refined with the CPU time when known:
 *     CPU / application >= cpu_bound -> application (CPU-bound code),
 *     CPU / application <= waiting   -> waiting (blocked: locks, sleep, I/O),
 *  6. otherwise mixed. Traces shorter than min_duration_ms are unknown.
 *
 * When measured segments overlap and exceed the duration (e.g. concurrent HTTP
 * pools), shares are computed against their sum so they never exceed 100%.
 * Without server signals (CPU time / load unknown) the rules are the original share rules.
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
        'server_load' => 1.5,
        'cpu_bound' => 0.7,
        'waiting' => 0.3,
        'queue_wait_ms' => 30000.0,
    ];

    /** @var array<string, float> */
    private readonly array $rules;

    /**
     * @param  array<array-key, mixed>  $rules  sherlock.bottleneck (+ server_load / cpu_bound / waiting from
     *                                          sherlock.server, queue_wait_ms from thresholds.queue_wait.warning_ms)
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
        $signals = self::signals($trace);

        if ($trace->queueWaitMs !== null && $trace->queueWaitMs >= $this->rules['queue_wait_ms'] && $trace->queueWaitMs >= $duration) {
            [$breakdown, , $percentages] = $this->segments($trace, $duration);

            return new Verdict(Bottleneck::QueueBacklog, sprintf(
                'The job waited %s in the queue%s before a worker started it, longer than it ran (%s): workers are not keeping up.',
                Describe::duration($trace->queueWaitMs),
                self::queueName($trace),
                Describe::duration($duration),
            ), round($duration, 2), $breakdown, $percentages, $signals);
        }

        if ($duration < max($this->rules['min_duration_ms'], 0.001)) {
            return new Verdict(Bottleneck::Unknown, sprintf('Too short to classify (%s).', Describe::duration($duration)), signals: $signals);
        }

        [$breakdown, $shares, $percentages] = $this->segments($trace, $duration);
        $application = $breakdown[Verdict::SEGMENT_APPLICATION];
        $cpu = $trace->cpuMs;
        $loadPerCore = $trace->loadPerCore();
        $cpuRatio = $cpu !== null && $application > 0.0 ? $cpu / $application : null;

        [$bottleneck, $reason] = match (true) {
            $shares[Verdict::SEGMENT_DB] >= $this->rules['database'] => [Bottleneck::Database, $this->databaseReason($trace, $shares, $duration)],
            $shares[Verdict::SEGMENT_EXTERNAL_HTTP] >= $this->rules['external_service'] => [Bottleneck::ExternalService, $this->externalReason($trace, $shares, $duration)],
            $loadPerCore !== null && $loadPerCore >= $this->rules['server_load'] && ($cpuRatio === null || $cpuRatio < $this->rules['cpu_bound']) => [Bottleneck::ServerLoad, self::serverLoadReason($trace, $loadPerCore, $duration)],
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
                Describe::duration($trace->bootstrapMs),
            )],
            $shares[Verdict::SEGMENT_APPLICATION] >= $this->rules['application'] && $cpuRatio !== null && $cpuRatio <= $this->rules['waiting'] => [Bottleneck::Waiting, sprintf(
                '%s of %s spent outside the database and external HTTP, but CPU time was only %s: the process was waiting or blocked (locks, sleep(), file / network I/O, session or cache locks), not computing.',
                Describe::percent($shares[Verdict::SEGMENT_APPLICATION]),
                Describe::duration($duration),
                Describe::duration((float) $cpu),
            )],
            $shares[Verdict::SEGMENT_APPLICATION] >= $this->rules['application'] && $cpuRatio !== null && $cpuRatio >= $this->rules['cpu_bound'] => [Bottleneck::Application, sprintf(
                'CPU-bound application code: %s of CPU time in a %s application segment (%s of %s); profile loops, serialization, large collections or image / file processing.',
                Describe::duration((float) $cpu),
                Describe::duration($application),
                Describe::percent($shares[Verdict::SEGMENT_APPLICATION]),
                Describe::duration($duration),
            )],
            $shares[Verdict::SEGMENT_APPLICATION] >= $this->rules['application'] => [Bottleneck::Application, sprintf(
                '%s of %s spent in application code (database %s in %d queries, external HTTP %s%s).',
                Describe::percent($shares[Verdict::SEGMENT_APPLICATION]),
                Describe::duration($duration),
                Describe::percent($shares[Verdict::SEGMENT_DB]),
                $trace->queryCount(),
                Describe::percent($shares[Verdict::SEGMENT_EXTERNAL_HTTP]),
                $cpu !== null ? ', CPU time '.Describe::duration($cpu) : '',
            )],
            default => [Bottleneck::Mixed, $this->mixedReason($shares, $duration)],
        };

        return new Verdict($bottleneck, $reason, round($duration, 2), $breakdown, $percentages, $signals);
    }

    /**
     * @return array{0: array<string, float>, 1: array<string, float>, 2: array<string, float>} [breakdown ms, shares, percentages]
     */
    private function segments(TraceData $trace, float $duration): array
    {
        $db = max($trace->dbMs, 0.0);
        $http = max($trace->httpMs, 0.0);
        $cache = max($trace->cacheMs, 0.0);
        $bootstrap = max($trace->bootstrapMs, 0.0);
        $measured = $db + $http + $cache + $bootstrap;
        $application = max($duration - $measured, 0.0);
        $base = max($duration, $measured, 0.001);

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

        return [$breakdown, $shares, array_map(static fn (float $share): float => round($share * 100, 1), $shares)];
    }

    /**
     * Known server / queue signals, for the verdict (and the dashboard).
     *
     * @return array<string, float|int>
     */
    public static function signals(TraceData $trace): array
    {
        return array_filter([
            'cpu_ms' => $trace->cpuMs !== null ? round($trace->cpuMs, 2) : null,
            'load_avg' => $trace->loadAvg,
            'cpu_cores' => $trace->cpuCores,
            'load_per_core' => ($perCore = $trace->loadPerCore()) !== null ? round($perCore, 3) : null,
            'queue_wait_ms' => $trace->queueWaitMs !== null ? round($trace->queueWaitMs, 1) : null,
        ], static fn (float|int|null $value): bool => $value !== null);
    }

    /** "Server load 7.9 on 4 cores (198%) while this request ran; CPU time only 40ms of 2.10s." */
    public static function serverLoadReason(TraceData $trace, float $loadPerCore, float $duration): string
    {
        return sprintf(
            'Server load %s on %d %s (%s) while this %s ran%s.',
            self::number((float) $trace->loadAvg),
            (int) $trace->cpuCores,
            $trace->cpuCores === 1 ? 'core' : 'cores',
            Describe::percent($loadPerCore),
            strtolower($trace->type->label()),
            $trace->cpuMs !== null
                ? '; CPU time only '.Describe::duration($trace->cpuMs).' of '.Describe::duration($duration).': the process was mostly waiting for a CPU'
                : '',
        );
    }

    private static function queueName(TraceData $trace): string
    {
        $queue = $trace->metadata['queue'] ?? null;

        return is_string($queue) && $queue !== '' ? ' `'.$queue.'`' : '';
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
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
