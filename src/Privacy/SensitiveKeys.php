<?php

declare(strict_types=1);

namespace Anabil\Tracing\Privacy;

/**
 * Sensitive key names. The locked defaults can never be removed; config can only add.
 *
 * Matching is case-insensitive and separator-insensitive ("apiKey", "api-key", "API_KEY"):
 *  - words longer than 3 characters match when CONTAINED in the key ("user_password_confirmation");
 *  - words of 3 characters or fewer (pin, otp, ssn, cvv, cvc, pwd) match only as a whole
 *    word segment (optionally plural), so "user_pin" / "otpCode" match but "shipping",
 *    "footprint" or "class_name" do not.
 */
final class SensitiveKeys
{
    public const LOCKED = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'access_token',
        'refresh_token',
        'remember_token',
        'private_key',
        'client_secret',
        'authorization',
        'credit_card',
        'card_number',
        'cvv',
        'cvc',
        'pin',
        'otp',
        'ssn',
    ];

    private const SHORT_WORD_LENGTH = 3;

    private const CACHE_LIMIT = 2048;

    /** @var list<string> */
    private readonly array $keys;

    private readonly ?string $containsPattern;

    private readonly ?string $segmentPattern;

    /** @var array<string, bool> */
    private array $cache = [];

    /**
     * @param  iterable<mixed>  $additional  extra keys from config (non-strings ignored)
     */
    public function __construct(iterable $additional = [])
    {
        $keys = self::LOCKED;
        foreach ($additional as $key) {
            if (is_string($key) && trim($key) !== '') {
                $keys[] = strtolower(trim($key));
            }
        }
        $this->keys = array_values(array_unique($keys));

        $contains = [];
        $segments = [];
        foreach ($this->keys as $key) {
            $compact = self::compact($key);
            if ($compact === '') {
                continue;
            }
            if (strlen($compact) <= self::SHORT_WORD_LENGTH) {
                $segments[] = preg_quote($compact, '/');
            } else {
                $contains[] = preg_quote($compact, '/');
            }
        }

        $this->containsPattern = $contains === [] ? null : '/'.implode('|', array_unique($contains)).'/';
        $this->segmentPattern = $segments === []
            ? null
            : '/(?:^|_)(?:'.implode('|', array_unique($segments)).')s?(?:_|$)/';
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->keys;
    }

    public function matches(string|int $key): bool
    {
        if (is_int($key) || $key === '') {
            return false;
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $result = ($this->containsPattern !== null && preg_match($this->containsPattern, self::compact($key)) === 1)
            || ($this->segmentPattern !== null && preg_match($this->segmentPattern, self::segments($key)) === 1);

        if (count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }

        return $this->cache[$key] = $result;
    }

    /** "API-Key" => "apikey" */
    private static function compact(string $key): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($key));
    }

    /** "userPin" / "user-pin" / "USER PIN" => "user_pin" */
    private static function segments(string $key): string
    {
        $snake = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($snake)), '_');
    }
}
