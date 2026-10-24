<?php

declare(strict_types=1);

namespace Anabil\Tracing\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Anabil\Tracing\Enums\TracingMode mode()
 * @method static bool enabled()
 * @method static bool isRecording()
 * @method static void tag(string $key, string|int|float|bool|null $value)
 * @method static void extendAi(string $driver, \Closure $factory)
 * @method static \Anabil\Tracing\Data\WatchState watch(int $minutes, ?string $routePattern = null, int|string|null $userId = null)
 * @method static void stopWatching()
 * @method static \Anabil\Tracing\Data\WatchState|null watching()
 * @method static mixed pause(\Closure $callback)
 *
 * @see \Anabil\Tracing\Tracing
 */
final class Tracing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Anabil\Tracing\Tracing::class;
    }
}
