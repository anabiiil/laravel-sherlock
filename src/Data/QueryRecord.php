<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

/**
 * One executed query, captured cheaply. Bindings are already redacted (or null when
 * not captured, e.g. production lightweight mode). Normalized SQL and fingerprint are
 * filled during analysis (at terminate) via withNormalization().
 *
 * $explainBindings: lightweight capture keeps the (redacted) bindings of the rare query
 * slow enough to be EXPLAINed, for EXPLAIN only. They are in-memory only: the analysis
 * pipeline drops them right after EXPLAIN and the recorder drops them again before a
 * RecordedTrace is built, so they never reach analyzers, storage, queued job payloads,
 * notifications or AI. They are never part of toArray().
 */
final readonly class QueryRecord
{
    /**
     * @param  array<int|string, scalar|null>|null  $bindings  redacted bindings; null = not captured
     * @param  float  $offsetMs  start time relative to the trace start
     * @param  array<int|string, scalar|null>|null  $explainBindings  transient, EXPLAIN only (see class doc)
     */
    public function __construct(
        public string $sql,
        public ?array $bindings,
        public float $timeMs,
        public string $connection,
        public string $driver,
        public ?SourceLocation $location = null,
        public float $offsetMs = 0.0,
        public ?string $normalizedSql = null,
        public ?string $fingerprint = null,
        public ?array $explainBindings = null,
    ) {}

    public function withNormalization(string $normalizedSql, string $fingerprint): self
    {
        return new self(
            $this->sql, $this->bindings, $this->timeMs, $this->connection, $this->driver,
            $this->location, $this->offsetMs, $normalizedSql, $fingerprint, $this->explainBindings,
        );
    }

    public function withLocation(?SourceLocation $location): self
    {
        return new self(
            $this->sql, $this->bindings, $this->timeMs, $this->connection, $this->driver,
            $location, $this->offsetMs, $this->normalizedSql, $this->fingerprint, $this->explainBindings,
        );
    }

    /**
     * Drops the stored bindings and the transient EXPLAIN bindings.
     */
    public function withoutBindings(): self
    {
        return new self(
            $this->sql, null, $this->timeMs, $this->connection, $this->driver,
            $this->location, $this->offsetMs, $this->normalizedSql, $this->fingerprint,
        );
    }

    public function withoutExplainBindings(): self
    {
        return new self(
            $this->sql, $this->bindings, $this->timeMs, $this->connection, $this->driver,
            $this->location, $this->offsetMs, $this->normalizedSql, $this->fingerprint,
        );
    }

    /**
     * Bindings EXPLAIN may use: the captured ones, else the transient EXPLAIN-only ones.
     *
     * @return array<int|string, scalar|null>|null
     */
    public function bindingsForExplain(): ?array
    {
        return $this->bindings ?? $this->explainBindings;
    }

    public function isNormalized(): bool
    {
        return $this->fingerprint !== null;
    }

    public function isSelect(): bool
    {
        $head = strtolower(substr(ltrim($this->sql, " \t\n\r("), 0, 6));

        return $head === 'select';
    }

    /**
     * Key identifying identical SQL + identical bindings (duplicate detection).
     * Without bindings, it degrades to the raw SQL.
     */
    public function identityKey(): string
    {
        return $this->bindings === null
            ? $this->sql
            : $this->sql."\0".json_encode($this->bindings, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'time_ms' => $this->timeMs,
            'connection' => $this->connection,
            'driver' => $this->driver,
            'location' => $this->location?->toArray(),
            'offset_ms' => $this->offsetMs,
            'normalized_sql' => $this->normalizedSql,
            'fingerprint' => $this->fingerprint,
        ];
    }
}
