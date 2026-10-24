<?php

declare(strict_types=1);

namespace Anabil\Tracing\Privacy\Rules;

use Anabil\Tracing\Contracts\RedactionRule;
use Anabil\Tracing\Contracts\Redactor;

/**
 * Payment card numbers (13-19 digits, optional spaces / dashes), Luhn-validated.
 */
final class CardNumberRule implements RedactionRule
{
    private const PATTERN = '/(?<![\d\-])\d(?:[ \-]?\d){12,18}(?![\d\-])/';

    public function redact(string $value): string
    {
        if (strlen($value) < 13 || preg_match('/\d{4}/', $value) !== 1) {
            return $value;
        }

        return (string) preg_replace_callback(self::PATTERN, static function (array $m): string {
            $digits = (string) preg_replace('/\D/', '', $m[0]);
            $length = strlen($digits);

            return $length >= 13 && $length <= 19 && self::luhn($digits) ? Redactor::REDACTED : $m[0];
        }, $value);
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
