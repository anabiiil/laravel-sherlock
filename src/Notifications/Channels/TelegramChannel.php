<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Notifications\Channels;

use Anabil\Sherlock\Contracts\NotificationChannel;
use Anabil\Sherlock\Data\NotificationMessage;
use Anabil\Sherlock\Notifications\Formatters\TelegramFormatter;
use Anabil\Sherlock\Notifications\NotificationFailed;
use Anabil\Sherlock\Notifications\NotificationPolicy;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Telegram Bot API sendMessage (notifications.telegram.bot_token + chat_id), parse_mode=HTML.
 */
final class TelegramChannel implements NotificationChannel
{
    public const DEFAULT_API_URL = 'https://api.telegram.org';

    public function __construct(
        private readonly NotificationPolicy $policy,
        private readonly TelegramFormatter $formatter = new TelegramFormatter,
    ) {}

    public function name(): string
    {
        return 'telegram';
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null && $this->chatId() !== null;
    }

    public function send(NotificationMessage $message): void
    {
        $token = $this->token();
        $chatId = $this->chatId();
        if ($token === null || $chatId === null) {
            throw new NotificationFailed('telegram: bot_token / chat_id are not configured');
        }

        $timeout = $this->policy->timeout();

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->asJson()
                ->post($this->apiUrl().'/bot'.$token.'/sendMessage', ['chat_id' => $chatId] + $this->formatter->format($message));
        } catch (Throwable $e) {
            throw NotificationFailed::from('telegram', $e, [$token]);
        }

        $ok = $response->json('ok');
        if (! $response->successful() || $ok === false) {
            $description = $response->json('description');

            throw NotificationFailed::response(
                'telegram',
                $response->status(),
                is_string($description) ? NotificationFailed::scrub($description, [$token]) : null,
            );
        }
    }

    private function token(): ?string
    {
        $token = $this->policy->channelConfig('telegram')['bot_token'] ?? null;

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    private function chatId(): ?string
    {
        $chatId = $this->policy->channelConfig('telegram')['chat_id'] ?? null;

        if (is_int($chatId)) {
            return (string) $chatId;
        }

        return is_string($chatId) && trim($chatId) !== '' ? trim($chatId) : null;
    }

    private function apiUrl(): string
    {
        $url = $this->policy->channelConfig('telegram')['api_url'] ?? null;

        return is_string($url) && trim($url) !== '' ? rtrim(trim($url), '/') : self::DEFAULT_API_URL;
    }
}
