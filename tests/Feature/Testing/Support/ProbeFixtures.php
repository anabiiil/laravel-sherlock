<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Testing\Support;

use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Support\BacktraceSourceLocator;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeAuthor;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeController;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbePost;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

/**
 * Testbench's base path is its skeleton under vendor/, so no frame of these tests would be
 * "application code". The package root is used as the application root instead: tests/...
 * become application files (like app/... and tests/... in a real project), src/ stays the package.
 */
trait ProbeFixtures
{
    public const AUTHORS = 3;

    public const POSTS_PER_AUTHOR = 2;

    protected function getEnvironmentSetUp($app): void
    {
        /** @var Application $app */
        $app->singleton(SourceLocator::class, static fn (): BacktraceSourceLocator => new BacktraceSourceLocator(self::packageRoot()));
    }

    public static function packageRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** "tests/Feature/Testing/...php:42" for the line holding "// @$marker". */
    public static function locationOf(string $file, string $marker): string
    {
        foreach (file($file) ?: [] as $index => $line) {
            if (str_contains($line, '// @'.$marker)) {
                return ltrim(str_replace('\\', '/', substr($file, strlen(self::packageRoot()))), '/').':'.($index + 1);
            }
        }

        throw new RuntimeException("Marker @{$marker} not found in {$file}");
    }

    /** Runs $assertion and returns its failure message; throws when it passes. */
    public static function failureOf(Closure $assertion): string
    {
        try {
            $assertion();
        } catch (ExpectationFailedException $e) {
            return $e->getMessage();
        }

        throw new RuntimeException('The Sherlock assertion was expected to fail but passed.');
    }

    public static function controllerFile(): string
    {
        return dirname(__DIR__).'/Fixtures/ProbeController.php';
    }

    public static function createProbeSchema(): void
    {
        Schema::dropIfExists('probe_posts');
        Schema::dropIfExists('probe_authors');

        Schema::create('probe_authors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('probe_posts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('probe_author_id')->index();
            $table->string('title');
        });

        for ($a = 1; $a <= self::AUTHORS; $a++) {
            $author = ProbeAuthor::query()->create(['name' => 'author-'.$a]);
            for ($p = 1; $p <= self::POSTS_PER_AUTHOR; $p++) {
                ProbePost::query()->create(['probe_author_id' => $author->id, 'title' => "post-{$a}-{$p}"]);
            }
        }

        Route::get('/probe/lazy', [ProbeController::class, 'lazy']);
        Route::get('/probe/eager', [ProbeController::class, 'eager']);
    }

    public static function dropProbeSchema(): void
    {
        Schema::dropIfExists('probe_posts');
        Schema::dropIfExists('probe_authors');
    }
}
