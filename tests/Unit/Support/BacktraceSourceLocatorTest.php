<?php

declare(strict_types=1);

use Anabil\Sherlock\Support\BacktraceSourceLocator;

beforeEach(function (): void {
    $this->locator = new BacktraceSourceLocator('/var/www/app', 30, ['app/Support/Ignored'], '/var/www/app/vendor/anabiiil/laravel-sherlock/src');
});

it('returns the first application frame with a relative path', function (): void {
    $frames = [
        ['file' => '/var/www/app/vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 800, 'function' => 'run'],
        ['file' => '/var/www/app/vendor/anabiiil/laravel-sherlock/src/Collectors/QueryCollector.php', 'line' => 10, 'function' => 'handle'],
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

it('never keeps an absolute path in PHP 8.4 closure names or anonymous class names', function (): void {
    $frames = [
        ['file' => '/var/www/app/routes/web.php', 'line' => 23, 'function' => 'first', 'class' => 'Illuminate\Database\Query\Builder'],
        ['file' => '/var/www/app/vendor/laravel/framework/src/Illuminate/Routing/Route.php', 'line' => 205, 'function' => '{closure:/var/www/app/routes/web.php:21}', 'class' => 'Illuminate\Routing\RouteFileRegistrar'],
    ];

    $location = $this->locator->locate($frames);

    expect($location?->function)->toBe('{closure:routes/web.php:21}')
        ->and($location?->file)->toBe('routes/web.php');

    expect($this->locator->cleanName('{closure:App\Http\Controllers\SmokeController::closureNplusone():100}'))
        ->toBe('{closure:App\Http\Controllers\SmokeController::closureNplusone():100}')
        ->and($this->locator->cleanName('{closure:{closure:/var/www/app/app/Jobs/Sync.php:10}:12}'))->toBe('{closure:{closure:app/Jobs/Sync.php:10}:12}')
        ->and($this->locator->cleanName('{closure:/somewhere/else/helpers.php:5}'))->toBe('{closure:helpers.php:5}')
        ->and($this->locator->cleanName('{closure}'))->toBe('{closure}')
        ->and($this->locator->cleanName('index'))->toBe('index')
        ->and($this->locator->cleanName("class@anonymous\0/var/www/app/app/Foo.php:3$0"))->toBe('class@anonymous');

    $windows = new BacktraceSourceLocator('C:\\www\\app', 30, [], 'C:\\www\\app\\vendor\\pkg\\src');
    expect($windows->cleanName('{closure:C:\\www\\app\\routes\\web.php:9}'))->toBe('{closure:routes/web.php:9}');

    $frames = $this->locator->frames([
        ['file' => '/var/www/app/routes/web.php', 'line' => 30, 'function' => '{closure:/var/www/app/routes/web.php:28}'],
    ]);
    expect($frames[0]['function'])->toBe('{closure:routes/web.php:28}');
});
