<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support;

use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Enums\IssueType;

/**
 * xxh128 hashes (32 hex chars): fast, stable across PHP versions and processes.
 */
final class HashFingerprinter implements Fingerprinter
{
    private const ALGO = 'xxh128';

    private const SEPARATOR = "\x1F";

    public function __construct(
        private readonly QueryNormalizer $normalizer,
    ) {}

    public function query(string $normalizedSql): string
    {
        return hash(self::ALGO, strtolower($normalizedSql));
    }

    public function rawQuery(string $sql): string
    {
        return $this->query($this->normalizer->normalize($sql));
    }

    public function issue(
        IssueType $type,
        ?string $queryFingerprint = null,
        ?SourceLocation $location = null,
        ?string $entryName = null,
        ?string $extra = null,
    ): string {
        return hash(self::ALGO, implode(self::SEPARATOR, [
            $type->value,
            $queryFingerprint ?? '',
            $location?->toString() ?? '',
            $entryName ?? '',
            $extra ?? '',
        ]));
    }
}
