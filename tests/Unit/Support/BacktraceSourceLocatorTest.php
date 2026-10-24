<?php

declare(strict_types=1);

use Anabil\Tracing\Support\BacktraceSourceLocator;

beforeEach(function (): void {
    $this->locator = new BacktraceSourceLocator('/var/www/app', 30, ['app/Support/Ignored'], '/var/www/app/vendor/anabil/laravel-tracing/src');
});

it('returns the first application frame with a relative path', function (): void {
    $frames = [
        ['file' => '/var/www/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 800, 'function' => 'run'],
        ['file' => '/var/www/app/vendor/anabil/laravel-tracing/src/Collectors/QueryCollector.php', 'line' => 10, 'function' => 'handle'],
        ['file' => '/var/www/app/app/Http/Controllers/OrderController.php', 'line' => 42, 'function' => 'get', 'class' => 'Illuminate\Database\Eloquent\Builder'],
        ['file' => '/var/www/app/vendor/laravel/framework/src/Illuminate/Routing/Controller.php', 'line' => 54, 'function' => 'index', 'class' => 'App\Http\Controllers\OrderController'],
    ];

    $location = $this->locator->locate($frames);

    expect($location)->not->toBeNull()
        ->and($location->file)->toBe('app/Http/Controllers/OrderController.php')
        ->and($location->line)->toBe(42)
        ->and($location->class)->toBe('App\Http\Controllers\OrderController')
        ->and($location->function)->toBe('index')
        ->and((string) $location)->toBe('app/Http/Controllers/OrderController.php:42');
});

it('skips vendor, package, ignored paths and entry scripts', function (): void {
    $frames = [
        ['file' => '/var/www/app/vendor/foo/bar.php', 'line' => 1],
        ['file' => '/var/www/app/app/Support/Ignored/Helper.php', 'line' => 2],
        ['file' => '/var/www/app/public/index.php', 'line' => 3],
        ['file' => '/var/www/app/artisan', 'line' => 4],
        ['function' => '{closure}'],
        ['file' => '/elsewhere/file.php', 'line' => 5],
    ];

    expect($this->locator->locate($frames))->toBeNull();
});

it('handles windows separators', function (): void {
    $locator = new BacktraceSourceLocator('C:\\www\\app', 30, [], 'C:\\www\\app\\vendor\\pkg\\src');

    $location = $locator->locate([['file' => 'C:\\www\\app\\app\\Models\\User.php', 'line' => 7]]);

    expect($location?->file)->toBe('app/Models/User.php');
});

it('makes paths relative', function (): void {
    expect($this->locator->relativePath('/var/www/app/app/Models/User.php'))->toBe('app/Models/User.php')
        ->and($this->locator->relativePath('/other/place.php'))->toBe('/other/place.php');
});

it('normalizes frames without arguments and with a limit', function (): void {
    $frames = [
        ['file' => '/var/www/app/app/A.php', 'line' => 1, 'function' => 'a', 'class' => 'A', 'args' => ['secret']],
        ['function' => 'internal'],
        ['file' => '/var/www/app/app/B.php', 'line' => 2, 'function' => 'b'],
        ['file' => '/var/www/app/app/C.php', 'line' => 3],
    ];

    $result = $this->locator->frames($frames, 2);

    expect($result)->toBe([
        ['file' => 'app/A.php', 'line' => 1, 'class' => 'A', 'function' => 'a'],
        ['file' => 'app/B.php', 'line' => 2, 'class' => null, 'function' => 'b'],
    ]);
});

it('captures a real backtrace when no frames are given', function (): void {
    $locator = new BacktraceSourceLocator(dirname(__DIR__, 3), 30, [], dirname(__DIR__, 3).'/src');

    $location = $locator->locate();

    expect($location)->not->toBeNull()
        ->and($location->file)->toBe('tests/Unit/Support/BacktraceSourceLocatorTest.php');
});
