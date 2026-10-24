<?php

declare(strict_types=1);

namespace Anabil\Tracing\Privacy\Rules;

use Anabil\Tracing\Contracts\RedactionRule;
use Anabil\Tracing\Contracts\Redactor;

/**
 * IPv4 and IPv6 addresses. IPv6 candidates must be full (8 groups) or compressed ("::")
 * and contain a digit, so times ("10:30:00") and "Class::method" are left alone.
 */
final class IpAddressRule implements RedactionRule
{
    private const IPV4 = '/(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?![\d.])/';

    private const IPV6 = '/(?<![\w:])(?:[A-Fa-f0-9]{1,4}:|:){2,7}(?::|[A-Fa-f0-9]{1,4})(?![\w:])/';

    public function redact(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = (string) preg_replace(self::IPV4, Redactor::REDACTED, $value);
        }

        if (substr_count($value, ':') >= 2) {
            $value = (string) preg_replace_callback(self::IPV6, static function (array $m): string {
                $candidate = $m[0];
                $full = substr_count($candidate, ':') === 7 && ! str_contains($candidate, '::');
                $compressed = str_contains($candidate, '::');

                return ($full || $compressed) && preg_match('/\d/', $candidate) === 1 ? Redactor::REDACTED : $candidate;
            }, $value);
        }

        return $value;
    }
}
