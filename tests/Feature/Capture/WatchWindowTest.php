<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Facades\Sherlock;
use Anabil\Sherlock\Recording\CacheWatchWindow;
use Anabil\Sherlock\Tests\Feature\Capture\Support\Capture;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('sherlock.mode', 'production');
    config()->set('sherlock.sample_rate', 0.0);

    $this->recorder = Capture::fakeRecorder();

    Route::get('/api/orders/{id}', fn (string $id) => DB::select('select ? as id', [$id]));
    Route::get('/other', fn () => DB::select('select 1'));
});

it('binds the cache-backed watch window', function (): void {
    expect(app(WatchWindow::class))->toBeInstanceOf(CacheWatchWindow::class);
});

it('fully traces matching requests while a watch window is active', function (): void {
    Sherlock::watch(10, 'api/orders*');

    $this->get('/api/orders/7')->assertOk();
    $this->get('/other')->assertOk();

    [$watched, $other] = $this->recorder->traces;

    expect($watched->full)->toBeTrue()
        ->and($watched->recordReasons)->toBe([RecordReason::Watch])
        ->and($watched->queries[0]->bindings)->toBe(['7'])
        ->and($other->full)->toBeFalse()
        ->and($other->recordReasons)->toBe([])
        ->and(Sherlock::watching()?->routePattern)->toBe('api/orders*');
});

it('stops the watch window early', function (): void {
    Sherlock::watch(10);
    Sherlock::stopWatching();

    $this->get('/other')->assertOk();

    expect($this->recorder->last()->full)->toBeFalse()
        ->and(Sherlock::watching())->toBeNull();
});

it('drops the watch reason at terminate when the user filter does not match', function (): void {
    Sherlock::watch(10, null, 99);

    $this->actingAs(new GenericUser(['id' => 5]))->get('/other')->assertOk();
    $this->actingAs(new GenericUser(['id' => 99]))->get('/other')->assertOk();

    [$otherUser, $watchedUser] = $this->recorder->traces;

    expect($otherUser->userId)->toBe(5)
        ->and($otherUser->recordReasons)->toBe([])
        ->and($watchedUser->userId)->toBe(99)
        ->and($watchedUser->recordReasons)->toBe([RecordReason::Watch]);
});

it('does not resolve a user that no guard resolved', function (): void {
    $this->get('/other')->assertOk();

    expect($this->recorder->last()->userId)->toBeNull();
});

it('starts and stops the window from artisan', function (): void {
    $this->artisan('sherlock:watch', ['--minutes' => 15, '--route' => 'api/*', '--user' => '42'])
        ->expectsOutputToContain('Full tracing on until')
        ->assertSuccessful();

    $state = app(WatchWindow::class)->current();
    expect($state)->not->toBeNull()
        ->and($state->routePattern)->toBe('api/*')
        ->and($state->userId)->toBe(42)
        ->and($state->remainingSeconds())->toBeGreaterThan(14 * 60);

    $this->artisan('sherlock:watch', ['--stop' => true])->assertSuccessful();
    expect(app(WatchWindow::class)->current())->toBeNull();

    $this->artisan('sherlock:watch', ['--minutes' => 0])->assertFailed();
});

it('prints the status', function (): void {
    config()->set('sherlock.notifications.channels', ['slack', 'telegram']);
    config()->set('sherlock.notifications.slack.webhook_url', 'https://hooks.slack.test/x');
    Sherlock::watch(5, 'api/*');

    $this->artisan('sherlock:status')
        ->expectsOutputToContain('production')
        ->expectsOutputToContain('active')
        ->expectsOutputToContain('slack, telegram')
        ->expectsOutputToContain('missing settings')
        ->expectsOutputToContain('anthropic')
        ->assertSuccessful();
});
