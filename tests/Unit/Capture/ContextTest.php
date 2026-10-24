<?php

declare(strict_types=1);

use Anabil\Tracing\Context\ContextManager;
use Anabil\Tracing\Context\TraceContext;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\LogRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;
use Anabil\Tracing\Support\Null\NullTraceContext;

function captureQuery(float $ms = 1.0, string $sql = 'select 1'): QueryRecord
{
    return new QueryRecord($sql, null, $ms, 'mysql', 'mysql');
}

function captureContext(int $maxQueries = 1000, int $maxHttp = 200, int $maxLogs = 100): TraceContext
{
    return new TraceContext('01J0000000000000000000000', EntryType::Job, 'App\Jobs\Sync', microtime(true), false, [], $maxQueries, $maxHttp, $maxLogs);
}

describe('TraceContext', function (): void {
    it('freezes into an immutable TraceData', function (): void {
        $context = new TraceContext('01J0000000000000000000000', EntryType::Request, 'GET /', microtime(true) - 0.25, true, [RecordReason::Local]);
        $context->addQuery(captureQuery(3.0));
        $context->addHttpCall(new HttpCallRecord('GET', 'https://a.test', 200, 10.0));
        $context->addLog(new LogRecord('error', 'x'));
        $context->cacheHit();
        $context->cacheMiss();
        $context->cacheWrite();
        $context->addCacheTime(1.5);
        $context->setName('GET /orders/{id}');
        $context->setRoute('orders.show');
        $context->setRequest('GET', '/orders/1');
        $context->setStatusCode(201);
        $context->setUserId(7);
        $context->setBootstrapMs(12.3456);
        $context->tag('tenant', 'acme');
        $context->meta('queue', 'default');

        $trace = $context->toTraceData();

        expect($trace->id)->toBe('01J0000000000000000000000')
            ->and($trace->type)->toBe(EntryType::Request)
            ->and($trace->name)->toBe('GET /orders/{id}')
            ->and($trace->route)->toBe('orders.show')
            ->and($trace->method)->toBe('GET')
            ->and($trace->uri)->toBe('/orders/1')
            ->and($trace->statusCode)->toBe(201)
            ->and($trace->userId)->toBe(7)
            ->and($trace->bootstrapMs)->toBe(12.346)
            ->and($trace->durationMs)->toBeGreaterThanOrEqual(250.0)
            ->and($trace->queryCount())->toBe(1)
            ->and($trace->dbMs)->toBe(3.0)
            ->and($trace->httpMs)->toBe(10.0)
            ->and($trace->cacheHits)->toBe(1)
            ->and($trace->cacheMisses)->toBe(1)
            ->and($trace->cacheWrites)->toBe(1)
            ->and($trace->cacheMs)->toBe(1.5)
            ->and($trace->full)->toBeTrue()
            ->and($trace->recordReasons)->toBe([RecordReason::Local])
            ->and($trace->tags)->toBe(['tenant' => 'acme'])
            ->and($trace->metadata)->toBe(['queue' => 'default'])
            ->and($trace->totalQueryCount)->toBeNull()
            ->and($trace->startedAt->getTimestamp())->toBeLessThanOrEqual(time());
    });

    it('caps stored records but keeps exact totals', function (): void {
        $context = captureContext(maxQueries: 3, maxHttp: 1, maxLogs: 2);
        for ($i = 0; $i < 5; $i++) {
            $context->addQuery(captureQuery(2.0));
            $context->addHttpCall(new HttpCallRecord('GET', 'https://a.test', 200, 1.0));
            $context->addLog(new LogRecord('error', 'x'));
        }

        $trace = $context->toTraceData();

        expect($trace->queries)->toHaveCount(3)
            ->and($trace->queryCount())->toBe(5)
            ->and($trace->dbMs)->toBe(10.0)
            ->and($trace->httpCalls)->toHaveCount(1)
            ->and($trace->logs)->toHaveCount(2)
            ->and($trace->metadata)->toBe(['dropped_queries' => 2, 'dropped_http_calls' => 4, 'dropped_logs' => 3]);
    });

    it('reports the first occurrence of a key only once, with a bounded memory', function (): void {
        $context = captureContext();

        expect($context->firstOccurrence('select 1'))->toBeTrue()
            ->and($context->firstOccurrence('select 1'))->toBeFalse()
            ->and($context->firstOccurrence('select 2'))->toBeTrue();

        for ($i = 0; $i < TraceContext::MAX_SEEN_KEYS; $i++) {
            $context->firstOccurrence('q'.$i);
        }

        expect($context->firstOccurrence('one more distinct query'))->toBeFalse();
    });

    it('reports the second occurrence of a key exactly once, with a bounded memory', function (): void {
        $context = captureContext();

        expect($context->secondOccurrence('select 1'))->toBeFalse()
            ->and($context->secondOccurrence('select 1'))->toBeTrue()
            ->and($context->secondOccurrence('select 1'))->toBeFalse()
            ->and($context->secondOccurrence('select 1'))->toBeFalse()
            ->and($context->secondOccurrence('select 2'))->toBeFalse();

        for ($i = 0; $i < TraceContext::MAX_SEEN_KEYS; $i++) {
            $context->secondOccurrence('q'.$i);
        }

        // Already-tracked keys still count; new keys are no longer tracked.
        expect($context->secondOccurrence('select 2'))->toBeTrue()
            ->and($context->secondOccurrence('new key'))->toBeFalse()
            ->and($context->secondOccurrence('new key'))->toBeFalse();
    });

    it('ignores timing signals once frozen but still accepts logs', function (): void {
        $context = captureContext();
        $context->addQuery(captureQuery());
        $context->freeze();
        $duration = $context->toTraceData()->durationMs;

        usleep(2000);
        $context->addQuery(captureQuery());
        $context->cacheHit();
        $context->addHttpCall(new HttpCallRecord('GET', 'https://a.test', 200, 1.0));
        $context->addLog(new LogRecord('error', 'late'));

        $trace = $context->toTraceData();

        expect($context->isFrozen())->toBeTrue()
            ->and($trace->durationMs)->toBe($duration)
            ->and($trace->queryCount())->toBe(1)
            ->and($trace->cacheHits)->toBe(0)
            ->and($trace->httpCalls)->toBe([])
            ->and($trace->logs)->toHaveCount(1);
    });

    it('adds and removes record reasons', function (): void {
        $context = captureContext();
        $context->addRecordReason(RecordReason::Watch);
        $context->addRecordReason(RecordReason::Watch);
        $context->addRecordReason(RecordReason::Sample);
        $context->removeRecordReason(RecordReason::Watch);

        expect($context->toTraceData()->recordReasons)->toBe([RecordReason::Sample])
            ->and($context->hasRecordReason(RecordReason::Watch))->toBeFalse();
    });
});

