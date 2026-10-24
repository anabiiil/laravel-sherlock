<?php

declare(strict_types=1);

/*
 | Analysis overhead micro-benchmark (no Laravel boot, no EXPLAIN).
 |
 | Analyses a synthetic production-like trace: 500 queries (incl. an N+1 of 200),
 | light capture (no bindings, locations only on first occurrences / slow queries),
 | a few HTTP calls, cache lookups and an exception, through the full analyzer list.
 |
 | Usage: php benchmarks/analysis-overhead.php [iterations=200]
 */

use Anabil\Tracing\Analysis\Analyzers\CacheMissRateAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\DuplicateQueryAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\ExceptionAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\ExcessiveQueriesAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\HighMemoryAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\MissingIndexAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\NPlusOneAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowEntryAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowExternalCallAnalyzer;
use Anabil\Tracing\Analysis\Analyzers\SlowQueryAnalyzer;
use Anabil\Tracing\Analysis\DefaultBottleneckClassifier;
use Anabil\Tracing\Analysis\TraceAnalysisPipeline;
use Anabil\Tracing\Data\HttpCallRecord;
use Anabil\Tracing\Data\LogRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Support\HashFingerprinter;
use Anabil\Tracing\Support\SqlQueryNormalizer;

require __DIR__.'/../vendor/autoload.php';

$iterations = max(1, (int) ($argv[1] ?? 200));

/**
 * Raw SQL as Laravel emits it (placeholders); a production trace has no bindings.
 */
function syntheticTrace(): TraceData
{
    $queries = [];
    $location = static fn (int $line): SourceLocation => new SourceLocation('app/Http/Controllers/OrderController.php', $line, 'App\\Http\\Controllers\\OrderController', 'index');

    // N+1 of 200: same raw SQL, only the first occurrence is located.
    for ($i = 0; $i < 200; $i++) {
        $queries[] = new QueryRecord(
            'select * from `posts` where `posts`.`user_id` = ? and `posts`.`user_id` is not null',
            null, 0.8, 'mysql', 'mysql', $i === 0 ? $location(40) : null, $i * 1.0,
        );
    }

    // 280 queries over 70 distinct shapes (4 executions each).
    for ($i = 0; $i < 280; $i++) {
        $shape = $i % 70;
        $queries[] = new QueryRecord(
            "select `id`, `name`, `col_{$shape}` from `table_{$shape}` where `table_{$shape}`.`owner_id` = ? and `status` in (?, ?, ?) order by `created_at` desc limit 15",
            null, 1.2, 'mysql', 'mysql', $i < 70 ? $location(100 + $shape) : null, 200.0 + $i,
        );
    }

    // 20 slow queries.
    for ($i = 0; $i < 20; $i++) {
        $queries[] = new QueryRecord(
            'select count(*) as aggregate from `orders` where `orders`.`created_at` between ? and ? and `orders`.`total` > ?',
            null, 150.0 + $i, 'mysql', 'mysql', $location(200), 500.0 + $i,
        );
    }

    $http = [];
    for ($i = 0; $i < 5; $i++) {
        $http[] = new HttpCallRecord('GET', 'https://api.example.com/v1/items/'.$i, 200, $i === 4 ? 1500.0 : 80.0);
    }

    return new TraceData(
        id: '01JBENCH',
        type: EntryType::Request,
        name: '/orders',
        startedAt: new DateTimeImmutable,
        durationMs: 6200.0,
        method: 'GET',
        bootstrapMs: 40.0,
        memoryPeakBytes: 96 * 1048576,
        queries: $queries,
        httpCalls: $http,
        cacheHits: 30,
        cacheMisses: 25,
        cacheMs: 12.0,
        logs: [new LogRecord('error', 'Order 15 failed', 'RuntimeException', 'app/Services/Billing.php', 42)],
    );
}

function pipeline(): TraceAnalysisPipeline
{
    $normalizer = new SqlQueryNormalizer;
    $fp = new HashFingerprinter($normalizer);

    return new TraceAnalysisPipeline($normalizer, $fp, [
        new SlowQueryAnalyzer($fp),
        new NPlusOneAnalyzer($fp),
        new DuplicateQueryAnalyzer($fp),
        new ExcessiveQueriesAnalyzer($fp),
        new SlowEntryAnalyzer($fp),
        new HighMemoryAnalyzer($fp),
        new SlowExternalCallAnalyzer($fp),
        new CacheMissRateAnalyzer($fp),
        new ExceptionAnalyzer($fp),
        new MissingIndexAnalyzer($fp),
    ], new DefaultBottleneckClassifier);
}

$trace = syntheticTrace();

// Cold: very first trace of a process, including class autoloading.
$start = hrtime(true);
$result = pipeline()->analyze($trace);
$coldMs = (hrtime(true) - $start) / 1e6;

$percentiles = static function (array $timings): array {
    sort($timings);

    return [$timings[intdiv(count($timings), 2)], $timings[(int) floor(count($timings) * 0.95)], $timings[0]];
};

// PHP-FPM: the container (and the normalizer memo) is rebuilt for every request.
$fresh = [];
for ($i = 0; $i < $iterations; $i++) {
    $pipeline = pipeline();
    $start = hrtime(true);
    $pipeline->analyze($trace);
    $fresh[] = (hrtime(true) - $start) / 1e6;
}

// Octane / queue worker: singletons live across traces (normalizer cache warm).
$shared = pipeline();
$warm = [];
for ($i = 0; $i < $iterations; $i++) {
    $start = hrtime(true);
    $shared->analyze($trace);
    $warm[] = (hrtime(true) - $start) / 1e6;
}

$memoryBefore = memory_get_usage();
$kept = pipeline()->analyze($trace);
$memoryMb = (memory_get_usage() - $memoryBefore) / 1048576;

printf("Synthetic trace: %d queries (N+1 of 200), %d HTTP calls, PHP %s\n", count($trace->queries), count($trace->httpCalls), PHP_VERSION);
printf("Findings: %s\n", implode(', ', array_map(static fn ($f): string => $f->type->value, $result->findings)));
printf("Verdict: %s - %s\n", $result->verdict->bottleneck->value, $result->verdict->reason);
printf("Cold (first trace of the process, incl. autoload): %.3f ms\n", $coldMs);
printf("Per request, fresh services (PHP-FPM)  median: %.3f ms   p95: %.3f ms   min: %.3f ms\n", ...$percentiles($fresh));
printf("Long-lived worker (Octane / queue)      median: %.3f ms   p95: %.3f ms   min: %.3f ms\n", ...$percentiles($warm));
printf("Runs: %d each\n", $iterations);
printf("Retained memory for the result: %.2f MB\n", $memoryMb);
unset($kept);
