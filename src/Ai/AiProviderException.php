<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai;

use RuntimeException;
use Throwable;

/**
 * Transport / API / response errors of an AI provider. Messages are short and never
 * contain the API key (they are stored on the issue and printed by the console).
 */
final class AiProviderException extends RuntimeException
{
    private const MAX_DETAIL = 200;

    public static function unreachable(string $driver, ?Throwable $previous = null): self
    {
        return new self("AI provider [{$driver}] is unreachable or timed out.", 0, $previous);
    }

    public static function http(string $driver, int $status, ?string $detail = null): self
    {
        $message = "AI provider [{$driver}] returned HTTP {$status}";

        return new self($detail !== null && $detail !== '' ? $message.': '.self::short($detail) : $message.'.', $status);
    }

    public static function invalidResponse(string $driver, string $reason): self
    {
        return new self("AI provider [{$driver}] returned an unusable response: {$reason}");
    }

    public static function misconfigured(string $driver, string $reason): self
    {
        return new self("AI driver [{$driver}] is misconfigured: {$reason}");
    }

    /** Remove the API key (if echoed back) and keep the message short. */
    public static function scrub(string $message, ?string $apiKey): string
    {
        if ($apiKey !== null && $apiKey !== '') {
            $message = str_replace($apiKey, '[REDACTED]', $message);
        }

        return self::short($message);
    }

    private static function short(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) > self::MAX_DETAIL ? mb_substr($value, 0, self::MAX_DETAIL).'…' : $value;
    }
}
