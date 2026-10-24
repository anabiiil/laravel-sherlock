<?php

declare(strict_types=1);

use Anabil\Sherlock\Tests\Feature\Capture\Support\Capture;
use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('sherlock.mode', 'local');
    D::allowAccess();

    Route::get('/dashboard-control', fn (): string => 'ok');
});

it('never traces dashboard requests while normal requests are traced', function (): void {
    $recorder = Capture::fakeRecorder();
    D::seed();

    $this->get('/dashboard-control')->assertOk();
    expect($recorder->traces)->toHaveCount(1);

    $this->get('/sherlock')->assertOk();
    $this->get('/sherlock/issues')->assertOk();
    $this->get('/sherlock/traces')->assertOk();
    $this->get('/sherlock/queries')->assertOk();

    expect($recorder->traces)->toHaveCount(1)
        ->and($recorder->last()->name)->toBe('GET /dashboard-control');
});

it('excludes the dashboard by route name too (custom path / domain)', function (): void {
    expect(config('sherlock.ignore.routes'))->toContain('sherlock.*');

    // Even with the path patterns removed, the route-name rule keeps the dashboard untraced.
    config()->set('sherlock.ignore.paths', []);
    app()->forgetInstance(Anabil\Sherlock\Recording\Policies\IgnorePolicy::class);
    app()->forgetInstance(Anabil\Sherlock\Contracts\RecordingPolicy::class);
    $recorder = Capture::fakeRecorder();

    $this->get('/sherlock/traces')->assertOk();
    $this->get('/dashboard-control')->assertOk();

    expect($recorder->traces)->toHaveCount(1)
        ->and($recorder->last()->name)->toBe('GET /dashboard-control');
});
