<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Providers;

use Anabil\Sherlock\Console\TestNotificationCommand;
use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Contracts\NotificationChannel;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Events\IssueRecurred;
use Anabil\Sherlock\Notifications\Channels\MailChannel;
use Anabil\Sherlock\Notifications\Channels\SlackChannel;
use Anabil\Sherlock\Notifications\Channels\TelegramChannel;
use Anabil\Sherlock\Notifications\DefaultNotificationDispatcher;
use Anabil\Sherlock\Notifications\IssueMessageFactory;
use Anabil\Sherlock\Notifications\Listeners\RecordRepeatedIssue;
use Anabil\Sherlock\Notifications\NotificationPolicy;
use Anabil\Sherlock\Notifications\Throttler;
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
 * then list its name() in sherlock.notifications.channels.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public const CHANNEL_TAG = 'sherlock.notification_channels';

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
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'sherlock');

        $this->app->make(Events::class)->listen(IssueRecurred::class, [RecordRepeatedIssue::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([TestNotificationCommand::class]);
        }
    }
}
