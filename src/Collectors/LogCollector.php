<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Context\UnitLifecycle;
use Anabil\Tracing\Contracts\Collector;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Contracts\TraceContext;
use Anabil\Tracing\Data\LogRecord;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/**
 * MessageLogged at or above the configured level. Reported exceptions are covered too
 * (Laravel logs them with the exception in the context). Message is redacted, context is
 * redacted recursively, frames are relative paths without arguments.
 *
 * Plain logs (no exception) record their call site: the first application frame.
 *
 * Values the redactor cannot recognize by shape are scrubbed too, on this (rare) path only:
 *  - a QueryException message has its bindings interpolated into the SQL ("values (a, s3cret)"):
 *    the interpolated SQL is replaced with the placeholder SQL;
 *  - values the current request sent under a sensitive key (password, token...) are removed
 *    from the message and the context wherever they appear ("login failed with password s3cret").
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
        private readonly ?Closure $knownSecrets = null,
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

    /**
     * Never record this exception (one of the package's own jobs failed; the worker reports it next).
     */
    public function ignoreException(Throwable $exception): void
    {
        $this->captured[$exception] = true;
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
            $file = $this->locator->relativePath($exception->getFile());
            $line = $exception->getLine();
        } else {
            // Call site of Log::error() (first application frame). Error logs are rare, so one
            // backtrace here is cheap, and it lets the same log line from two routes be one issue.
            $site = $this->locator->locate();
            $file = $site?->file;
            $line = $site?->line;
        }

        if ($exception instanceof QueryException) {
            $text = self::withoutBindings($exception, $text);
        }

        $secrets = $this->secrets();
        $context = $context === [] ? [] : $this->redactor->redactArray($context);
        if ($secrets !== []) {
            $text = self::scrub($text, $secrets);
            array_walk_recursive($context, static function (mixed &$value) use ($secrets): void {
                if (is_string($value)) {
                    $value = self::scrub($value, $secrets);
                }
            });
        }

        return new LogRecord(
            level: strtolower($level),
            message: $this->redactor->redactString($text),
            exceptionClass: $exception !== null ? $exception::class : null,
            file: $file,
            line: $line,
            frames: $exception !== null ? $this->frames($exception) : [],
            context: $context,
            offsetMs: round($this->lifecycle->manager()->current()->elapsedMs(), 3),
        );
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        if ($this->knownSecrets === null) {
            return [];
        }

        $secrets = ($this->knownSecrets)();

        return is_array($secrets) ? array_values(array_filter($secrets, 'is_string')) : [];
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function scrub(string $value, array $secrets): string
    {
        return str_replace($secrets, Redactor::REDACTED, $value);
    }

    /** Put the placeholders back where Laravel interpolated the bindings into the message. */
    private static function withoutBindings(QueryException $exception, string $text): string
    {
        try {
            $bindings = $exception->getBindings();
            if ($bindings === []) {
                return $text;
            }

            // Exactly how QueryException::formatMessage() interpolated them.
            return str_replace(Str::replaceArray('?', $bindings, $exception->getSql()), $exception->getSql(), $text);
        } catch (Throwable) {
            return $text;
        }
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
