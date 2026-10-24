<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis;

use Anabil\Tracing\Analysis\Explain\ExplainService;
use Anabil\Tracing\Contracts\Analyzer;
use Anabil\Tracing\Contracts\BottleneckClassifier;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Data\AnalysisResult;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Data\Verdict;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whole analysis stage (Pipeline + Strategy), run at terminate:
 *
 *  1. enrich: normalize + fingerprint every query, memoized per distinct raw SQL
 *  2. EXPLAIN eligible slow SELECTs (cached once per fingerprint, see ExplainService), then
 *     drop the transient EXPLAIN-only bindings so nothing downstream ever sees them
 *  3. run every configured Analyzer; one throwing analyzer never stops the others
 *  4. classify the bottleneck
 *
 * Never throws. Failures are logged once per component per process (warning level,
 * below the captured log level, so they never feed back into tracing).
 */
final class TraceAnalysisPipeline implements TraceAnalyzer
{
    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @param  list<Analyzer>  $analyzers
     */
    public function __construct(
        private readonly QueryNormalizer $normalizer,
        private readonly Fingerprinter $fingerprinter,
        private readonly array $analyzers,
        private readonly BottleneckClassifier $classifier,
        private readonly ?ExplainService $explainer = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function analyze(TraceData $trace): AnalysisResult
    {
        try {
            $trace = $this->enrich($trace);
        } catch (Throwable $e) {
            $this->report('enrich', $e);
        }

        $plans = [];
        if ($this->explainer !== null) {
            try {
                $plans = $this->explainer->explainTrace($trace);
            } catch (Throwable $e) {
                $this->report(ExplainService::class, $e);
            }
        }

        $trace = self::withoutExplainBindings($trace);

        /** @var list<Finding> $findings */
        $findings = [];
        foreach ($this->analyzers as $analyzer) {
            try {
                $found = $analyzer instanceof PlanAwareAnalyzer
                    ? $analyzer->analyzeWithPlans($trace, $plans)
                    : $analyzer->analyze($trace);

                foreach ($found as $finding) {
                    $findings[] = $finding;
                }
            } catch (Throwable $e) {
                $this->report($analyzer::class, $e);
            }
        }

        try {
            $verdict = $this->classifier->classify($trace);
        } catch (Throwable $e) {
            $this->report($this->classifier::class, $e);
            $verdict = Verdict::unknown();
        }

        return new AnalysisResult($findings, $verdict, $plans, $trace);
    }

    /**
     * Normalize + fingerprint each distinct raw SQL once (N+1 loops repeat the same string).
     */
    public function enrich(TraceData $trace): TraceData
    {
        if ($trace->queries === []) {
            return $trace;
        }

        /** @var array<string, array{0: string, 1: string}> $memo */
        $memo = [];
        $queries = [];
        $changed = false;

        foreach ($trace->queries as $query) {
            if ($query->isNormalized()) {
                $queries[] = $query;

                continue;
            }

            $entry = $memo[$query->sql] ??= $this->normalizeOnce($query->sql);
            $queries[] = $query->withNormalization($entry[0], $entry[1]);
            $changed = true;
        }

        return $changed ? $trace->withQueries($queries) : $trace;
    }

    /**
     * Drops QueryRecord::$explainBindings (lightweight capture keeps them for EXPLAIN only).
     */
    public static function withoutExplainBindings(TraceData $trace): TraceData
    {
        foreach ($trace->queries as $query) {
            if ($query->explainBindings !== null) {
                return $trace->withQueries(array_map(
                    static fn (QueryRecord $query): QueryRecord => $query->explainBindings === null ? $query : $query->withoutExplainBindings(),
                    $trace->queries,
                ));
            }
        }

        return $trace;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function normalizeOnce(string $sql): array
    {
        $normalized = $this->normalizer->normalize($sql);

        return [$normalized, $this->fingerprinter->query($normalized)];
    }

    private function report(string $component, Throwable $e): void
    {
        if (isset($this->reported[$component])) {
            return;
        }
        $this->reported[$component] = true;

        $this->logger?->warning('[tracing] analysis step failed: '.$component.': '.$e->getMessage(), [
            'exception' => $e::class,
        ]);
    }
}
