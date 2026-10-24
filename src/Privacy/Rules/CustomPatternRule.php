<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Privacy\Rules;

use Anabil\Sherlock\Contracts\RedactionRule;
use Anabil\Sherlock\Contracts\Redactor;

/**
 * User-defined regexes from config("sherlock.privacy.patterns"). Invalid patterns are ignored.
 */
final class CustomPatternRule implements RedactionRule
{
    /** @var list<string> */
    private readonly array $patterns;

    /**
     * @param  iterable<mixed>  $patterns
     */
    public function __construct(iterable $patterns = [])
    {
        $valid = [];
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && self::isValid($pattern)) {
                $valid[] = $pattern;
            }
        }
        $this->patterns = $valid;
    }

    private static function isValid(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    public function isEmpty(): bool
    {
        return $this->patterns === [];
    }

    public function redact(string $value): string
    {
        if ($this->patterns === []) {
            return $value;
        }

        return (string) preg_replace($this->patterns, Redactor::REDACTED, $value);
    }
}
