<?php

declare(strict_types=1);

namespace Anabil\Tracing\Privacy\Rules;

use Anabil\Tracing\Contracts\RedactionRule;
use Anabil\Tracing\Contracts\Redactor;

/**
 * Phone numbers in common shapes only, to avoid masking ids, timestamps and dates:
 *  - international: "+20 100 123 4567", "+201001234567", "0044 20 7946 0958"
 *  - NANP: "555-123-4567", "(555) 123-4567", "555.123.4567"
 *  - national with trunk 0: "010 0123 4567", "01001234567"
 */
final class PhoneRule implements RedactionRule
{
    private const PATTERN = '/'
        .'(?<![\w+])(?:\+|00)[1-9](?:[\s.\-()]{0,2}\d){7,14}(?!\d)'
        .'|(?<![\w.\-])\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}(?![\w.\-])'
        .'|(?<![\w.\-])0\d{1,4}[\s\-]\d{3,4}[\s\-]?\d{3,4}(?![\w.\-])'
        .'|(?<![\w.\-])0\d{9,10}(?![\w.\-])'
        .'/';

    public function redact(string $value): string
    {
        if (strlen($value) < 8 || strpbrk($value, '0123456789') === false) {
            return $value;
        }

        return (string) preg_replace(self::PATTERN, Redactor::REDACTED, $value);
    }
}
