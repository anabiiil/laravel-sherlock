<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Recording\PatternList;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Stringable;
use UnitEnum;

/**
 * QueryExecuted listener of one probe run. Tests can afford full detail, so every query
 * keeps its (unredacted, in memory only) bindings and its source location.
 *
 * Skipped: queries matching "sherlock.ignore.queries" and queries issued by Sherlock
 * itself (a frame inside the package's src/, outside src/Testing: trace persistence at
 * the end of a request made with $this->get(), the dashboard, ...).
 */
final class QueryRecorder
{
    /** Deep enough for a lazy load inside a view rendered by a controller behind middleware. */
    private const BACKTRACE_LIMIT = 200;

    /** @var list<QueryRecord> */
    private array $queries = [];

    /** @var array<string, string> connection name => driver */
    private array $drivers = [];

    private bool $recording = true;

    private readonly float $startedAt;

    private readonly string $packageSource;

    private readonly string $testingSource;

    public function __construct(
        private readonly SourceLocator $locator,
        private readonly ?PatternList $ignore = null,
    ) {
        $this->startedAt = hrtime(true) / 1e6;
        $this->packageSource = self::normalize(dirname(__DIR__)).'/';
        $this->testingSource = self::normalize(__DIR__).'/';
    }

    public function handle(QueryExecuted $event): void
    {
        if (! $this->recording) {
            return;
        }

        $sql = $event->sql;
        if ($this->ignore !== null && $this->ignore->matches($sql)) {
            return;
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT);
        if ($this->issuedBySherlock($frames)) {
            return;
        }

        $time = (float) $event->time;

        $this->queries[] = new QueryRecord(
            $sql,
            self::bindings($event),
            $time,
            $event->connectionName,
            $this->drivers[$event->connectionName] ??= $event->connection->getDriverName(),
            $this->locator->locate($frames),
            max(0.0, hrtime(true) / 1e6 - $this->startedAt - $time),
        );
    }

    /** Ignores every query from now on (the listener may outlive the run on a custom dispatcher). */
    public function stop(): void
    {
        $this->recording = false;
    }

    /**
     * @return list<QueryRecord>
     */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $frames
     */
    private function issuedBySherlock(array $frames): bool
    {
        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if (! is_string($file)) {
                continue;
            }

            $file = self::normalize($file);
            if (str_starts_with($file, $this->packageSource) && ! str_starts_with($file, $this->testingSource)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int|string, scalar|null>
     */
    private static function bindings(QueryExecuted $event): array
    {
        $bindings = [];

        foreach ($event->connection->prepareBindings($event->bindings) as $key => $value) {
            $bindings[$key] = match (true) {
                $value === null, is_scalar($value) => $value,
                $value instanceof BackedEnum => $value->value,
                $value instanceof UnitEnum => $value->name,
                $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s.u'),
                $value instanceof Stringable => (string) $value,
                default => get_debug_type($value),
            };
        }

        return $bindings;
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
