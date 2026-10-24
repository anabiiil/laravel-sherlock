<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Capture overhead benchmark
|--------------------------------------------------------------------------
|
|   php benchmarks/capture-overhead.php [--queries=2000] [--events=300] [--runs=5]
|
| Boots a Testbench application per mode (each in its own PHP process) and pushes
| framework events through the real dispatcher + collectors:
|   off         - mode=off: no listener registered (baseline: bare dispatcher cost)
|   production  - mode=production, lightweight capture (no bindings)
|   full        - mode=local, full capture (redacted bindings)
|
| Queries: 20 distinct SQL strings repeated, in units of 500 queries, so each unit takes
| 20 backtraces (the realistic case). A second scenario ("100 distinct SQL") runs units of
| 100 queries that are all different (e.g. a page made of many unrelated lookups): in
| lightweight capture no backtrace is taken for them (only repeats and slow queries get a
| location), in full capture every one gets a backtrace. Reported per event: the median of
| --runs runs, as total dispatch time and as overhead over "off".
|
*/

use Anabil\Sherlock\Collectors\QueryCollector;
use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Contracts\TraceRecorder;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\SherlockServiceProvider;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Log\Events\MessageLogged;
use Orchestra\Testbench\Foundation\Application as Testbench;

require __DIR__.'/../vendor/autoload.php';

$options = getopt('', ['queries::', 'events::', 'runs::', 'mode::']);
$queries = max(100, (int) ($options['queries'] ?? 2000));
$events = max(10, (int) ($options['events'] ?? 300));
$runs = max(1, (int) ($options['runs'] ?? 5));

const MODES = ['off' => 'off', 'production' => 'production', 'full' => 'local'];

if (! isset($options['mode'])) {
    $results = [];
    foreach (array_keys(MODES) as $mode) {
        $command = sprintf(
            '%s %s --mode=%s --queries=%d --events=%d --runs=%d',
            escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), $mode, $queries, $events, $runs,
        );
        $output = shell_exec($command);
        $decoded = is_string($output) ? json_decode(trim($output), true) : null;
        if (! is_array($decoded)) {
            fwrite(STDERR, "Mode {$mode} failed:\n".(string) $output."\n");
            exit(1);
        }
        $results[$mode] = $decoded;
    }

    printf("PHP %s, %s, Laravel %s, %d queries / %d cache+log events, median of %d runs\n\n",
        PHP_VERSION, php_uname('m'), $results['off']['laravel'], $queries, $events, $runs);

    $rows = [
        'query' => 'QueryExecuted',
        'query_direct' => 'QueryCollector::handle() only',
        'query_distinct' => 'QueryExecuted, 100 distinct SQL',
        'cache' => 'Cache hit (Retrieving+Hit)',
        'cache_miss' => 'Cache miss (Retrieving+Miss)',
        'log_below' => 'MessageLogged below level',
        'log_error' => 'MessageLogged error (redacted)',
    ];

    printf("%-32s %14s %14s %14s %16s %16s\n", 'event (µs per event)', 'off', 'production', 'full', 'prod overhead', 'full overhead');
    printf("%s\n", str_repeat('-', 112));
    foreach ($rows as $key => $label) {
        $off = $results['off'][$key] ?? null;
        $prod = $results['production'][$key];
        $full = $results['full'][$key];
        printf("%-32s %14s %14.3f %14.3f %16s %16s\n",
            $label,
            $off === null ? 'n/a' : sprintf('%.3f', $off),
            $prod,
            $full,
            $off === null ? '-' : sprintf('%+.3f', $prod - $off),
            $off === null ? '-' : sprintf('%+.3f', $full - $off),
        );
    }

    printf("\nTotal for %d queries: off %.2f ms, production %.2f ms, full %.2f ms\n",
        $queries,
        $results['off']['query'] * $queries / 1000,
        $results['production']['query'] * $queries / 1000,
        $results['full']['query'] * $queries / 1000,
    );
    printf("Backtraces per unit of 500 (20 distinct SQL): production %s, full %s\n",
        $results['production']['backtraces'], $results['full']['backtraces']);
    printf("Backtraces per unit of 100 distinct SQL: production %s, full %s\n",
        $results['production']['backtraces_distinct'], $results['full']['backtraces_distinct']);

    exit(0);
}

// ---------------------------------------------------------------- single mode (child process)

$mode = (string) $options['mode'];
$_ENV['SHERLOCK_MODE'] = $_SERVER['SHERLOCK_MODE'] = MODES[$mode];
putenv('SHERLOCK_MODE='.MODES[$mode]);
$_ENV['SHERLOCK_SAMPLE_RATE'] = $_SERVER['SHERLOCK_SAMPLE_RATE'] = '0';

$app = Testbench::create(options: ['extra' => ['providers' => [SherlockServiceProvider::class], 'dont-discover' => ['*']]]);

$app->instance(TraceRecorder::class, new class implements TraceRecorder
{
    public function record(TraceData $trace): void {}
});

