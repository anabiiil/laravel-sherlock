<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Capture\Support;

use Anabil\Tracing\Contracts\TraceRecorder;
use Anabil\Tracing\Data\TraceData;
use RuntimeException;

final class FakeTraceRecorder implements TraceRecorder
{
    /** @var list<TraceData> */
    public array $traces = [];

    public bool $throw = false;

    public function record(TraceData $trace): void
    {
        if ($this->throw) {
            throw new RuntimeException('recorder exploded');
        }

        $this->traces[] = $trace;
    }

    public function last(): TraceData
    {
        if ($this->traces === []) {
            throw new RuntimeException('No trace was recorded.');
        }

        return $this->traces[array_key_last($this->traces)];
    }

    public function json(): string
    {
        return (string) json_encode(array_map(static fn (TraceData $t): array => $t->toArray(), $this->traces));
    }
}
