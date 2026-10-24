<?php

declare(strict_types=1);

use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;
use Anabil\Tracing\Recording\CacheWatchWindow;
use Anabil\Tracing\Recording\Policies\WatchWindowPolicy;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;

/**
 * Cache factory over one array store that counts get() calls.
 */
final class CaptureCountingCacheFactory implements Factory
{
    public int $gets = 0;

    public bool $fail = false;

    public readonly Repository $repository;

    public function __construct()
    {
        $factory = $this;
        $this->repository = new class(new ArrayStore, $factory) extends Repository
        {
            public function __construct(ArrayStore $store, private readonly CaptureCountingCacheFactory $factory)
            {
                parent::__construct($store);
            }

            public function get($key, $default = null): mixed
            {
                $this->factory->gets++;
                if ($this->factory->fail) {
                    throw new RuntimeException('redis down');
                }

                return parent::get($key, $default);
            }
        };
    }

    public function store($name = null): RepositoryContract
    {
        return $this->repository;
    }
}

function captureWindow(CaptureCountingCacheFactory $cache, float &$now, int $memo = 5): CacheWatchWindow
{
    return new CacheWatchWindow($cache, null, 'tracing:watch', 60, $memo, static function () use (&$now): float {
        return $now;
    });
}

function watchCandidate(string $path, int|string|null $user = null, ?string $route = null): EntryCandidate
{
    return new EntryCandidate(EntryType::Request, $path, 'GET', $path, $route, null, $user);
}

it('starts a window that expires on its own', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $window = captureWindow($cache, $now, 0);

    $state = $window->start(10);

    expect($state->remainingSeconds((new DateTimeImmutable)->setTimestamp((int) $now)))->toBe(600)
        ->and($cache->repository->getStore()->get('tracing:watch'))->toBe($state->toArray())
        ->and($window->current())->not->toBeNull()
        ->and($window->matches(new EntryCandidate(EntryType::Job, 'App\Jobs\X')))->toBeTrue();

    $now += 601;

    expect($window->current())->toBeNull()
        ->and($window->matches(watchCandidate('api/orders')))->toBeFalse();
});

it('clamps the duration to the configured maximum', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();

    $state = captureWindow($cache, $now)->start(10_000);

    expect($state->expiresAt->getTimestamp() - $state->startedAt->getTimestamp())->toBe(3600);
});

it('stops a window early', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $window = captureWindow($cache, $now, 0);
    $window->start(10);
    $window->stop();

    expect($window->current())->toBeNull()
        ->and($cache->repository->getStore()->get('tracing:watch'))->toBeNull();
});

it('reads the cache at most once per memo period', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $writer = captureWindow($cache, $now);
    $writer->start(10);

    $reader = captureWindow($cache, $now); // another process
    $cache->gets = 0;

    for ($i = 0; $i < 100; $i++) {
        $reader->matches(watchCandidate('api/orders'));
    }
    expect($cache->gets)->toBe(1);

    $now += 5;
    $reader->current();
    expect($cache->gets)->toBe(2);
});

it('treats a failing cache store as no window', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $window = captureWindow($cache, $now);
    $cache->fail = true;

    expect($window->current())->toBeNull()
        ->and($window->matches(watchCandidate('api')))->toBeFalse();
});

it('filters by route pattern on the path or the route name', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $window = captureWindow($cache, $now);
    $window->start(10, '/api/orders*');

    expect($window->matches(watchCandidate('api/orders/15')))->toBeTrue()
        ->and($window->matches(watchCandidate('/api/orders')))->toBeTrue()
        ->and($window->matches(watchCandidate('api/users')))->toBeFalse()
        ->and($window->matches(new EntryCandidate(EntryType::Command, 'app:sync')))->toBeFalse();

    $window->start(10, 'orders.*');
    expect($window->matches(watchCandidate('x', route: 'orders.show')))->toBeTrue();
});

it('filters by user, treating a not yet resolved user as a potential match', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $window = captureWindow($cache, $now);
    $window->start(10, null, 15);

    expect($window->matches(watchCandidate('api', 15)))->toBeTrue()
        ->and($window->matches(watchCandidate('api', '15')))->toBeTrue()
        ->and($window->matches(watchCandidate('api', 16)))->toBeFalse()
        ->and($window->matches(watchCandidate('api')))->toBeTrue()
        ->and($window->matches(new EntryCandidate(EntryType::Job, 'App\Jobs\X')))->toBeFalse();
});

it('drives the watch window policy', function (): void {
    $cache = new CaptureCountingCacheFactory;
    $now = (float) time();
    $window = captureWindow($cache, $now);
    $policy = new WatchWindowPolicy($window);

    expect($policy->decide(watchCandidate('api'))->capture)->toBeFalse();

    $window->start(5);
    $decision = $policy->decide(watchCandidate('api'));

    expect($decision->full)->toBeTrue()
        ->and($decision->reasons)->toBe([RecordReason::Watch]);
});
