<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\ContextManager;
use Anabil\Tracing\Contracts\TraceContext;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;

final class NullContextManager implements ContextManager
{
    private readonly NullTraceContext $context;

    public function __construct()
    {
        $this->context = new NullTraceContext;
    }

    public function start(EntryType $type, string $name, RecordingDecision $decision, ?float $startedAt = null): TraceContext
    {
        return $this->context;
    }

    public function current(): TraceContext
    {
        return $this->context;
    }

    public function isRecording(): bool
    {
        return false;
    }

    public function stop(): ?TraceData
    {
        return null;
    }

    public function reset(): void {}
}
