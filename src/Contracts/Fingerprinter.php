<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Enums\IssueType;

/**
 * Short stable hashes (32 hex chars).
 */
interface Fingerprinter
{
    /** Fingerprint of a normalized query (case-insensitive). */
    public function query(string $normalizedSql): string;

    /** Normalize then fingerprint a raw SQL string. */
    public function rawQuery(string $sql): string;

    /**
     * Issue fingerprint = type + query fingerprint + source location + entry name (+ extra, e.g. exception class).
     */
    public function issue(
        IssueType $type,
        ?string $queryFingerprint = null,
        ?SourceLocation $location = null,
        ?string $entryName = null,
        ?string $extra = null,
    ): string;
}
