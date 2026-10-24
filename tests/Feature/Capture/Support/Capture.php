<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Capture\Support;

use Anabil\Sherlock\Collectors\QueryCollector;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Contracts\TraceRecorder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Test helpers for the capture layer.
 */
final class Capture
{
    public static function fakeRecorder(): FakeTraceRecorder
    {
        $recorder = new FakeTraceRecorder;
        app()->instance(TraceRecorder::class, $recorder);

        return $recorder;
    }

    /**
     * Rebuild the query collector with a spy locator (collectors are wired at boot).
     */
    public static function spyLocator(): SpySourceLocator
    {
        $spy = new SpySourceLocator;

        Event::forget(QueryExecuted::class);
        app()->instance(SourceLocator::class, $spy);
        app()->forgetInstance(QueryCollector::class);
        app(QueryCollector::class)->register();

        return $spy;
    }

    public static function createTables(): void
    {
        Schema::dropIfExists('capture_items');
        Schema::dropIfExists('capture_users');

        Schema::create('capture_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('capture_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('password');
        });
    }

    public static function dropTables(): void
    {
        Schema::dropIfExists('capture_items');
        Schema::dropIfExists('capture_users');
    }
}
