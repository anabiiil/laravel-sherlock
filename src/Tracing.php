<?php

declare(strict_types=1);

namespace Anabil\Tracing;

use Anabil\Tracing\Contracts\AiProviderFactory;
use Anabil\Tracing\Contracts\ContextManager;
use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Data\WatchState;
use Anabil\Tracing\Enums\TracingMode;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Main service behind the Tracing facade (user-facing API only).
 * Services are resolved lazily so calling the facade costs nothing when unused.
 */
final class Tracing
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {}

    public function mode(): TracingMode
    {
        return TracingMode::fromConfig($this->config->get('tracing.mode'));
    }

    public function enabled(): bool
    {
        return $this->mode()->isEnabled();
    }

    public function isRecording(): bool
    {
        return $this->contexts()->isRecording();
    }

    /** Attach a tag to the current trace (no-op when not recording). */
    public function tag(string $key, string|int|float|bool|null $value): void
    {
        $this->contexts()->current()->tag($key, $value);
    }

    /**
     * Register a custom AI driver: Tracing::extendAi('name', fn ($app, array $config) => new MyDriver).
     */
    public function extendAi(string $driver, Closure $factory): void
    {
        $this->container->make(AiProviderFactory::class)->extend($driver, $factory);
    }

    public function watch(int $minutes, ?string $routePattern = null, int|string|null $userId = null): WatchState
    {
        return $this->container->make(WatchWindow::class)->start($minutes, $routePattern, $userId);
    }

    public function stopWatching(): void
    {
        $this->container->make(WatchWindow::class)->stop();
    }

    private function contexts(): ContextManager
    {
        return $this->container->make(ContextManager::class);
    }
}
