<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| End-to-end request overhead benchmark
|--------------------------------------------------------------------------
|
|   php benchmarks/e2e-overhead.php [--requests=300] [--warmup=30] [--rounds=3] [--database=tracing_bench]
|
| Boots a Testbench application on MySQL (one PHP process per scenario) with a route that
| runs 100 real queries: 1 posts query + a 40x lazy-loaded belongsTo (N+1) + 59 lookups.
| Each request goes through the real HTTP kernel; response time (Kernel::handle(), what the
| user waits for) and terminate time (Kernel::terminate(), after the response was sent:
| analysis + persistence) are measured separately. Each scenario runs --rounds times, in a
| rotating order (CPU boost / MySQL warm-up drift otherwise favours whichever runs last);
| reported: the median over rounds of the per-round median over --requests.
|
| Scenarios:
|   none          - the package is not registered at all (baseline)
|   off           - mode=off
|   production    - mode=production, not sampled, no threshold crossed (thresholds raised):
|                   captured in memory, analyzed at terminate, nothing persisted
|   prod_persist  - mode=production, default thresholds: N+1 + excessive queries crossed,
|                   analyzed and persisted (+ issues upserted) at terminate
|   local         - mode=local: full capture (redacted bindings), always persisted
|
| DB credentials: DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD env vars, falling back to the
| values of a local phpunit.xml. The benchmark database is created if missing and its
| bench_* / tracing_* tables are dropped afterwards.
|
*/

use Anabil\Tracing\TracingServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application as Testbench;

require __DIR__.'/../vendor/autoload.php';

const SCENARIOS = ['none', 'off', 'production', 'prod_persist', 'local'];

$options = getopt('', ['requests::', 'warmup::', 'database::', 'scenario::', 'rounds::']);
$rounds = max(1, (int) ($options['rounds'] ?? 3));
$requests = max(20, (int) ($options['requests'] ?? 300));
$warmup = max(1, (int) ($options['warmup'] ?? 30));
$database = (string) ($options['database'] ?? 'tracing_bench');

/** env var, else the value in a local phpunit.xml, else $default */
function bench_env(string $name, string $default): string
{
    $value = getenv($name);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    static $xml = null;
    $xml ??= is_file(__DIR__.'/../phpunit.xml') ? (string) file_get_contents(__DIR__.'/../phpunit.xml') : '';
    if (preg_match('/<env\s+name="'.preg_quote($name, '/').'"\s+value="([^"]*)"/', $xml, $m) === 1) {
        return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
    }

    return $default;
}

if (! isset($options['scenario'])) {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s', bench_env('DB_HOST', '127.0.0.1'), bench_env('DB_PORT', '3306')),
        bench_env('DB_USERNAME', 'root'),
        bench_env('DB_PASSWORD', ''),
    );
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '', $database).'`');

    $samples = [];
    for ($round = 0; $round < $rounds; $round++) {
        $order = SCENARIOS;
        for ($r = 0; $r < $round; $r++) {
            $order[] = array_shift($order);
        }
        if ($round % 2 === 1) {
            $order = array_reverse($order);
        }

        foreach ($order as $scenario) {
            $command = sprintf('%s %s --scenario=%s --requests=%d --warmup=%d --database=%s',
                escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), $scenario, $requests, $warmup, escapeshellarg($database));
            $output = shell_exec($command);
            $decoded = is_string($output) ? json_decode(trim($output), true) : null;
            if (! is_array($decoded)) {
                fwrite(STDERR, "Scenario {$scenario} failed:\n".(string) $output."\n");
                exit(1);
            }
            $samples[$scenario][] = $decoded;
        }
    }

    $results = [];
    foreach (SCENARIOS as $scenario) {
        $result = $samples[$scenario][0];
        foreach (['response_ms', 'response_p90_ms', 'terminate_ms'] as $key) {
            $values = array_column($samples[$scenario], $key);
            sort($values);
            $result[$key] = $values[intdiv(count($values), 2)];
        }
        $results[$scenario] = $result;
    }

    printf("PHP %s (opcache %s), %s, Laravel %s, MySQL, %d queries per request, median of %d rounds x %d requests (%d warm-up)\n\n",
        PHP_VERSION, $results['none']['opcache'] ? 'on' : 'off', php_uname('m'), $results['none']['laravel'],
        $results['none']['queries'], $rounds, $requests, $warmup);

    $base = $results['none'];
    printf("%-14s %12s %12s %12s %12s %10s %10s %10s\n",
        'scenario', 'response ms', 'vs none', 'terminate ms', 'vs none', 'p90 resp', 'persisted', 'issues');
    printf("%s\n", str_repeat('-', 100));
    foreach ($results as $scenario => $r) {
        printf("%-14s %12.3f %+12.3f %12.3f %+12.3f %10.3f %10d %10d\n",
            $scenario,
            $r['response_ms'],
            $r['response_ms'] - $base['response_ms'],
            $r['terminate_ms'],
            $r['terminate_ms'] - $base['terminate_ms'],
            $r['response_p90_ms'],
            $r['traces'],
            $r['issues'],
        );
    }

    printf("\nResponse-time overhead of production over none: %+.3f ms per request (%+.2f µs per query).\n",
        $results['production']['response_ms'] - $base['response_ms'],
        ($results['production']['response_ms'] - $base['response_ms']) * 1000 / $base['queries']);
    printf("Analysis + persistence run in terminate (after the response is sent): production %.3f ms, prod_persist %.3f ms, local %.3f ms.\n",
        $results['production']['terminate_ms'], $results['prod_persist']['terminate_ms'], $results['local']['terminate_ms']);

    exit(0);
}

