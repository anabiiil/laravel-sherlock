<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Collectors\CacheCollector;
use Anabil\Sherlock\Collectors\CommandCollector;
use Anabil\Sherlock\Collectors\HttpClientCollector;
use Anabil\Sherlock\Collectors\JobCollector;
use Anabil\Sherlock\Collectors\LogCollector;
use Anabil\Sherlock\Collectors\QueryCollector;
use Anabil\Sherlock\Collectors\RequestCollector;
use Anabil\Sherlock\Console\StatusCommand;
use Anabil\Sherlock\Console\WatchCommand;
use Anabil\Sherlock\Context\ContextManager;
use Anabil\Sherlock\Context\FailureReporter;
use Anabil\Sherlock\Context\UnitLifecycle;
use Anabil\Sherlock\Contracts\Collector;
use Anabil\Sherlock\Contracts\ContextManager as ContextManagerContract;
use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Contracts\WatchWindow;
use Anabil\Sherlock\Enums\SherlockMode;
use Anabil\Sherlock\Recording\CacheWatchWindow;
use Anabil\Sherlock\Recording\Policies\CompositeRecordingPolicy;
use Anabil\Sherlock\Recording\Policies\HeaderTriggerPolicy;
use Anabil\Sherlock\Recording\Policies\IgnorePolicy;
use Anabil\Sherlock\Recording\Policies\LocalPolicy;
use Anabil\Sherlock\Recording\Policies\OffPolicy;
use Anabil\Sherlock\Recording\Policies\ProductionPolicy;
use Anabil\Sherlock\Recording\Policies\WatchWindowPolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Owns capture: ContextManager, collectors, recording policies, watch window, header
 * trigger and the request / job / command lifecycle hooks.
 *
 * mode=off registers nothing at all: no bindings, no listeners, no terminating callback
 * (the core Null Objects stay in place). Only the two artisan commands are registered, in
 * console, so "sherlock:status" still works.
 */
final class CaptureServiceProvider extends ServiceProvider
{
    private bool $enabled = false;

    public function register(): void
    {
        $this->enabled = SherlockMode::fromConfig($this->config()->get('sherlock.mode'))->isEnabled();

        if (! $this->enabled) {
            return;
        }

        $this->registerContext();
        $this->registerPolicies();
        $this->registerCollectors();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([WatchCommand::class, StatusCommand::class]);
        }

        if (! $this->enabled) {
            return;
        }

