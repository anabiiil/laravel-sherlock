<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Console\DigestCommand;
use Anabil\Sherlock\Contracts\DigestService;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Contracts\TraceRepository;
use Anabil\Sherlock\Digest\DefaultDigestService;
use Anabil\Sherlock\Digest\DigestBuilder;
use Anabil\Sherlock\Jobs\SendDigestJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Owns the digest: DigestBuilder, DigestService, the sherlock:digest command and its schedule.
 *
 * Nothing here touches the request path. The schedule entry is registered only when the
 * digest is enabled; it runs once a day at "digest.at", checks isDue() (one cache read) and
 * only then queues a SendDigestJob.
 */
final class DigestServiceProvider extends ServiceProvider
{
    public const SCHEDULE_NAME = 'sherlock:digest';

    public function register(): void
    {
        $this->app->singleton(DigestBuilder::class, static function (Application $app): DigestBuilder {
            $config = $app->make(Repository::class);
            $limit = $config->get('sherlock.digest.limit', 10);
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
            $store = $config->get('sherlock.digest.cache_store');
            $days = $config->get('sherlock.digest.every_days', 1);
            $channels = $config->get('sherlock.digest.channels', []);
            $timezone = $config->get('app.timezone');

            return new DefaultDigestService(
                $app->make(DigestBuilder::class),
                $app->make(NotificationDispatcher::class),
                $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                (bool) $config->get('sherlock.digest.enabled', false),
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

            if (! $config->get('sherlock.digest.enabled', false)) {
                return;
            }

            $at = $config->get('sherlock.digest.at', '09:00');

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
        if (! $config->get('sherlock.dashboard.enabled', true)) {
            return null;
        }

        $base = $config->get('sherlock.notifications.dashboard_url') ?: $config->get('app.url');
        $path = $config->get('sherlock.dashboard.path', 'sherlock');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/'.(is_string($path) ? trim($path, '/') : 'sherlock');
    }
}