// ---------------------------------------------------------------- one scenario (child process)

final class BenchAuthor extends Model
{
    protected $table = 'bench_authors';

    public $timestamps = false;
}

final class BenchPost extends Model
{
    protected $table = 'bench_posts';

    public $timestamps = false;

    /** @return BelongsTo<BenchAuthor, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(BenchAuthor::class);
    }
}

$scenario = (string) $options['scenario'];
$mode = match ($scenario) {
    'none', 'off' => 'off',
    'production', 'prod_persist' => 'production',
    'local' => 'local',
    default => throw new InvalidArgumentException("Unknown scenario {$scenario}"),
};
$env = [
    'TRACING_MODE' => $mode, 'TRACING_SAMPLE_RATE' => '0', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => bench_env('DB_HOST', '127.0.0.1'), 'DB_PORT' => bench_env('DB_PORT', '3306'),
    'DB_DATABASE' => $database, 'DB_USERNAME' => bench_env('DB_USERNAME', 'root'), 'DB_PASSWORD' => bench_env('DB_PASSWORD', ''),
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
];
foreach ($env as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}

$app = Testbench::create(
    options: ['extra' => ['providers' => $scenario === 'none' ? [] : [TracingServiceProvider::class], 'dont-discover' => ['*']]],
);

$config = $app['config'];
$config->set('tracing.explain.enabled', false);
if ($scenario === 'production') {
    // Same capture + analysis work, but nothing crosses a threshold: nothing is persisted.
    $config->set('tracing.thresholds.n_plus_one.warning_count', 100000);
    $config->set('tracing.thresholds.excessive_queries.warning_count', 100000);
    $config->set('tracing.thresholds.duplicate_query.min_count', 100000);
}

// Schema: fixture tables + fresh tracing tables.
Schema::dropIfExists('bench_settle');
Schema::create('bench_settle', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedInteger('request');
});
Schema::dropIfExists('bench_posts');
Schema::dropIfExists('bench_authors');
Schema::create('bench_authors', static function (Blueprint $table): void {
    $table->id();
    $table->string('name');
});
Schema::create('bench_posts', static function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('author_id');
    $table->string('title');
});
DB::table('bench_authors')->insert(array_map(static fn (int $i): array => ['id' => $i, 'name' => 'Author '.$i], range(1, 40)));
DB::table('bench_posts')->insert(array_map(static fn (int $i): array => ['author_id' => $i, 'title' => 'Post '.$i], range(1, 40)));

$migration = require __DIR__.'/../database/migrations/2026_01_01_000000_create_tracing_tables.php';
$migration->down();
if ($scenario !== 'none') {
    $migration->up();
}

Route::get('/bench', static function (): JsonResponse {
    $names = [];
    foreach (BenchPost::query()->orderBy('id')->get() as $post) { // 1 query
        $names[] = $post->author->name;                           // 40 queries (N+1)
    }
    for ($i = 0; $i < 59; $i++) {                                 // 59 queries
        $names[] = DB::table('bench_authors')->where('id', $i % 40 + 1)->value('name');
    }

    return new JsonResponse(['names' => count($names)]);
});

$kernel = $app->make(HttpKernel::class);
$queries = 0;
DB::listen(static function () use (&$queries): void {
    $queries++;
});

$response = [];
$terminate = [];
for ($i = 0; $i < $warmup + $requests; $i++) {
    $request = Request::create('/bench', 'GET');
    $queries = 0;

    $start = hrtime(true);
    $result = $kernel->handle($request);
    $handled = hrtime(true);
    $requestQueries = $queries;
    $kernel->terminate($request, $result);
    $done = hrtime(true);

    // Untimed, in every scenario: one small write between requests. On MySQL (seen on 8.4 /
    // macOS) a write after a request makes the next request's reads ~20% faster, which would
    // otherwise favour the scenarios that persist. This keeps the scenarios comparable.
    DB::table('bench_settle')->insert(['request' => $i]);

    if ($result->getStatusCode() !== 200) {
        fwrite(STDOUT, 'HTTP '.$result->getStatusCode().': '.substr((string) $result->getContent(), 0, 500));
        exit(1);
    }

    if ($i >= $warmup) {
        $response[] = ($handled - $start) / 1e6;
        $terminate[] = ($done - $handled) / 1e6;
    }
}

$percentile = static function (array $values, float $p): float {
    sort($values);

    return $values[min(count($values) - 1, (int) floor(count($values) * $p))];
};

$traces = $scenario === 'none' || ! Schema::hasTable('tracing_traces') ? 0 : DB::table('tracing_traces')->count();
$issues = $scenario === 'none' || ! Schema::hasTable('tracing_issues') ? 0 : DB::table('tracing_issues')->count();

$migration->down();
Schema::dropIfExists('bench_settle');
Schema::dropIfExists('bench_posts');
Schema::dropIfExists('bench_authors');

echo json_encode([
    'laravel' => $app->version(),
    'opcache' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)),
    'queries' => $requestQueries,
    'response_ms' => $percentile($response, 0.5),
    'response_p90_ms' => $percentile($response, 0.9),
    'terminate_ms' => $percentile($terminate, 0.5),
    'traces' => $traces,
    'issues' => $issues,
]);
