<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Context\UnitLifecycle;
use Anabil\Tracing\Contracts\Collector;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Contracts\TraceContext;
use Anabil\Tracing\Data\LogRecord;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Throwable;
use WeakMap;

/**
 * MessageLogged at or above the configured level. Reported exceptions are covered too
 * (Laravel logs them with the exception in the context). Message is redacted, context is
 * redacted recursively, frames are relative paths without arguments.
 *
 * Logs raised outside any unit (scheduler closures, between worker jobs, inside an
 * ignored request) go to a standalone orphan trace, recorded before the next unit starts
 * or at terminate. An exception already attached through captureException() (e.g. by the
 * job collector) is not recorded twice when the framework reports it afterwards.
 */
final class LogCollector implements Collector
{
    private readonly int $minWeight;

    /** @var WeakMap<Throwable, bool> */
    private WeakMap $captured;

    public function __construct(
        private readonly Dispatcher $events,
        private readonly UnitLifecycle $lifecycle,
        private readonly Redactor $redactor,
        private readonly SourceLocator $locator,
        string $minLevel = 'error',
        private readonly bool $logs = true,
        private readonly bool $exceptions = true,
        private readonly int $maxFrames = 30,
    ) {
        $this->minWeight = max(1, LogRecord::levelWeight($minLevel));
        $this->captured = new WeakMap;
    }

    public function register(): void
    {
        $this->events->listen(MessageLogged::class, $this->handle(...));
    }

    public function handle(MessageLogged $event): void
    {
        if (LogRecord::levelWeight($event->level) < $this->minWeight) {
            return;
        }

        try {
            if ($this->lifecycle->manager()->isPaused()) {
                return;
            }

            $context = $event->context;
            $exception = $context['exception'] ?? null;

            if ($exception instanceof Throwable) {
                if (! $this->exceptions || isset($this->captured[$exception])) {
                    return;
                }
                unset($context['exception']);
            } elseif (! $this->logs) {
                return;
            }

            $this->target()->addLog($this->record($event->level, $event->message, $context, $exception instanceof Throwable ? $exception : null));
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    /**
     * Attach an exception to the current unit now (before the framework reports it).
     */
    public function captureException(Throwable $exception, string $level = 'error'): void
    {
        try {
            if (! $this->exceptions || isset($this->captured[$exception]) || $this->lifecycle->manager()->isPaused()) {
                return;
            }

            $this->captured[$exception] = true;
            $this->target()->addLog($this->record($level, $exception->getMessage(), [], $exception));
        } catch (Throwable $e) {
            $this->lifecycle->failures()->report($e);
        }
    }

    private function target(): TraceContext
    {
        $context = $this->lifecycle->manager()->current();

        return $context->isActive() ? $context : $this->lifecycle->orphan();
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function record(string $level, mixed $message, array $context, ?Throwable $exception): LogRecord
    {
        $text = is_string($message) ? $message : (is_scalar($message) ? (string) $message : get_debug_type($message));

        if ($exception !== null) {
            $this->captured[$exception] = true;
        }

        return new LogRecord(
            level: strtolower($level),
            message: $this->redactor->redactString($text),
            exceptionClass: $exception !== null ? $exception::class : null,
            file: $exception !== null ? $this->locator->relativePath($exception->getFile()) : null,
            line: $exception?->getLine(),
            frames: $exception !== null ? $this->frames($exception) : [],
            context: $context === [] ? [] : $this->redactor->redactArray($context),
            offsetMs: round($this->lifecycle->manager()->current()->elapsedMs(), 3),
        );
    }

    /**
     * @return list<array{file: string, line: int, class?: string|null, function?: string|null}>
     */
    private function frames(Throwable $exception): array
    {
        $frames = [['file' => $exception->getFile(), 'line' => $exception->getLine()]];
        foreach ($exception->getTrace() as $frame) {
            unset($frame['args']);
            $frames[] = $frame;
        }

        return $this->locator->frames($frames, $this->maxFrames);
    }
}
