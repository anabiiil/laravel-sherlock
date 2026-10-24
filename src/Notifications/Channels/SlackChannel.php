<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications\Channels;

use Anabil\Tracing\Contracts\NotificationChannel;
use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Notifications\Formatters\SlackFormatter;
use Anabil\Tracing\Notifications\NotificationFailed;
use Anabil\Tracing\Notifications\NotificationPolicy;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Slack incoming webhook (notifications.slack.webhook_url).
 */
final class SlackChannel implements NotificationChannel
{
    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly SlackFormatter $formatter = new SlackFormatter,
    ) {}

    public function name(): string
    {
        return 'slack';
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl() !== null;
    }

    public function send(NotificationMessage $message): void
    {
        $url = $this->webhookUrl();
        if ($url === null) {
            throw new NotificationFailed('slack: webhook_url is not configured');
        }

        $config = $this->policy->channelConfig('slack');
        $timeout = $this->policy->timeout();

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->asJson()
                ->post($url, $this->formatter->format($message, $config));
        } catch (Throwable $e) {
            throw NotificationFailed::from('slack', $e, [$url]);
        }

        if (! $response->successful()) {
            throw NotificationFailed::response('slack', $response->status(), NotificationFailed::scrub($response->body(), [$url]));
        }
    }

    private function webhookUrl(): ?string
    {
        $url = $this->policy->channelConfig('slack')['webhook_url'] ?? null;

        return is_string($url) && preg_match('#^https?://\S+$#i', trim($url)) === 1 ? trim($url) : null;
    }
}