// Counts backtraces (locate() calls) so the reported numbers are measured, not assumed.
// The extra method call costs a few ns per backtrace, nothing per query.
$locator = new class($app->make(SourceLocator::class)) implements SourceLocator
{
    public int $calls = 0;

    public function __construct(private readonly SourceLocator $inner) {}

    public function locate(?array $frames = null): ?SourceLocation
    {
        $this->calls++;

        return $this->inner->locate($frames);
    }

    public function relativePath(string $file): string
    {
        return $this->inner->relativePath($file);
    }

    public function frames(array $frames, int $limit = 30): array
    {
        return $this->inner->frames($frames, $limit);
    }
};
$app->instance(SourceLocator::class, $locator);
if ($mode !== 'off' && $app->bound(QueryCollector::class)) {
    $app->forgetInstance(QueryCollector::class);
    $app->make('events')->forget(QueryExecuted::class);
    $app->make(QueryCollector::class)->register();
}

$dispatcher = $app->make('events');
$connection = new MySqlConnection(static fn () => null, 'bench', '', ['driver' => 'mysql', 'name' => 'mysql']);

/** @var list<QueryExecuted> $queryEvents */
$queryEvents = [];
for ($i = 0; $i < 20; $i++) {
    $queryEvents[] = new QueryExecuted(
        "select * from `orders` where `user_id` = ? and `status` = ? and `email` = ? /* variant {$i} */",
        [42, 'paid', 'ann@example.com'],
        1.25,
        $connection,
    );
}

/** @var list<QueryExecuted> $distinctEvents */
$distinctEvents = [];
for ($i = 0; $i < 100; $i++) {
    $distinctEvents[] = new QueryExecuted(
        "select * from `table_{$i}` where `user_id` = ? and `status` = ? limit 1",
        [42, 'paid'],
        0.4,
        $connection,
    );
}

$lifecycle = $app->bound(UnitLifecycle::class) ? $app->make(UnitLifecycle::class) : null;
$begin = static function () use ($lifecycle): void {
    $lifecycle?->begin(new EntryCandidate(EntryType::Job, 'App\Jobs\Benchmark'));
};
$end = static function () use ($lifecycle): void {
    $lifecycle?->end();
};

$median = static function (array $values): float {
    sort($values);

    return $values[intdiv(count($values), 2)];
};

/**
 * @param  Closure(int): void  $body
 */
$measure = static function (int $count, Closure $body, bool $units = true, int $unit = 500) use ($runs, $begin, $end, $median): float {
    $samples = [];
    for ($run = 0; $run <= $runs; $run++) { // run 0 = warm-up
        $elapsed = 0;
        for ($offset = 0; $offset < $count; $offset += $unit) {
            $chunk = min($unit, $count - $offset);
            if ($units) {
                $begin();
            }
            $start = hrtime(true);
            for ($i = 0; $i < $chunk; $i++) {
                $body($offset + $i);
            }
            $elapsed += hrtime(true) - $start;
            if ($units) {
                $end();
            }
        }
        if ($run > 0) {
            $samples[] = $elapsed / $count / 1000; // ns -> µs
        }
    }

    return $median($samples);
};

// Empty-loop cost of the closure call itself, subtracted from every measurement.
$loop = $measure($queries, static function (int $i): void {}, false);

$result = ['laravel' => $app->version()];

$locator->calls = 0;
$result['query'] = $measure($queries, static fn (int $i) => $dispatcher->dispatch($queryEvents[$i % 20])) - $loop;
// Per unit: ($runs + 1) passes of ceil($queries / 500) units.
$result['backtraces'] = $locator->calls / (($runs + 1) * (int) ceil($queries / 500));

// Units of 100 queries, all distinct SQL strings.
$locator->calls = 0;
$result['query_distinct'] = $measure($queries, static fn (int $i) => $dispatcher->dispatch($distinctEvents[$i % 100]), true, 100) - $loop;
$result['backtraces_distinct'] = $locator->calls / (($runs + 1) * (int) ceil($queries / 100));

if ($app->bound(QueryCollector::class)) {
    $collector = $app->make(QueryCollector::class);
    $result['query_direct'] = $measure($queries, static fn (int $i) => $collector->handle($queryEvents[$i % 20])) - $loop;
} else {
    $result['query_direct'] = null;
}

$retrieving = new RetrievingKey('array', 'some-key');
$hit = new CacheHit('array', 'some-key', 'value');
$miss = new CacheMissed('array', 'some-key');
$result['cache'] = ($measure($events, static function () use ($dispatcher, $retrieving, $hit): void {
    $dispatcher->dispatch($retrieving);
    $dispatcher->dispatch($hit);
}) - $loop) / 2;
$result['cache_miss'] = ($measure($events, static function () use ($dispatcher, $retrieving, $miss): void {
    $dispatcher->dispatch($retrieving);
    $dispatcher->dispatch($miss);
}) - $loop) / 2;

$info = new MessageLogged('info', 'User 42 viewed the dashboard', ['user_id' => 42]);
$error = new MessageLogged('error', 'Payment failed for ann@example.com password=hunter2', ['order' => 15, 'token' => 'abc']);
$result['log_below'] = $measure($events, static fn () => $dispatcher->dispatch($info)) - $loop;
// The context caps logs at 100 per trace: measure in units of 100.
$result['log_error'] = $median(array_map(static function () use ($dispatcher, $error, $begin, $end): float {
    $begin();
    $start = hrtime(true);
    for ($i = 0; $i < 100; $i++) {
        $dispatcher->dispatch($error);
    }
    $elapsed = (hrtime(true) - $start) / 100 / 1000;
    $end();

    return $elapsed;
}, range(0, $runs))) - $loop;

echo json_encode($result);
