<?php

declare(strict_types=1);

namespace Anabil\Tracing\Privacy\Rules;

use Anabil\Tracing\Contracts\RedactionRule;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Privacy\SensitiveKeys;

/**
 * Masks values of sensitive keys inside free text: "password=secret", "token: abc",
 * JSON '"api_key":"abc"', query strings "?access_token=abc&x=1".
 * (Array keys are handled by the Redactor using the same SensitiveKeys.)
 */
final class SensitiveKeyRule implements RedactionRule
{
    /** Candidate keys (non-consuming, so "failed: password: x" still sees "password"). */
    private const KEY = '/(?<![\w\-])[A-Za-z_][\w.\-]{0,63}(?=["\']?\s*[:=])/';

    /** Separator + value, anchored right after a sensitive key. */
    private const VALUE = '/\G(["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s"\'&,;}\]]+)/';

    public function __construct(
        private readonly SensitiveKeys $keys,
    ) {}

    public function keys(): SensitiveKeys
    {
        return $this->keys;
    }

    public function redact(string $value): string
    {
        if (strpbrk($value, '=:') === false || preg_match_all(self::KEY, $value, $keys, PREG_OFFSET_CAPTURE) === 0) {
            return $value;
        }

        $result = '';
        $cursor = 0;

        foreach ($keys[0] as [$key, $offset]) {
            $valueStart = $offset + strlen($key);
            if ($offset < $cursor || ! $this->keys->matches($key)
                || preg_match(self::VALUE, $value, $m, 0, $valueStart) !== 1) {
                continue;
            }

            $raw = $m[2];
            $quote = ($raw[0] === '"' || $raw[0] === "'") ? $raw[0] : '';
            $valueOffset = $valueStart + strlen($m[1]) + strlen($quote);
            if (substr($value, $valueOffset, strlen(Redactor::REDACTED)) === Redactor::REDACTED) {
                continue;
            }

            $result .= substr($value, $cursor, $valueStart - $cursor).$m[1].$quote.Redactor::REDACTED.$quote;
            $cursor = $valueStart + strlen($m[0]);
        }

        return $cursor === 0 ? $value : $result.substr($value, $cursor);
    }
}
