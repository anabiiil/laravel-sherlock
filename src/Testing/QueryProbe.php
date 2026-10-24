<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

use Anabil\Sherlock\Analysis\TraceAnalysisPipeline;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\QueryNormalizer;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Recording\PatternList;
use Anabil\Sherlock\Support\BacktraceSourceLocator;
use Anabil\Sherlock\Support\HashFingerprinter;
use Anabil\Sherlock\Support\Null\NullBottleneckClassifier;
use Anabil\Sherlock\Support\SqlQueryNormalizer;
use Closure;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Testing\Fakes\EventFake;
use LogicException;

/**
 * Records the queries a callback runs, for tests. Independent from the global capture:
 * it works in every sherlock.mode (including "off") and its listener only exists while
 * the callback runs (it is removed afterwards, also when the callback throws).
 *
 * Only loaded by tests (InteractsWithSherlock, Pest expectations): zero production cost.
 */
final class QueryProbe
{
    private readonly ?PatternList $ignore;

    /**
     * @param  array<array-key, mixed>  $ignoredQueries  Str::is() patterns on the raw SQL
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly SourceLocator $locator,
        private readonly QueryNormalizer $normalizer,
        private readonly Fingerprinter $fingerprinter,
        private readonly ProbeThresholds $thresholds = new ProbeThresholds,
        private readonly array $ignoredQueries = [],
    ) {
        $ignore = new PatternList($ignoredQueries);
        $this->ignore = $ignore->isEmpty() ? null : $ignore;
    }

    /**
     * Built from the running Laravel application: its event dispatcher, Sherlock's source
     * locator / normalizer / fingerprinter (defaults when the provider is not loaded),
     * thresholds from config and "sherlock.ignore.queries".
     */
    public static function make(?Container $app = null): self
    {
        $app ??= Container::getInstance();
        if (! $app->bound('events')) {
            throw new LogicException('Sherlock query assertions need a booted Laravel application (no event dispatcher is bound).');
        }

        /** @var Dispatcher $events */
        $events = $app->make('events');
        $config = $app->bound('config') ? $app->make('config') : null;
        $config = $config instanceof Repository ? $config : null;

        $normalizer = $app->bound(QueryNormalizer::class) ? $app->make(QueryNormalizer::class) : new SqlQueryNormalizer;
        $ignored = $config?->get('sherlock.ignore.queries', []);

        return new self(
            $events,
            $app->bound(SourceLocator::class) ? $app->make(SourceLocator::class) : new BacktraceSourceLocator(self::basePath($app)),
            $normalizer,
            $app->bound(Fingerprinter::class) ? $app->make(Fingerprinter::class) : new HashFingerprinter($normalizer),
            ProbeThresholds::fromConfig($config),
            is_array($ignored) ? $ignored : [],
        );
    }

    public function withThresholds(ProbeThresholds $thresholds): self
    {
        return new self($this->events, $this->locator, $this->normalizer, $this->fingerprinter, $thresholds, $this->ignoredQueries);
    }

    /**
     * Runs $callback and returns what it queried. Exceptions from the callback are rethrown
     * after the listener is removed.
     */
    public function run(callable $callback): ProbeResult
    {
        $recorder = new QueryRecorder($this->locator, $this->ignore);
        $listener = $recorder->handle(...);
        $dispatcher = self::realDispatcher($this->events);
        $startedAt = new DateTimeImmutable;
        $start = hrtime(true);

        $dispatcher->listen(QueryExecuted::class, $listener);

        try {
            $value = $callback();
        } finally {
            $recorder->stop();
            self::forget($dispatcher, $listener);
        }

        $trace = new TraceData(
            id: 'sherlock-probe',
            type: EntryType::Command,
            name: 'the test callback',
            startedAt: $startedAt,
            durationMs: (hrtime(true) - $start) / 1e6,
            queries: $recorder->queries(),
            full: true,
        );

        $pipeline = new TraceAnalysisPipeline($this->normalizer, $this->fingerprinter, [], new NullBottleneckClassifier);

        return new ProbeResult($value, $pipeline->enrich($trace), $this->thresholds, $this->fingerprinter);
    }

    /**
     * Event::fake() wraps the dispatcher, but database connections keep dispatching on the
     * real one (they captured it before the fake), so the listener goes there.
     */
    private static function realDispatcher(Dispatcher $events): Dispatcher
    {
        while ($events instanceof EventFake && $events->dispatcher instanceof Dispatcher) {
            $events = $events->dispatcher;
        }

        return $events;
    }

    /**
     * Removes exactly this listener, keeping every other QueryExecuted listener in order.
     * A dispatcher without raw listener access keeps the (stopped, inert) listener.
     */
    private static function forget(Dispatcher $dispatcher, Closure $listener): void
    {
        if (! $dispatcher instanceof EventDispatcher) {
            return;
        }

        $raw = $dispatcher->getRawListeners()[QueryExecuted::class] ?? [];
        $remaining = array_filter(is_array($raw) ? $raw : [], static fn (mixed $candidate): bool => $candidate !== $listener);

        $dispatcher->forget(QueryExecuted::class);
        foreach ($remaining as $candidate) {
            $dispatcher->listen(QueryExecuted::class, $candidate);
        }
    }

    private static function basePath(Container $app): string
    {
        return method_exists($app, 'basePath') ? (string) $app->basePath() : (string) getcwd();
    }
}
