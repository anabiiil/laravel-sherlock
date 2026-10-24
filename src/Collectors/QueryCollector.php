<?php

declare(strict_types=1);

namespace Anabil\Tracing\Collectors;

use Anabil\Tracing\Analysis\Explain\ExplainGuard;
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
 *  - bindings: in full capture redacted via Redactor::redactBindings() (column maps cached
 *    per SQL); in lightweight capture not captured, except for a query slow enough to be
 *    EXPLAINed (>= $explainBindingsMs, a read-only SELECT): its redacted bindings are kept
 *    in memory for EXPLAIN only (QueryRecord::$explainBindings, never persisted). Such
 *    queries are rare, so the cost stays negligible;
 *  - backtrace: in full capture for the first occurrence of each raw SQL string in the
 *    trace; in lightweight capture for the second occurrence (an N+1 loop repeats the same
 *    line, a query that runs once is not worth a backtrace). Always for a query at least as
 *    slow as the slow-query threshold or the EXPLAIN threshold (missing-index location);
 *  - per-trace caps are enforced by the context (totals stay exact).
 */
final class QueryCollector implements Collector
{
    /** @var array<string, string> connection name => driver */
    private array $drivers = [];

    private readonly ?PatternList $ignore;

    /** Queries at least this slow always get a backtrace (slow-query / EXPLAIN threshold). */
    private readonly float $locateMs;

    /**
     * @param  iterable<mixed>  $ignoredQueries  Str::is() patterns on the raw SQL
     * @param  float|null  $explainBindingsMs  lightweight capture: keep EXPLAIN-only bindings of
     *                                         queries at least this slow; null = never (EXPLAIN disabled)
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
        private readonly ?float $explainBindingsMs = null,
    ) {
        $ignore = new PatternList($ignoredQueries);
        $this->ignore = $ignore->isEmpty() ? null : $ignore;
        $this->locateMs = min($slowQueryMs, $explainBindingsMs ?? $slowQueryMs);
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
            $full = $context->isFull();

            $location = null;
            $explainBindings = null;
            if ($time >= $this->locateMs) {
                // Rare slow path: always located; lightweight capture keeps EXPLAIN-only bindings.
                if ($this->sourceLocation) {
                    $location = $this->locator->locate();
                }
                if (! $full && $this->explainBindingsMs !== null && $time >= $this->explainBindingsMs
                    && $event->bindings !== [] && ExplainGuard::isSafe($sql)) {
                    $explainBindings = $this->redactor->redactBindings($sql, $event->bindings);
                }
            } elseif ($this->sourceLocation && ($full ? $context->firstOccurrence($sql) : $context->secondOccurrence($sql))) {
                $location = $this->locator->locate();
            }

            $context->addQuery(new QueryRecord(
                $sql,
                $full ? $this->redactor->redactBindings($sql, $event->bindings) : null,
                $time,
                $event->connectionName,
                $this->drivers[$event->connectionName] ??= $event->connection->getDriverName(),
                $location,
                max(0.0, $context->elapsedMs() - $time),
                null,
                null,
                $explainBindings, // positional: named arguments skipping defaults are slower on the hot path
            ));
        } catch (Throwable $e) {
            $this->failures->report($e);
        }
    }
}
