<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Anabil\Tracing\Console\DigestCommand;
use Anabil\Tracing\Contracts\DigestService;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Contracts\TraceRepository;
use Anabil\Tracing\Digest\DefaultDigestService;
use Anabil\Tracing\Digest\DigestBuilder;
use Anabil\Tracing\Jobs\SendDigestJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Owns the digest: DigestBuilder, DigestService, the tracing:digest command and its schedule.
 *
 * Nothing here touches the request path. The schedule entry is registered only when the
 * digest is enabled; it runs once a day at "digest.at", checks isDue() (one cache read) and
 * only then queues a SendDigestJob.
 */
final class DigestServiceProvider extends ServiceProvider
{
    public const SCHEDULE_NAME = 'tracing:digest';

    public function register(): void
    {
        $this->app->singleton(DigestBuilder::class, static function (Application $app): DigestBuilder {
            $config = $app->make(Repository::class);
            $limit = $config->get('tracing.digest.limit', 10);
            $name = $config->get('app.name');

            return new DigestBuilder(
                $app->make(TraceRepository::class),
                $app->make(IssueRepository::class),
                is_numeric($limit) ? max(1, (int) $limit) : 10,
                self::dashboardUrl($config),
                is_string($name) && $name !== '' ? $name : 'Laravel',
            );
        });

        $this->app->singleton(DigestService::class, static function (Application $app): DefaultDigestService {
            $config = $app->make(Repository::class);
            $store = $config->get('tracing.digest.cache_store');
            $days = $config->get('tracing.digest.every_days', 1);
            $channels = $config->get('tracing.digest.channels', []);
            $timezone = $config->get('app.timezone');

            return new DefaultDigestService(
                $app->make(DigestBuilder::class),
                $app->make(NotificationDispatcher::class),
                $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                (bool) $config->get('tracing.digest.enabled', false),
                is_numeric($days) ? max(1, (int) $days) : 1,
                is_array($channels) ? array_values(array_filter($channels, static fn (mixed $c): bool => is_string($c) && $c !== '')) : [],
                is_string($timezone) && $timezone !== '' ? $timezone : 'UTC',
            );
        });
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([DigestCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make(Repository::class);

            if (! $config->get('tracing.digest.enabled', false)) {
                return;
            }

            $at = $config->get('tracing.digest.at', '09:00');

            $schedule->call(function (): void {
                $this->app->make(Bus::class)->dispatch(new SendDigestJob);
            })
                ->name(self::SCHEDULE_NAME)
                ->dailyAt(is_string($at) && $at !== '' ? $at : '09:00')
                ->when(fn (): bool => $this->app->make(DigestService::class)->isDue());
        });
    }

    private static function dashboardUrl(Repository $config): ?string
    {
        if (! $config->get('tracing.dashboard.enabled', true)) {
            return null;
        }

        $base = $config->get('tracing.notifications.dashboard_url') ?: $config->get('app.url');
        $path = $config->get('tracing.dashboard.path', 'tracing');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/'.(is_string($path) ? trim($path, '/') : 'tracing');
    }
}
