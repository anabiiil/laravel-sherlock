<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Context\ContextManager;
use Anabil\Tracing\Context\FailureReporter;
use Anabil\Tracing\Contracts\Collector;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Recording\PatternList;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

/**
 * QueryExecuted -> QueryRecord. The hot path is a cheap array push:
 *  - no normalization / fingerprinting (done at terminate by the analyzers);
 *  - bindings: dropped entirely in lightweight capture; in full capture redacted via
 *    Redactor::redactBindings() (column maps cached per SQL);
 *  - backtrace only for the first occurrence of each raw SQL string in the trace, or when
 *    the query itself is slower than the slow-query threshold;
 *  - per-trace caps are enforced by the context (totals stay exact).
 */
final class QueryCollector implements Collector
{
    /** @var array<string, string> connection name => driver */
    private array $drivers = [];

    private readonly ?PatternList $ignore;

    /**
     * @param  iterable<mixed>  $ignoredQueries  Str::is() patterns on the raw SQL
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ContextManager $manager,
        private readonly Redactor $redactor,
        private readonly SourceLocator $locator,
        private readonly FailureReporter $failures,
        private readonly float $slowQueryMs = 100.0,
        private readonly bool $sourceLocation = true,
        iterable $ignoredQueries = [],
    ) {
        $ignore = new PatternList($ignoredQueries);
        $this->ignore = $ignore->isEmpty() ? null : $ignore;
    }

    public function register(): void
    {
        $this->events->listen(QueryExecuted::class, $this->handle(...));
    }

    public function handle(QueryExecuted $event): void
    {
        try {
            $context = $this->manager->current();
            if (! $context->isActive()) {
                return;
            }

            $sql = $event->sql;
            if ($this->ignore !== null && $this->ignore->matches($sql)) {
                return;
            }

            $time = (float) $event->time;

            $location = null;
            if ($this->sourceLocation && ($time >= $this->slowQueryMs || $context->firstOccurrence($sql))) {
                $location = $this->locator->locate();
            }

            $context->addQuery(new QueryRecord(
                $sql,
                $context->isFull() ? $this->redactor->redactBindings($sql, $event->bindings) : null,
                $time,
                $event->connectionName,
                $this->drivers[$event->connectionName] ??= $event->connection->getDriverName(),
                $location,
                max(0.0, $context->elapsedMs() - $time),
            ));
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }
}
