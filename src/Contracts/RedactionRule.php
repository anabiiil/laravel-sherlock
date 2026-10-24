<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

/**
 * One text redaction strategy in the Redactor chain. Must be fast: do a cheap
 * pre-check (str_contains / strpbrk) before running regexes.
 */
interface RedactionRule
{
    public function redact(string $value): string;
}
