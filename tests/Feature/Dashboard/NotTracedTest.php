<?php

declare(strict_types=1);

use Anabil\Tracing\Tests\Feature\Capture\Support\Capture;
use Anabil\Tracing\Tests\Feature\Dashboard\DashboardFixtures as D;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('tracing.mode', 'local');
    D::allowAccess();

    Route::get('/dashboard-control', fn (): string => 'ok');
});

it('never traces dashboard requests while normal requests are traced', function (): void {
    $recorder = Capture::fakeRecorder();
    D::seed();

    $this->get('/dashboard-control')->assertOk();
    expect($recorder->traces)->toHaveCount(1);

    $this->get('/tracing')->assertOk();
    $this->get('/tracing/issues')->assertOk();
    $this->get('/tracing/traces')->assertOk();
    $this->get('/tracing/queries')->assertOk();

    expect($recorder->traces)->toHaveCount(1)
        ->and($recorder->last()->name)->toBe('GET /dashboard-control');
});

it('excludes the dashboard by route name too (custom path / domain)', function (): void {
    expect(config('tracing.ignore.routes'))->toContain('tracing.*');

    // Even with the path patterns removed, the route-name rule keeps the dashboard untraced.
    config()->set('tracing.ignore.paths', []);
    app()->forgetInstance(Anabil\Tracing\Recording\Policies\IgnorePolicy::class);
    app()->forgetInstance(Anabil\Tracing\Contracts\RecordingPolicy::class);
    $recorder = Capture::fakeRecorder();

    $this->get('/tracing/traces')->assertOk();
    $this->get('/dashboard-control')->assertOk();

    expect($recorder->traces)->toHaveCount(1)
        ->and($recorder->last()->name)->toBe('GET /dashboard-control');
});
