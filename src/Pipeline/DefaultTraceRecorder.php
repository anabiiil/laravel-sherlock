<?php

declare(strict_types=1);

namespace Anabil\Tracing\Pipeline;

use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Contracts\TraceAnalyzer;
use Anabil\Tracing\Contracts\TraceRecorder;
use Anabil\Tracing\Data\AnalysisResult;
use Anabil\Tracing\Data\RecordedTrace;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\RecordReason;
use Anabil\Tracing\Enums\Severity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Terminate-time orchestration: analyze -> decide -> persist. Never throws.
 *
 * A trace is persisted when a forcing reason exists (local / watch / header / sample)
 * or, otherwise, when the analysis crosses $persistMinSeverity or contains an error.
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
    ) {}

    public function record(TraceData $trace): void
    {
        try {
            $analysis = $this->analyzer->analyze($trace);
            $trace = $analysis->trace ?? $trace;

            $reason = $this->reasonFor($trace, $analysis);
            if ($reason === null) {
                return;
            }

            $this->persistence->persist(new RecordedTrace(
                $trace->withRecordReason($reason),
                $analysis,
                $reason,
                $this->shouldStoreBindings($trace),
            ));
        } catch (Throwable $e) {
            // Warning level: below the captured log level, so it never feeds back into tracing.
            $this->logger?->warning('[tracing] failed to record trace: '.$e->getMessage(), [
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

    private function shouldStoreBindings(TraceData $trace): bool
    {
        return match ($this->storeBindings) {
            'always' => true,
            'never' => false,
            default => $trace->full,
        };
    }
}
