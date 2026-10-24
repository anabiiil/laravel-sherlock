<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Pipeline;

use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Contracts\ServerProbe;
use Anabil\Sherlock\Contracts\TraceAnalyzer;
use Anabil\Sherlock\Contracts\TraceRecorder;
use Anabil\Sherlock\Data\AnalysisResult;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\RecordedTrace;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Enums\Severity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Terminate-time orchestration: analyze -> decide -> persist. Never throws.
 *
 * A trace is persisted when a forcing reason exists (local / watch / header / sample)
 * or, otherwise, when the analysis crosses $persistMinSeverity or contains an error.
 *
 * Server signals that cost more than a syscall are resolved here, only when needed: the CPU
 * core count (memoized per process) before analysis when the load average could reach the
 * per-core threshold, and for every persisted trace; available memory for persisted traces only.
 */
final class DefaultTraceRecorder implements TraceRecorder
{
    /**
     * @param  string  $storeBindings  auto | always | never (privacy.store_bindings)
     */
    public function __construct(
        private readonly TraceAnalyzer $analyzer,
        private readonly PersistenceStrategy $persistence,
        private readonly Severity $persistMinSeverity = Severity::Warning,
        private readonly string $storeBindings = 'auto',
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ServerProbe $probe = null,
        private readonly float $loadPerCoreWarning = 1.5,
    ) {}

    public function record(TraceData $trace): void
    {
        try {
            $trace = $this->withCores($trace, false);
            $analysis = $this->analyzer->analyze($trace);
            $trace = $analysis->trace ?? $trace;

            $reason = $this->reasonFor($trace, $analysis);
            if ($reason === null) {
                return;
            }

            $trace = $this->withPersistedSignals($trace);

            $storeBindings = $this->shouldStoreBindings($trace);
            $trace = self::stripBindings($trace, $storeBindings);

            $this->persistence->persist(new RecordedTrace(
                $trace->withRecordReason($reason),
                $analysis->trace !== null ? new AnalysisResult($analysis->findings, $analysis->verdict, $analysis->explainPlans, $trace) : $analysis,
                $reason,
                $storeBindings,
            ));
        } catch (Throwable $e) {
            // Warning level: below the captured log level, so it never feeds back into tracing.
            $this->logger?->warning('[sherlock] failed to record trace: '.$e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }

    public function reasonFor(TraceData $trace, AnalysisResult $analysis): ?RecordReason
    {
        $forced = array_values(array_filter(
            $trace->recordReasons,
            static fn (RecordReason $reason): bool => $reason->forcesPersistence(),
        ));

        // A crossed threshold is more informative than "sample" in the stored record reason.
        if ($analysis->crosses($this->persistMinSeverity) && RecordReason::primary($forced) === RecordReason::Sample) {
            return RecordReason::Threshold;
        }

        if ($forced !== []) {
            return RecordReason::primary($forced);
        }

        return $analysis->crosses($this->persistMinSeverity) ? RecordReason::Threshold : null;
    }

    /**
     * What reaches persistence (and so a queued PersistTraceJob payload) never carries more
     * than may be stored: the transient EXPLAIN-only bindings are always dropped, the
     * captured bindings too when they must not be stored.
     */
    public static function stripBindings(TraceData $trace, bool $storeBindings): TraceData
    {
        $strip = static fn (QueryRecord $query): bool => $query->explainBindings !== null || (! $storeBindings && $query->bindings !== null);

        foreach ($trace->queries as $query) {
            if ($strip($query)) {
                return $trace->withQueries(array_map(
                    static fn (QueryRecord $query): QueryRecord => match (true) {
                        ! $strip($query) => $query,
                        $storeBindings => $query->withoutExplainBindings(),
                        default => $query->withoutBindings(),
                    },
                    $trace->queries,
                ));
            }
        }

        return $trace;
    }

    /**
     * Cores are only needed to judge the load per core: before analysis only when the load could
     * reach the warning threshold (load >= threshold x 1 core), always for a persisted trace.
     */
    private function withCores(TraceData $trace, bool $persisting): TraceData
    {
        if ($this->probe === null || $trace->loadAvg === null || $trace->cpuCores !== null) {
            return $trace;
        }

        if (! $persisting && $trace->loadAvg < $this->loadPerCoreWarning) {
            return $trace;
        }

        $cores = $this->probe->cpuCores();

        return $cores === null ? $trace : $trace->withServerSignals($cores);
    }

    private function withPersistedSignals(TraceData $trace): TraceData
    {
        if ($this->probe === null) {
            return $trace;
        }

        $trace = $this->withCores($trace, true);
        $memory = $this->probe->memoryAvailableMb();

        return $memory === null ? $trace : $trace->withServerSignals(null, $memory);
    }

    private function shouldStoreBindings(TraceData $trace): bool
    {
        return match ($this->storeBindings) {
            'always' => true,
            'never' => false,
            default => $trace->full,
        };
    }
}
