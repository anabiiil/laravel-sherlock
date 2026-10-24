<?php

declare(strict_types=1);

namespace Anabil\Tracing\Storage;

/**
 * Names of the package tables. The prefix comes from config("tracing.storage.table_prefix")
 * (default "tracing_") and is applied on top of the connection's own prefix.
 */
final class Tables
{
    public const DEFAULT_PREFIX = 'tracing_';

    public function __construct(
        private readonly string $prefix = self::DEFAULT_PREFIX,
    ) {}

    public static function fromConfig(): self
    {
        $prefix = function_exists('config') ? config('tracing.storage.table_prefix', self::DEFAULT_PREFIX) : null;

        return new self(is_string($prefix) ? $prefix : self::DEFAULT_PREFIX);
    }

    /** The configured tracing connection name (null = the application's default connection). */
    public static function connection(): ?string
    {
        $connection = function_exists('config') ? config('tracing.storage.connection') : null;

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function traces(): string
    {
        return $this->prefix.'traces';
    }

    public function queries(): string
    {
        return $this->prefix.'queries';
    }

    public function issues(): string
    {
        return $this->prefix.'issues';
    }

    public function issueTraces(): string
    {
        return $this->prefix.'issue_traces';
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [$this->traces(), $this->queries(), $this->issues(), $this->issueTraces()];
    }
}