        try {
            $this->bootCapture();
        } catch (Throwable $e) {
            // Sherlock must never prevent the application from booting.
            $this->app->make(FailureReporter::class)->report($e);
        }
    }

    /**
     * @return list<class-string<Collector>>
     */
    public function collectors(): array
    {
        $capture = $this->config()->get('sherlock.capture', []);
        $capture = is_array($capture) ? $capture : [];
        $on = static fn (string $key, bool $default = true): bool => (bool) ($capture[$key] ?? $default);

        return array_values(array_filter([
            $on('queries') ? QueryCollector::class : null,
            $on('cache') ? CacheCollector::class : null,
            $on('http_client') ? HttpClientCollector::class : null,
            $on('logs') || $on('exceptions') ? LogCollector::class : null,
            $on('requests') ? RequestCollector::class : null,
            $on('jobs') ? JobCollector::class : null,
            $on('commands') ? CommandCollector::class : null,
        ]));
    }

    private function bootCapture(): void
    {
        $collectors = $this->collectors();
        foreach ($collectors as $class) {
            $this->app->make($class)->register();
        }

        $requests = in_array(RequestCollector::class, $collectors, true);
        $lifecycle = $this->app->make(UnitLifecycle::class);

        // After the response is sent (FPM: fastcgi_finish_request() already ran) / after a command.
        $this->app->terminating(static function (Container $app) use ($requests, $lifecycle): void {
            try {
                if ($requests) {
                    $app->make(RequestCollector::class)->terminate($app);
                }
                $lifecycle->terminate();
            } catch (Throwable $e) {
                $lifecycle->failures()->report($e);
            }
        });

        if ($requests) {
            $this->startRequestWhileBooting();
        }
    }

    /**
     * FPM: start the request unit now, with LARAVEL_START as its start, so queries run by
     * providers booting after this one are captured and bootstrap time is measured.
     * Console / Octane / tests start on Routing / RequestReceived instead.
     */
    private function startRequestWhileBooting(): void
    {
        if ($this->app->runningInConsole() || isset($_SERVER['LARAVEL_OCTANE']) || ! $this->app->bound('request')) {
            return;
        }

        $request = $this->app->make('request');
        if (! $request instanceof Request) {
            return;
        }

        $start = defined('LARAVEL_START') ? constant('LARAVEL_START') : null;
        $start = is_float($start) && $start <= microtime(true) ? $start : null;

        $collector = $this->app->make(RequestCollector::class);
        $collector->startFromBoot($request, $start);

        if ($start !== null) {
            $this->app->booted(static fn () => $collector->bootstrapped($start));
        }
    }

    private function registerContext(): void
    {
        $this->app->singleton(ContextManager::class, function (): ContextManager {
            return new ContextManager(
                $this->int('sherlock.capture.max_queries', 1000),
                $this->int('sherlock.capture.max_http_calls', 200),
                $this->int('sherlock.capture.max_logs', 100),
            );
        });
        $this->app->singleton(ContextManagerContract::class, static fn (Application $app): ContextManager => $app->make(ContextManager::class));

        $this->app->singleton(FailureReporter::class, static fn (Application $app): FailureReporter => new FailureReporter(
            $app,
            $app->make(ContextManager::class),
        ));

        $this->app->singleton(UnitLifecycle::class, static fn (Application $app): UnitLifecycle => new UnitLifecycle(
            $app,
            $app->make(ContextManager::class),
            $app->make(FailureReporter::class),
        ));
    }

    private function registerPolicies(): void
    {
        $this->app->singleton(WatchWindow::class, function (Application $app): CacheWatchWindow {
            $store = $this->config()->get('sherlock.watch.cache_store');
            $key = $this->config()->get('sherlock.watch.cache_key');

            return new CacheWatchWindow(
                $app->make('cache'),
                is_string($store) && $store !== '' ? $store : null,
                is_string($key) && $key !== '' ? $key : 'sherlock:watch',
                $this->int('sherlock.watch.max_minutes', 1440),
                $this->int('sherlock.watch.memo_seconds', 5),
                null,
                (bool) $this->config()->get('sherlock.watch.shared_memo', true),
                hash('xxh3', $app->basePath()),
            );
        });

        $this->app->singleton(IgnorePolicy::class, function (): IgnorePolicy {
            $ignore = $this->config()->get('sherlock.ignore', []);

            return IgnorePolicy::fromConfig(is_array($ignore) ? $ignore : []);
        });

        $this->app->singleton(HeaderTriggerPolicy::class, function (): HeaderTriggerPolicy {
            $secret = $this->config()->get('sherlock.header.secret');

            return new HeaderTriggerPolicy(
                is_string($secret) ? $secret : null,
                (bool) $this->config()->get('sherlock.header.enabled', true),
            );
        });

        // Resolved lazily (first unit), so the mode / sample rate are read at that point.
        $this->app->singleton(RecordingPolicy::class, function (Application $app): RecordingPolicy {
            $rate = $this->config()->get('sherlock.sample_rate', 0.0);

            return match (SherlockMode::fromConfig($this->config()->get('sherlock.mode'))) {
                SherlockMode::Off => new OffPolicy,
                SherlockMode::Local => new CompositeRecordingPolicy([new LocalPolicy], $app->make(IgnorePolicy::class)),
                SherlockMode::Production => new CompositeRecordingPolicy(array_values(array_filter([
                    $app->make(HeaderTriggerPolicy::class),
                    // watch.enabled=false: no cache read at all when a unit starts.
                    $this->config()->get('sherlock.watch.enabled', true) ? new WatchWindowPolicy($app->make(WatchWindow::class)) : null,
                    new ProductionPolicy(is_numeric($rate) ? (float) $rate : 0.0),
                ])), $app->make(IgnorePolicy::class)),
            };
        });
    }

    private function registerCollectors(): void
    {
        $this->app->singleton(QueryCollector::class, function (Application $app): QueryCollector {
            $ignored = $this->config()->get('sherlock.ignore.queries', []);

            return new QueryCollector(
                $app->make(Dispatcher::class),
                $app->make(ContextManager::class),
                $app->make(Redactor::class),
                $app->make(SourceLocator::class),
                $app->make(FailureReporter::class),
                (float) $this->int('sherlock.thresholds.slow_query.warning_ms', 100),
                (bool) $this->config()->get('sherlock.capture.source_location', true),
                is_array($ignored) ? $ignored : [],
                AnalysisServiceProvider::explainEnabled($app) ? AnalysisServiceProvider::explainMinQueryMs($app) : null,
            );
        });

        $this->app->singleton(CacheCollector::class, static fn (Application $app): CacheCollector => new CacheCollector(
            $app->make(Dispatcher::class),
            $app->make(ContextManager::class),
            $app->make(FailureReporter::class),
        ));

        $this->app->singleton(HttpClientCollector::class, fn (Application $app): HttpClientCollector => new HttpClientCollector(
            $app->make(Dispatcher::class),
            $app->make(ContextManager::class),
            $app->make(Redactor::class),
            $app->make(SourceLocator::class),
            $app->make(FailureReporter::class),
            (bool) $this->config()->get('sherlock.capture.source_location', true),
        ));

        $this->app->singleton(LogCollector::class, function (Application $app): LogCollector {
            $level = $this->config()->get('sherlock.capture.log_level', 'error');

            return new LogCollector(
                $app->make(Dispatcher::class),
                $app->make(UnitLifecycle::class),
                $app->make(Redactor::class),
                $app->make(SourceLocator::class),
                is_string($level) ? $level : 'error',
                (bool) $this->config()->get('sherlock.capture.logs', true),
                (bool) $this->config()->get('sherlock.capture.exceptions', true),
                $this->int('sherlock.capture.max_stack_frames', 30),
                // Error path only: values the request sent under sensitive keys are scrubbed.
                in_array(RequestCollector::class, $this->collectors(), true)
                    ? static fn (): array => $app->make(RequestCollector::class)->sensitiveInput()
                    : null,
            );
        });

        $this->app->singleton(RequestCollector::class, function (Application $app): RequestCollector {
            $name = $this->config()->get('sherlock.header.name', 'X-Sherlock');

            // The header value is read (one array lookup) and only compared by HeaderTriggerPolicy.
            return new RequestCollector(
                $app->make(Dispatcher::class),
                $app->make(UnitLifecycle::class),
                $app->make(Redactor::class),
                $app,
                is_string($name) && $name !== '' ? $name : null,
            );
        });

        $this->app->singleton(JobCollector::class, function (Application $app): JobCollector {
            return new JobCollector(
                $app->make(Dispatcher::class),
                $app->make(UnitLifecycle::class),
                in_array(LogCollector::class, $this->collectors(), true) ? $app->make(LogCollector::class) : null,
            );
        });

        $this->app->singleton(CommandCollector::class, static fn (Application $app): CommandCollector => new CommandCollector(
            $app->make(Dispatcher::class),
            $app->make(UnitLifecycle::class),
        ));
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config()->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
