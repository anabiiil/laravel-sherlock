<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\ContextManager;
use Anabil\Sherlock\Contracts\TraceContext;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Closure;

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

    public function pause(Closure $callback): mixed
    {
        return $callback();
    }
}
