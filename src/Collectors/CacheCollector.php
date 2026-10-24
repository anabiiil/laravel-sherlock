<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Context\ContextManager;
use Anabil\Tracing\Context\FailureReporter;
use Anabil\Tracing\Contracts\Collector;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Hit / miss / write counters plus time spent in cache calls. Cache keys and values are
 * never stored. Timing uses the Retrieving / Writing events when the framework has them.
 */
final class CacheCollector implements Collector
{
    private float $operationStartedAt = 0.0;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly ContextManager $manager,
        private readonly FailureReporter $failures,
    ) {}

    public function register(): void
    {
        $this->events->listen(CacheHit::class, $this->hit(...));
        $this->events->listen(CacheMissed::class, $this->miss(...));
        $this->events->listen(KeyWritten::class, $this->written(...));

        $starts = [];
        foreach ([RetrievingKey::class, RetrievingManyKeys::class, WritingKey::class, WritingManyKeys::class] as $event) {
            if (class_exists($event)) {
                $starts[] = $event;
            }
        }

        if ($starts !== []) {
            $this->events->listen($starts, $this->starting(...));
        }
    }

    public function starting(): void
    {
        if ($this->manager->isRecording()) {
            $this->operationStartedAt = microtime(true);
        }
    }

    public function hit(): void
    {
        try {
            $context = $this->manager->current();
            if ($context->isActive()) {
                $context->cacheHit();
                $this->addTime();
            }
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    public function miss(): void
    {
        try {
            $context = $this->manager->current();
            if ($context->isActive()) {
                $context->cacheMiss();
                $this->addTime();
            }
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    public function written(): void
    {
        try {
            $context = $this->manager->current();
            if ($context->isActive()) {
                $context->cacheWrite();
                $this->addTime();
            }
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    private function addTime(): void
    {
        if ($this->operationStartedAt > 0.0) {
            $this->manager->current()->addCacheTime((microtime(true) - $this->operationStartedAt) * 1000);
            $this->operationStartedAt = 0.0;
        }
    }
}
