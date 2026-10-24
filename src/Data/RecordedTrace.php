<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

use Anabil\Tracing\Enums\RecordReason;

/**
 * A trace that was decided to be persisted, with its analysis. This is what
 * PersistenceStrategy, TraceRepository and IssueService receive.
 */
final readonly class RecordedTrace
{
    /**
     * @param  bool  $storeBindings  whether redacted bindings may be stored (privacy.store_bindings resolved)
     */
    public function __construct(
        public TraceData $trace,
        public AnalysisResult $analysis,
        public RecordReason $reason,
        public bool $storeBindings = false,
    ) {}
}
