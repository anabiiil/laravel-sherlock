<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

use Anabil\Sherlock\Enums\Severity;

/**
 * Result of EXPLAIN for one query fingerprint.
 */
final readonly class ExplainPlan
{
    /**
     * @param  string  $sql  normalized SQL that was explained
     * @param  array<array-key, mixed>  $raw  decoded plan as returned by the driver
     * @param  list<ExplainInsight>  $insights
     */
    public function __construct(
        public string $connection,
        public string $driver,
        public string $sql,
        public array $raw = [],
        public array $insights = [],
    ) {}

    public function hasProblems(): bool
    {
        return $this->insights !== [];
    }

    public function worstSeverity(): ?Severity
    {
        $worst = null;
        foreach ($this->insights as $insight) {
            $worst = $worst === null ? $insight->severity : Severity::max($worst, $insight->severity);
        }

        return $worst;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'driver' => $this->driver,
            'sql' => $this->sql,
            'raw' => $this->raw,
            'insights' => array_map(static fn (ExplainInsight $i): array => $i->toArray(), $this->insights),
        ];
    }
}