describe('ContextManager', function (): void {
    it('returns the null context when the decision does not capture', function (): void {
        $manager = new ContextManager;

        expect($manager->start(EntryType::Request, 'GET /', RecordingDecision::skip()))->toBeInstanceOf(NullTraceContext::class)
            ->and($manager->isRecording())->toBeFalse()
            ->and($manager->depth())->toBe(0)
            ->and($manager->stop())->toBeNull();
    });

    it('starts, records and stops a unit', function (): void {
        $manager = new ContextManager;
        $context = $manager->start(EntryType::Job, 'Job', RecordingDecision::full(RecordReason::Watch), microtime(true) - 1);
        $context->addQuery(captureQuery());

        expect($manager->isRecording())->toBeTrue()
            ->and($manager->current())->toBe($context)
            ->and($context->isFull())->toBeTrue();

        $trace = $manager->stop();

        expect($trace?->queryCount())->toBe(1)
            ->and($trace?->recordReasons)->toBe([RecordReason::Watch])
            ->and($trace?->durationMs)->toBeGreaterThanOrEqual(1000.0)
            ->and($manager->isRecording())->toBeFalse()
            ->and($manager->current())->toBeInstanceOf(NullTraceContext::class);
    });

    it('attributes nested units to the outer one', function (): void {
        $manager = new ContextManager;
        $outer = $manager->start(EntryType::Request, 'GET /', RecordingDecision::light());
        $inner = $manager->start(EntryType::Job, 'SyncJob', RecordingDecision::full(RecordReason::Local));
        $inner->addQuery(captureQuery());

        expect($inner)->toBe($outer)
            ->and($manager->depth())->toBe(2)
            ->and($manager->stop())->toBeNull()
            ->and($manager->isRecording())->toBeTrue();

        $trace = $manager->stop();
        expect($trace?->name)->toBe('GET /')
            ->and($trace?->type)->toBe(EntryType::Request)
            ->and($trace?->queryCount())->toBe(1)
            ->and($trace?->full)->toBeFalse();
    });

    it('suspends capture while paused, even nested and on exceptions', function (): void {
        $manager = new ContextManager;
        $manager->start(EntryType::Command, 'demo', RecordingDecision::light());

        $value = $manager->pause(function () use ($manager): string {
            $manager->current()->addQuery(captureQuery());
            $manager->pause(fn () => null);

            return $manager->isRecording() ? 'recording' : 'paused';
        });

        try {
            $manager->pause(fn () => throw new RuntimeException('x'));
        } catch (RuntimeException) {
        }

        expect($value)->toBe('paused')
            ->and($manager->isRecording())->toBeTrue()
            ->and($manager->stop()?->queryCount())->toBe(0);
    });

    it('discards and resets units', function (): void {
        $manager = new ContextManager;
        $manager->start(EntryType::Request, 'GET /', RecordingDecision::light());
        $manager->orphan(EntryType::Command, 'artisan')->addLog(new LogRecord('error', 'x'));
        $manager->discard();

        expect($manager->isRecording())->toBeFalse()
            ->and($manager->hasOrphan())->toBeTrue()
            ->and($manager->stopOrphan()?->logs)->toHaveCount(1)
            ->and($manager->hasOrphan())->toBeFalse();

        $manager->start(EntryType::Request, 'GET /', RecordingDecision::light());
        $manager->reset();

        expect($manager->depth())->toBe(0)->and($manager->stop())->toBeNull();
    });

    it('applies the configured caps to new contexts', function (): void {
        $manager = new ContextManager(maxQueries: 2);
        $context = $manager->start(EntryType::Job, 'Job', RecordingDecision::light());
        foreach (range(1, 4) as $i) {
            $context->addQuery(captureQuery());
        }

        $trace = $manager->stop();
        expect($trace?->queries)->toHaveCount(2)->and($trace?->queryCount())->toBe(4);
    });
});
