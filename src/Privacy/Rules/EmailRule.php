<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Privacy\Rules;

use Anabil\Sherlock\Contracts\RedactionRule;
use Anabil\Sherlock\Contracts\Redactor;

final class EmailRule implements RedactionRule
{
    private const PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    public function redact(string $value): string
    {
        if (! str_contains($value, '@')) {
            return $value;
        }

        return (string) preg_replace(self::PATTERN, Redactor::REDACTED, $value);
    }
}
