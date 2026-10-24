<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Explain;

use Anabil\Tracing\Contracts\Explainer;
use Anabil\Tracing\Contracts\PlanInterpreter;
use Anabil\Tracing\Data\ExplainInsight;
use Anabil\Tracing\Data\ExplainPlan;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\ExplainInsightType;
use Anabil\Tracing\Enums\Severity;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Decides which queries of a trace get EXPLAINed and runs each at most once per
 * query fingerprint per TTL.
 *
 * Eligible: normalized, >= minQueryMs (the slow-query threshold), passes ExplainGuard
 * (single read-only SELECT, bindings for every placeholder). At most maxPerTrace
 * fingerprints per trace, slowest first.
 *
 * The interpreted plan (or a "none" marker when the query could not be explained) is
 * cached under the fingerprint, so a hot slow query is explained once and later traces
 * reuse the cached plan with no database round trip. An atomic add() lock prevents
 * concurrent workers from explaining the same fingerprint simultaneously.
 * Without a cache store nothing is ever explained (no way to bound the frequency).
 */
final class ExplainService
{
    public const CACHE_PREFIX = 'tracing:explain:';

    private const NONE = 'none';

    private const LOCK_SECONDS = 60;

    /**
     * @param  iterable<Explainer>  $explainers
     */
    public function __construct(
        private readonly iterable $explainers,
        private readonly PlanInterpreter $interpreter,
        private readonly ?CacheRepository $cache,
        private readonly bool $enabled = true,
        private readonly float $minQueryMs = 100.0,
        private readonly int $cacheSeconds = 86400,
        private readonly int $maxPerTrace = 3,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled && $this->cache !== null && $this->maxPerTrace > 0;
    }

    /**
     * @return array<string, ExplainPlan> query fingerprint => plan
     */
    public function explainTrace(TraceData $trace): array
    {
        if (! $this->enabled() || $trace->queries === []) {
            return [];
        }

        /** @var array<string, QueryRecord> $candidates */
        $candidates = [];
        foreach ($trace->queries as $query) {
            $fingerprint = $query->fingerprint;
            if ($fingerprint === null || $query->timeMs < $this->minQueryMs) {
                continue;
            }
            if (isset($candidates[$fingerprint]) && $candidates[$fingerprint]->timeMs >= $query->timeMs) {
                continue;
            }
            if (ExplainGuard::isEligible($query)) {
                $candidates[$fingerprint] = $query;
            }
        }

        if ($candidates === []) {
            return [];
        }

        uasort($candidates, static fn (QueryRecord $a, QueryRecord $b): int => $b->timeMs <=> $a->timeMs);

        $plans = [];
        foreach (array_slice($candidates, 0, $this->maxPerTrace, true) as $fingerprint => $query) {
            $plan = $this->explainOnce($query);
            if ($plan !== null) {
                $plans[(string) $fingerprint] = $plan;
            }
        }

        return $plans;
    }

    /**
     * Cached plan for the query fingerprint, or EXPLAIN it now (once per TTL).
     */
    public function explainOnce(QueryRecord $query): ?ExplainPlan
    {
        if (! $this->enabled() || $query->fingerprint === null || ! ExplainGuard::isEligible($query)) {
            return null;
        }

        $key = self::CACHE_PREFIX.$query->fingerprint;
        $cached = $this->cacheGet($key);
        if (is_array($cached)) {
            return self::hydrate($cached);
        }
        if ($cached !== null) {
            return null; // explained before, no plan
        }

        $explainer = $this->explainerFor($query->driver);
        if ($explainer === null || ! $this->acquire($key)) {
            return null;
        }

        $plan = null;
        try {
            $plan = $explainer->explain($query);
            if ($plan !== null) {
                $insights = $this->interpreter instanceof SqlAwarePlanInterpreter
                    ? $this->interpreter->interpretFor($plan->driver, $plan->raw, $query->normalizedSql ?? $plan->sql)
                    : $this->interpreter->interpret($plan->driver, $plan->raw);

                $plan = new ExplainPlan($plan->connection, $plan->driver, $plan->sql, $plan->raw, $insights);
            }
        } catch (Throwable) {
            $plan = null;
        }

        $this->cachePut($key, $plan?->toArray() ?? self::NONE);

        return $plan;
    }

    private function explainerFor(string $driver): ?Explainer
    {
        foreach ($this->explainers as $explainer) {
            if ($explainer->supports($driver)) {
                return $explainer;
            }
        }

        return null;
    }

    private function acquire(string $key): bool
    {
        try {
            return $this->cache?->add($key.':lock', 1, self::LOCK_SECONDS) ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache?->get($key);
        } catch (Throwable) {
            return self::NONE; // cache down: do not explain (cannot bound frequency)
        }
    }

    private function cachePut(string $key, mixed $value): void
    {
        try {
            $this->cache?->put($key, $value, max($this->cacheSeconds, 1));
        } catch (Throwable) {
            // best effort
        }
    }

    /**
     * @param  array<array-key, mixed>  $data  output of ExplainPlan::toArray()
     */
    public static function hydrate(array $data): ?ExplainPlan
    {
        if (! is_string($data['connection'] ?? null) || ! is_string($data['driver'] ?? null) || ! is_string($data['sql'] ?? null)) {
            return null;
        }

        $insights = [];
        foreach (is_array($data['insights'] ?? null) ? $data['insights'] : [] as $insight) {
            if (! is_array($insight) || ! is_string($insight['message'] ?? null)) {
                continue;
            }

            $insights[] = new ExplainInsight(
                ExplainInsightType::tryFrom((string) ($insight['type'] ?? '')) ?? ExplainInsightType::Other,
                Severity::fromConfig($insight['severity'] ?? null, Severity::Info),
                $insight['message'],
                is_string($insight['table'] ?? null) ? $insight['table'] : null,
                is_string($insight['suggested_index'] ?? null) ? $insight['suggested_index'] : null,
                is_int($insight['estimated_rows'] ?? null) ? $insight['estimated_rows'] : null,
            );
        }

        return new ExplainPlan(
            $data['connection'],
            $data['driver'],
            $data['sql'],
            is_array($data['raw'] ?? null) ? $data['raw'] : [],
            $insights,
        );
    }
}
