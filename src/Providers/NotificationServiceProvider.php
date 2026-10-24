<?php

declare(strict_types=1);

namespace Anabil\Tracing\Providers;

use Anabil\Tracing\Console\TestNotificationCommand;
use Anabil\Tracing\Contracts\IssueRepository;
use Anabil\Tracing\Contracts\NotificationChannel;
use Anabil\Tracing\Contracts\NotificationDispatcher;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Events\IssueRecurred;
use Anabil\Tracing\Notifications\Channels\MailChannel;
use Anabil\Tracing\Notifications\Channels\SlackChannel;
use Anabil\Tracing\Notifications\Channels\TelegramChannel;
use Anabil\Tracing\Notifications\DefaultNotificationDispatcher;
use Anabil\Tracing\Notifications\IssueMessageFactory;
use Anabil\Tracing\Notifications\Listeners\RecordRepeatedIssue;
use Anabil\Tracing\Notifications\NotificationPolicy;
use Anabil\Tracing\Notifications\Throttler;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Owns notifications: channels (slack, telegram, mail), formatters, throttler, NotificationDispatcher.
 *
 * Custom channels: implement NotificationChannel and tag the binding with self::CHANNEL_TAG,
 * then list its name() in tracing.notifications.channels.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public const CHANNEL_TAG = 'tracing.notification_channels';

    public function register(): void
    {
        $this->app->singleton(NotificationPolicy::class);
        $this->app->singleton(IssueMessageFactory::class, static fn (Application $app): IssueMessageFactory => new IssueMessageFactory(
            $app->make(NotificationPolicy::class),
            $app->make(Repository::class),
            $app,
        ));

        $this->app->singleton(SlackChannel::class);
        $this->app->singleton(TelegramChannel::class);
        $this->app->singleton(MailChannel::class);
        $this->app->tag([SlackChannel::class, TelegramChannel::class, MailChannel::class], self::CHANNEL_TAG);

        $this->app->singleton(DefaultNotificationDispatcher::class, static function (Application $app): DefaultNotificationDispatcher {
            /** @var iterable<NotificationChannel> $channels */
            $channels = $app->tagged(self::CHANNEL_TAG);

            return new DefaultNotificationDispatcher(
                $app->make(NotificationPolicy::class),
                $app->make(IssueRepository::class),
                $app->make(IssueMessageFactory::class),
                $app->make(Redactor::class),
                $app->make(Cache::class),
                $channels,
                $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
            );
        });
        $this->app->singleton(
            NotificationDispatcher::class,
            static fn (Application $app): NotificationDispatcher => $app->make(DefaultNotificationDispatcher::class),
        );

        // Not a singleton: resolves the current bus / cache (Bus::fake, Cache swaps).
        $this->app->bind(Throttler::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'tracing');

        $this->app->make(Events::class)->listen(IssueRecurred::class, [RecordRepeatedIssue::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([TestNotificationCommand::class]);
        }
    }
}
