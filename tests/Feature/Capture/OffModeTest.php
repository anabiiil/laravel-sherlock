<?php

declare(strict_types=1);

use Anabil\Tracing\Context\UnitLifecycle;
use Anabil\Tracing\Contracts\ContextManager;
use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Facades\Tracing;
use Anabil\Tracing\Providers\CaptureServiceProvider;
use Anabil\Tracing\Support\Null\NullContextManager;
use Anabil\Tracing\Support\Null\NullWatchWindow;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\Facades\DB;

const CAPTURE_EVENTS = [
    QueryExecuted::class, CacheHit::class, CacheMissed::class, KeyWritten::class, RetrievingKey::class,
    RequestSending::class, ResponseReceived::class, MessageLogged::class, JobProcessing::class,
    JobProcessed::class, CommandStarting::class, CommandFinished::class, Routing::class,
    RouteMatched::class, RequestHandled::class,
];

/**
 * Terminating callbacks registered by the capture provider (closures scoped to it).
 */
function captureTerminatingCallbacks(): int
{
    $property = new ReflectionProperty(app(), 'terminatingCallbacks');
    $count = 0;
    foreach ($property->getValue(app()) as $callback) {
        if ($callback instanceof Closure && (new ReflectionFunction($callback))->getClosureScopeClass()?->getName() === CaptureServiceProvider::class) {
            $count++;
        }
    }

    return $count;
}

/**
 * Listeners registered by this package (closures scoped to / bound on an Anabil\Tracing class).
 *
 * @return list<string>
 */
function capturePackageListeners(): array
{
    $found = [];
    $listeners = (new ReflectionProperty(app('events'), 'listeners'))->getValue(app('events'));

    foreach ($listeners as $event => $list) {
        foreach ($list as $listener) {
            $owner = null;
            if ($listener instanceof Closure) {
                $reflection = new ReflectionFunction($listener);
                $owner = $reflection->getClosureThis() !== null
                    ? $reflection->getClosureThis()::class
                    : $reflection->getClosureScopeClass()?->getName();
            } elseif (is_array($listener) && is_object($listener[0] ?? null)) {
                $owner = $listener[0]::class;
            } elseif (is_string($listener)) {
                $owner = $listener;
            }

            if (is_string($owner) && str_starts_with($owner, 'Anabil\\Tracing\\')) {
                $found[] = $event.' => '.$owner;
            }
        }
    }

    return $found;
}

function captureBootWithMode(string $mode): void
{
    $_ENV['TRACING_MODE'] = $_SERVER['TRACING_MODE'] = $mode;
    putenv('TRACING_MODE='.$mode);
    test()->refreshApplication();
}

afterEach(function (): void {
    unset($_ENV['TRACING_MODE'], $_SERVER['TRACING_MODE']);
    putenv('TRACING_MODE');
});

it('registers no listener, binding or terminating callback when mode is off', function (): void {
    captureBootWithMode('off');

    expect(config('tracing.mode'))->toBe('off');

    // The framework itself listens to some of these events; none of the listeners may be ours.
    expect(capturePackageListeners())->toBe([])
        ->and(app('events')->hasListeners(QueryExecuted::class))->toBeFalse()
        ->and(app('events')->hasListeners(Routing::class))->toBeFalse();

    expect(captureTerminatingCallbacks())->toBe(0)
        ->and(app(ContextManager::class))->toBeInstanceOf(NullContextManager::class)
        ->and(app(WatchWindow::class))->toBeInstanceOf(NullWatchWindow::class)
        ->and(app()->bound(UnitLifecycle::class))->toBeFalse()
        ->and(Tracing::isRecording())->toBeFalse()
        ->and(Tracing::pause(fn () => DB::select('select 7 as n')[0]->n))->toBe(7);
});

it('registers the collectors when enabled', function (): void {
    captureBootWithMode('production');

    foreach ([QueryExecuted::class, MessageLogged::class, JobProcessing::class, Routing::class] as $event) {
        expect(app('events')->hasListeners($event))->toBeTrue();
    }
    expect(count(capturePackageListeners()))->toBeGreaterThanOrEqual(count(CAPTURE_EVENTS));

    expect(captureTerminatingCallbacks())->toBe(1);
});

it('keeps tracing:status usable when off', function (): void {
    captureBootWithMode('off');

    $this->artisan('tracing:status')->expectsOutputToContain('off')->assertSuccessful();
    $this->artisan('tracing:watch')->assertFailed();
});
