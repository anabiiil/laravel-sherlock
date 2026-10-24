<?php

declare(strict_types=1);

namespace Anabil\Tracing\Analysis\Support;

use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Data\QueryRecord;

/**
 * Analyzers receive enriched queries from the pipeline; these fall back to computing
 * the values when an analyzer is used standalone.
 *
 * @property-read Fingerprinter $fingerprinter
 */
trait ResolvesQueries
{
    private function fingerprintOf(QueryRecord $query): string
    {
        return $query->fingerprint ?? $this->fingerprinter->rawQuery($query->sql);
    }

    private function normalizedOf(QueryRecord $query): string
    {
        return $query->normalizedSql ?? $query->sql;
    }
}
