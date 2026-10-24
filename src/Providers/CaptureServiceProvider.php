<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Anabil\Tracing\Collectors\CacheCollector;
use Anabil\Tracing\Collectors\CommandCollector;
use Anabil\Tracing\Collectors\HttpClientCollector;
use Anabil\Tracing\Collectors\JobCollector;
use Anabil\Tracing\Collectors\LogCollector;
use Anabil\Tracing\Collectors\QueryCollector;
use Anabil\Tracing\Collectors\RequestCollector;
use Anabil\Tracing\Console\StatusCommand;
use Anabil\Tracing\Console\WatchCommand;
use Anabil\Tracing\Context\ContextManager;
use Anabil\Tracing\Context\FailureReporter;
use Anabil\Tracing\Context\UnitLifecycle;
use Anabil\Tracing\Contracts\Collector;
use Anabil\Tracing\Contracts\ContextManager as ContextManagerContract;
use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Enums\TracingMode;
use Anabil\Tracing\Recording\CacheWatchWindow;
use Anabil\Tracing\Recording\Policies\CompositeRecordingPolicy;
use Anabil\Tracing\Recording\Policies\HeaderTriggerPolicy;
use Anabil\Tracing\Recording\Policies\IgnorePolicy;
use Anabil\Tracing\Recording\Policies\LocalPolicy;
use Anabil\Tracing\Recording\Policies\OffPolicy;
use Anabil\Tracing\Recording\Policies\ProductionPolicy;
use Anabil\Tracing\Recording\Policies\WatchWindowPolicy;
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
 * console, so "tracing:status" still works.
 */
final class CaptureServiceProvider extends ServiceProvider
{
    private bool $enabled = false;

    public function register(): void
    {
        $this->enabled = TracingMode::fromConfig($this->config()->get('tracing.mode'))->isEnabled();

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
            // Tracing must never prevent the application from booting.
            $this->app->make(FailureReporter::class)->report($e);
        }
    }

    /**
     * @return list<class-string<Collector>>
     */
    public function collectors(): array
    {
        $capture = $this->config()->get('tracing.capture', []);
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
                $this->int('tracing.capture.max_queries', 1000),
                $this->int('tracing.capture.max_http_calls', 200),
                $this->int('tracing.capture.max_logs', 100),
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
            $store = $this->config()->get('tracing.watch.cache_store');
            $key = $this->config()->get('tracing.watch.cache_key');

            return new CacheWatchWindow(
                $app->make('cache'),
                is_string($store) && $store !== '' ? $store : null,
                is_string($key) && $key !== '' ? $key : 'tracing:watch',
                $this->int('tracing.watch.max_minutes', 1440),
                $this->int('tracing.watch.memo_seconds', 5),
            );
        });

        $this->app->singleton(IgnorePolicy::class, function (): IgnorePolicy {
            $ignore = $this->config()->get('tracing.ignore', []);

            return IgnorePolicy::fromConfig(is_array($ignore) ? $ignore : []);
        });

        $this->app->singleton(HeaderTriggerPolicy::class, function (): HeaderTriggerPolicy {
            $secret = $this->config()->get('tracing.header.secret');

            return new HeaderTriggerPolicy(
                is_string($secret) ? $secret : null,
                (bool) $this->config()->get('tracing.header.enabled', true),
            );
        });

        // Resolved lazily (first unit), so the mode / sample rate are read at that point.
        $this->app->singleton(RecordingPolicy::class, function (Application $app): RecordingPolicy {
            $rate = $this->config()->get('tracing.sample_rate', 0.0);

            return match (TracingMode::fromConfig($this->config()->get('tracing.mode'))) {
                TracingMode::Off => new OffPolicy,
                TracingMode::Local => new CompositeRecordingPolicy([new LocalPolicy], $app->make(IgnorePolicy::class)),
                TracingMode::Production => new CompositeRecordingPolicy([
                    $app->make(HeaderTriggerPolicy::class),
                    new WatchWindowPolicy($app->make(WatchWindow::class)),
                    new ProductionPolicy(is_numeric($rate) ? (float) $rate : 0.0),
                ], $app->make(IgnorePolicy::class)),
            };
        });
    }

    private function registerCollectors(): void
    {
        $this->app->singleton(QueryCollector::class, function (Application $app): QueryCollector {
            $ignored = $this->config()->get('tracing.ignore.queries', []);

            return new QueryCollector(
                $app->make(Dispatcher::class),
                $app->make(ContextManager::class),
                $app->make(Redactor::class),
                $app->make(SourceLocator::class),
                $app->make(FailureReporter::class),
                (float) $this->int('tracing.thresholds.slow_query.warning_ms', 100),
                (bool) $this->config()->get('tracing.capture.source_location', true),
                is_array($ignored) ? $ignored : [],
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
            (bool) $this->config()->get('tracing.capture.source_location', true),
        ));

        $this->app->singleton(LogCollector::class, function (Application $app): LogCollector {
            $level = $this->config()->get('tracing.capture.log_level', 'error');

            return new LogCollector(
                $app->make(Dispatcher::class),
                $app->make(UnitLifecycle::class),
                $app->make(Redactor::class),
                $app->make(SourceLocator::class),
                is_string($level) ? $level : 'error',
                (bool) $this->config()->get('tracing.capture.logs', true),
                (bool) $this->config()->get('tracing.capture.exceptions', true),
                $this->int('tracing.capture.max_stack_frames', 30),
            );
        });

        $this->app->singleton(RequestCollector::class, function (Application $app): RequestCollector {
            $name = $this->config()->get('tracing.header.name', 'X-Tracing');

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
