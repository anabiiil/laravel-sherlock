<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Privacy\Rules;

use Anabil\Sherlock\Contracts\RedactionRule;
use Anabil\Sherlock\Contracts\Redactor;

/**
 * Well-known API key shapes, long hex strings (>= 32) and long high-entropy
 * base64-like strings (>= 40, mixed upper / lower / digits).
 */
final class LongSecretRule implements RedactionRule
{
    private const PREFIXED = '/\b(?:'
        .'(?:sk|pk|rk)[-_](?:live|test|proj|ant)?[-_]?[A-Za-z0-9_\-]{16,}'
        .'|gh[pousr]_[A-Za-z0-9]{20,}'
        .'|github_pat_[A-Za-z0-9_]{20,}'
        .'|xox[abposr]-[A-Za-z0-9\-]{10,}'
        .'|AKIA[0-9A-Z]{16}'
        .'|AIza[0-9A-Za-z_\-]{35}'
        .')/';

    private const HEX = '/(?<![A-Za-z0-9])[A-Fa-f0-9]{32,}(?![A-Za-z0-9])/';

    private const BASE64 = '/(?<![A-Za-z0-9+\/=_\-])[A-Za-z0-9+\/_\-]{40,}={0,2}/';

    public function redact(string $value): string
    {
        if (strlen($value) < 20) {
            return $value;
        }

        $value = (string) preg_replace([self::PREFIXED, self::HEX], Redactor::REDACTED, $value);

        if (strlen($value) < 40) {
            return $value;
        }

        return (string) preg_replace_callback(self::BASE64, static function (array $m): string {
            $candidate = $m[0];

            return preg_match('/[A-Z]/', $candidate) === 1
                && preg_match('/[a-z]/', $candidate) === 1
                && preg_match('/\d/', $candidate) === 1
                    ? Redactor::REDACTED
                    : $candidate;
        }, $value);
    }
}
