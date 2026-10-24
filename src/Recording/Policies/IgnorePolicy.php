<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Recording\Policies;

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Recording\PatternList;

/**
 * Ignore lists from config (paths, route names, HTTP methods, jobs, commands). Runs first
 * in the CompositeRecordingPolicy and short-circuits: an ignored unit records nothing.
 * Patterns are compiled once (PatternList).
 *
 * The package's own jobs (Anabil\Sherlock\Jobs, Anabil\Sherlock\Notifications\Jobs) and commands
 * (sherlock:...) are always ignored,
 * whatever the config says: tracing them would make the package report on itself.
 */
final class IgnorePolicy implements RecordingPolicy
{
    /** @var list<string> */
    public const PACKAGE_JOB_PREFIXES = ['Anabil\\Sherlock\\Jobs\\', 'Anabil\\Sherlock\\Notifications\\Jobs\\'];

    public const PACKAGE_COMMAND_PREFIX = 'sherlock:';

    private readonly PatternList $paths;

    private readonly PatternList $routes;

    private readonly PatternList $jobs;

    private readonly PatternList $commands;

    /** @var array<string, true> */
    private readonly array $methods;

    /**
     * @param  iterable<mixed>  $paths
     * @param  iterable<mixed>  $routes
     * @param  iterable<mixed>  $jobs
     * @param  iterable<mixed>  $commands
     * @param  iterable<mixed>  $methods
     */
    public function __construct(
        iterable $paths = [],
        iterable $routes = [],
        iterable $jobs = [],
        iterable $commands = [],
        iterable $methods = [],
    ) {
        $this->paths = new PatternList($paths);
        $this->routes = new PatternList($routes);
        $this->jobs = new PatternList($jobs);
        $this->commands = new PatternList($commands);

        $set = [];
        foreach ($methods as $method) {
            if (is_string($method) && $method !== '') {
                $set[strtoupper($method)] = true;
            }
        }
        $this->methods = $set;
    }

    /**
     * @param  array<string, mixed>  $config  the "sherlock.ignore" config array
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::list($config['paths'] ?? []),
            self::list($config['routes'] ?? []),
            self::list($config['jobs'] ?? []),
            self::list($config['commands'] ?? []),
            self::list($config['methods'] ?? []),
        );
    }

    public function ignores(EntryCandidate $candidate): bool
    {
        return match ($candidate->type) {
            EntryType::Request => ($candidate->method !== null && isset($this->methods[strtoupper($candidate->method)]))
                || $this->paths->matches(self::path($candidate->path ?? $candidate->name))
                || $this->routes->matches($candidate->routeName),
            EntryType::Job => self::isPackageJob($candidate->name) || $this->jobs->matches($candidate->name),
            EntryType::Command => str_starts_with($candidate->name, self::PACKAGE_COMMAND_PREFIX)
                || $this->commands->matches($candidate->name),
        };
    }

    public static function isPackageJob(string $name): bool
    {
        $name = ltrim($name, '\\');
        foreach (self::PACKAGE_JOB_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function ignoresRoute(?string $routeName): bool
    {
        return $this->routes->matches($routeName);
    }

    public function decide(EntryCandidate $candidate): RecordingDecision
    {
        // "No objection" is expressed as a non-forcing light capture; the composite only uses ignores().
        return $this->ignores($candidate) ? RecordingDecision::skip() : RecordingDecision::light();
    }

    /** "/api/orders/" => "api/orders"; the root stays "/" (as Request::path()). */
    private static function path(string $path): string
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
