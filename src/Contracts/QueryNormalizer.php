<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

/**
 * Literals become "?", IN lists "IN (?+)", multi-row VALUES collapsed, whitespace collapsed.
 */
interface QueryNormalizer
{
    public function normalize(string $sql): string;
}
