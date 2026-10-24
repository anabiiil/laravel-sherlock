<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Integration\Support;

use Anabil\Sherlock\Collectors\QueryCollector;
use Anabil\Sherlock\Storage\Support\QuietReporter;
use Anabil\Sherlock\Tests\Feature\Integration\Fixtures\IntegrationController;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Shared helpers for the end-to-end and privacy suites: the fixture schema, the fixture
 * routes, and raw access to whatever the package stored.
 */
final class Integration
{
    public const SLOW_QUERY_SECONDS = 0.06;

    /** Slow-query threshold used by the suites (the fixture sleeps 60 ms). */
    public const SLOW_QUERY_MS = 40;

    public const POSTS = 8;

    public static function packageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** Relative path of a fixture file, as the source locator reports it. */
    public static function relative(string $file): string
    {
        return ltrim(str_replace('\\', '/', substr($file, strlen(self::packageRoot()))), '/');
    }

    /** Line of the first "@marker" comment in $file. */
    public static function lineOf(string $file, string $marker): int
    {
        $lines = file($file) ?: [];
        foreach ($lines as $index => $line) {
            if (str_contains($line, '// @'.$marker)) {
                return $index + 1;
            }
        }

        throw new RuntimeException("Marker @{$marker} not found in {$file}");
    }

    public static function controllerFile(): string
    {
        return self::packageRoot().'/tests/Feature/Integration/Fixtures/IntegrationController.php';
    }

    /**
     * Common setup: fixture tables + data, routes, no stale storage rows, thresholds tuned
     * so the fixture's 60 ms query is slow.
     */
    public static function setUp(string $mode = 'local'): void
    {
        QuietReporter::flush();

        config()->set('sherlock.mode', $mode);
        config()->set('sherlock.sample_rate', 0.0);
        config()->set('sherlock.thresholds.slow_query.warning_ms', self::SLOW_QUERY_MS);
        config()->set('sherlock.header.secret', 'integration-header-secret');
        // EXPLAIN runs on slow SELECTs; sleep() has no table, keep the suites deterministic.
        config()->set('sherlock.explain.enabled', false);

        self::createSchema();
        self::truncateStorage();
        self::routes();
    }

    /**
     * Rebuild the query collector from the current config (collectors are wired at boot).
     */
    public static function rebuildQueryCollector(): void
    {
        Event::forget(QueryExecuted::class);
        app()->forgetInstance(QueryCollector::class);
        app(QueryCollector::class)->register();
    }

    public static function tearDown(): void
    {
        Schema::dropIfExists('integration_posts');
        Schema::dropIfExists('integration_authors');
    }

    public static function createSchema(): void
    {
        self::tearDown();

        Schema::create('integration_authors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('integration_posts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->string('title');
        });

        $authors = [];
        for ($i = 1; $i <= self::POSTS; $i++) {
            $authors[] = ['id' => $i, 'name' => 'Author '.$i];
        }
        DB::table('integration_authors')->insert($authors);

        $posts = [];
        for ($i = 1; $i <= self::POSTS; $i++) {
            $posts[] = ['author_id' => $i, 'title' => 'Post '.$i];
        }
        DB::table('integration_posts')->insert($posts);
    }

    public static function routes(): void
    {
        Route::get('/integration/report', [IntegrationController::class, 'report'])->name('integration.report');
        Route::get('/integration/fast', [IntegrationController::class, 'fast'])->name('integration.fast');
        Route::get('/integration/n-plus-one', [IntegrationController::class, 'nPlusOne'])->name('integration.n_plus_one');
        Route::get('/integration/boom', [IntegrationController::class, 'boom'])->name('integration.boom');
        Route::get('/integration/log-error', [IntegrationController::class, 'logError'])->name('integration.log_error');
        Route::get('/integration/nested', [IntegrationController::class, 'nested'])->name('integration.nested');
    }

    /** @return list<string> every sherlock_* table that exists on the Sherlock connection */
    public static function storageTables(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $rows = $db->select(
            'select table_name as name from information_schema.tables where table_schema = ? and table_name like ?',
            [$db->getDatabaseName(), 'sherlock\_%'],
        );

        return array_values(array_map(static fn (object $row): string => (string) $row->name, $rows));
    }

    public static function truncateStorage(): void
    {
        foreach (self::storageTables() as $table) {
            DB::table($table)->truncate();
        }
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $table): array
    {
        return array_values(array_map(
            static fn (object $row): array => (array) $row,
            DB::table($table)->get()->all(),
        ));
    }

    public static function count(string $table): int
    {
        return DB::table($table)->count();
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
