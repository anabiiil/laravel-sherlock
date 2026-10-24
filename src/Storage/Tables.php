<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Storage;

/**
 * Names of the package tables. The prefix comes from config("sherlock.storage.table_prefix")
 * (default "sherlock_") and is applied on top of the connection's own prefix.
 */
final class Tables
{
    public const DEFAULT_PREFIX = 'sherlock_';

    public function __construct(
        private readonly string $prefix = self::DEFAULT_PREFIX,
    ) {}

    public static function fromConfig(): self
    {
        $prefix = function_exists('config') ? config('sherlock.storage.table_prefix', self::DEFAULT_PREFIX) : null;

        return new self(is_string($prefix) ? $prefix : self::DEFAULT_PREFIX);
    }

    /** The configured Sherlock connection name (null = the application's default connection). */
    public static function connection(): ?string
    {
        $connection = function_exists('config') ? config('sherlock.storage.connection') : null;

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

    public function releases(): string
    {
        return $this->prefix.'releases';
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return [$this->traces(), $this->queries(), $this->issues(), $this->issueTraces(), $this->releases()];
    }
}
