<?php

declare(strict_types=1);

namespace Anabil\Tracing\Console;

use Anabil\Tracing\Notifications\DefaultNotificationDispatcher;
use Anabil\Tracing\Notifications\IssueMessageFactory;
use Illuminate\Console\Command;

/**
 * Sends a sample message synchronously to one or all configured channels and prints the
 * per-channel result. Ignores the global switch, channel list and severity thresholds so a
 * setup can be verified before notifications are enabled; credentials are still required.
 */
final class TestNotificationCommand extends Command
{
    /** What each built-in channel needs, for "not configured" messages. */
    private const HINTS = [
        'slack' => 'set TRACING_SLACK_WEBHOOK_URL (tracing.notifications.slack.webhook_url)',
        'telegram' => 'set TRACING_TELEGRAM_BOT_TOKEN and TRACING_TELEGRAM_CHAT_ID (tracing.notifications.telegram)',
        'mail' => 'set TRACING_MAIL_TO (tracing.notifications.mail.to)',
    ];

    /** @var string */
    protected $signature = 'tracing:test-notification {channel? : slack, telegram or mail (default: every configured channel)}';

    /** @var string */
    protected $description = 'Send a test notification to verify the Slack / Telegram / Mail setup';

    public function handle(DefaultNotificationDispatcher $dispatcher, IssueMessageFactory $messages): int
    {
        $requested = $this->argument('channel');
        $requested = is_string($requested) && trim($requested) !== '' ? strtolower(trim($requested)) : null;
        $all = $dispatcher->allChannels();

        if ($requested !== null && ! isset($all[$requested])) {
            $this->components->error("Unknown channel [{$requested}]. Available: ".implode(', ', array_keys($all)).'.');

            return self::FAILURE;
        }

        $names = $requested !== null ? [$requested] : array_keys($all);
        $results = $dispatcher->deliver($messages->sample(), $names, force: true);
        $failed = false;

        foreach ($names as $name) {
            if (! array_key_exists($name, $results)) {
                $this->components->twoColumnDetail($name, '<fg=yellow>not configured</>');
                $failed = $failed || $requested !== null;

                continue;
            }

            $error = $results[$name];
            if ($error === null) {
                $this->components->twoColumnDetail($name, '<fg=green;options=bold>sent</>');

                continue;
            }

            $failed = true;
            $this->components->twoColumnDetail($name, '<fg=red;options=bold>failed</> '.$error->getMessage());
        }

        if ($requested !== null && ! array_key_exists($requested, $results)) {
            $hint = self::HINTS[$requested] ?? "set its credentials under tracing.notifications.{$requested}";
            $this->components->warn("Channel [{$requested}] is not configured: {$hint}.");

            return self::FAILURE;
        }

        if ($results === []) {
            $this->components->warn('No channel is configured. Set the credentials under tracing.notifications ('
                .implode(', ', array_values(self::HINTS)).').');

            return self::FAILURE;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
