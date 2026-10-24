<?php

declare(strict_types=1);

namespace Anabil\Tracing\Privacy\Rules;

use Anabil\Tracing\Contracts\RedactionRule;
use Anabil\Tracing\Contracts\Redactor;

/**
 * Detects password hashes by shape (bcrypt, argon2i/id, sha/md5-crypt, scrypt, pbkdf2,
 * i.e. every format Laravel's Hash can produce) and removes them regardless of key / column.
 */
final class PasswordHashRule implements RedactionRule
{
    private const HASH = '(?:'
        .'\$2[abxy]?\$\d{2}\$[.\/A-Za-z0-9]{53}'                                          // bcrypt
        .'|\$argon2(?:id|i|d)\$v=\d+\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+\/]+(?:\$[A-Za-z0-9+\/]+)?' // argon2
        .'|\$[156]\$(?:rounds=\d+\$)?[.\/A-Za-z0-9]{1,16}\$[.\/A-Za-z0-9]{22,86}'          // md5/sha256/sha512-crypt
        .'|\$(?:scrypt|7)\$[^\s\'"]{16,}'                                                  // scrypt
        .'|\$?pbkdf2[\w\-]*\$[^\s\'"]{16,}'                                                // pbkdf2 (passlib / django)
        .')';

    private const SEARCH = '/'.self::HASH.'/';

    private const FULL = '/^'.self::HASH.'$/';

    public function redact(string $value): string
    {
        if (! str_contains($value, '$')) {
            return $value;
        }

        return (string) preg_replace(self::SEARCH, Redactor::REDACTED, $value);
    }

    /** Whether the whole value is a password hash. */
    public function isHash(string $value): bool
    {
        return str_contains($value, '$') && preg_match(self::FULL, $value) === 1;
    }
}
