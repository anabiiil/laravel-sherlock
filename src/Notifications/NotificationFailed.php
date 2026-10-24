<?php

declare(strict_types=1);

namespace Anabil\Tracing\Notifications;

use RuntimeException;
use Throwable;

/**
 * A channel could not deliver a message. Messages never contain webhook URLs or bot tokens.
 */
final class NotificationFailed extends RuntimeException
{
    /**
     * @param  list<string>  $secrets  values scrubbed from the message (webhook URL, bot token...)
     */
    public static function from(string $channel, Throwable $previous, array $secrets = []): self
    {
        return new self($channel.': '.self::scrub($previous->getMessage(), $secrets), 0, $previous);
    }

    public static function response(string $channel, int $status, ?string $detail = null): self
    {
        $detail = $detail !== null ? trim(mb_substr($detail, 0, 200)) : '';

        return new self($channel.': HTTP '.$status.($detail !== '' ? ' - '.$detail : ''));
    }

    /**
     * @param  list<string>  $secrets
     */
    public static function scrub(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        // Never leak a URL (webhooks embed their secret in the path).
        $message = (string) preg_replace('#https?://\S+#i', '[url]', $message);

        return mb_substr($message, 0, 300);
    }
}
