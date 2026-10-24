<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Privacy\Rules;

use Anabil\Sherlock\Contracts\RedactionRule;
use Anabil\Sherlock\Contracts\Redactor;

/**
 * Bearer / Basic credentials and JWTs.
 */
final class BearerTokenRule implements RedactionRule
{
    private const PATTERNS = [
        '/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]{8,}=*/i',
        '/\b(Basic)\s+(?=[A-Za-z0-9+\/]*[0-9+\/=])[A-Za-z0-9+\/]{8,}={0,2}/i',
        '/\beyJ[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]{5,}\.[A-Za-z0-9_\-]*/',
    ];

    private const REPLACEMENTS = [
        '$1 '.Redactor::REDACTED,
        '$1 '.Redactor::REDACTED,
        Redactor::REDACTED,
    ];

    public function redact(string $value): string
    {
        if (stripos($value, 'bearer') === false && stripos($value, 'basic') === false && ! str_contains($value, 'eyJ')) {
            return $value;
        }

        return (string) preg_replace(self::PATTERNS, self::REPLACEMENTS, $value);
    }
}
