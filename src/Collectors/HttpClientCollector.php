<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Context\ContextManager;
use Anabil\Tracing\Context\FailureReporter;
use Anabil\Tracing\Contracts\Collector;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\SourceLocation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Throwable;

/**
 * Outgoing HTTP calls (Laravel HTTP client). Duration from a start map keyed by the
 * request object id. Only method, redacted URL, status and timing are kept: never
 * headers or bodies. A backtrace is taken per call (an HTTP call costs milliseconds,
 * the backtrace microseconds).
 */
final class HttpClientCollector implements Collector
{
    /** In-flight calls tracked at once; protects long-running workers from leaks. */
    private const MAX_IN_FLIGHT = 100;

    /** @var array<int, array{float, ?SourceLocation}> */
    private array $started = [];

    public function __construct(
        private readonly Dispatcher $events,
        private readonly ContextManager $manager,
        private readonly Redactor $redactor,
        private readonly SourceLocator $locator,
        private readonly FailureReporter $failures,
        private readonly bool $sourceLocation = true,
    ) {}

    public function register(): void
    {
        $this->events->listen(RequestSending::class, $this->sending(...));
        $this->events->listen(ResponseReceived::class, $this->received(...));
        $this->events->listen(ConnectionFailed::class, $this->failed(...));
    }

    public function sending(RequestSending $event): void
    {
        try {
            if (! $this->manager->isRecording()) {
                return;
            }

            if (count($this->started) >= self::MAX_IN_FLIGHT) {
                $this->started = [];
            }

            $this->started[spl_object_id($event->request)] = [
                microtime(true),
                $this->sourceLocation ? $this->locator->locate() : null,
            ];
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    public function received(ResponseReceived $event): void
    {
        try {
            $this->finish($event->request, $event->response->status(), null);
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    public function failed(ConnectionFailed $event): void
    {
        try {
            $this->finish($event->request, null, $event->exception::class);
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }

    private function finish(Request $request, ?int $status, ?string $error): void
    {
        $id = spl_object_id($request);
        $start = $this->started[$id] ?? null;
        unset($this->started[$id]);

        $context = $this->manager->current();
        if ($start === null || ! $context->isActive()) {
            return;
        }

        [$startedAt, $location] = $start;
        $now = microtime(true);
        $duration = ($now - $startedAt) * 1000;

        $context->addHttpCall(new HttpCallRecord(
            strtoupper($request->method()),
            $this->redactor->redactUrl($request->url()),
            $status,
            round($duration, 3),
            round(max(0.0, $context->elapsedMs() - $duration), 3),
            $location,
            $error,
        ));
    }
}
