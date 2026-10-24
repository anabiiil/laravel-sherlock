<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Anabil\Sherlock\Enums\SherlockMode mode()
 * @method static bool enabled()
 * @method static bool isRecording()
 * @method static void tag(string $key, string|int|float|bool|null $value)
 * @method static void extendAi(string $driver, \Closure $factory)
 * @method static \Anabil\Sherlock\Data\WatchState watch(int $minutes, ?string $routePattern = null, int|string|null $userId = null)
 * @method static void stopWatching()
 * @method static \Anabil\Sherlock\Data\WatchState|null watching()
 * @method static mixed pause(\Closure $callback)
 * @method static void recordLazyLoad(\Illuminate\Database\Eloquent\Model|string $model, string $relation)
 *
 * @see \Anabil\Sherlock\Sherlock
 */
final class Sherlock extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Anabil\Sherlock\Sherlock::class;
    }
}
