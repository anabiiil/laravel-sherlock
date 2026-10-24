<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording\Policies;

use Anabil\Tracing\Contracts\RecordingPolicy;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\RecordingDecision;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Recording\PatternList;

/**
 * Ignore lists from config (paths, route names, HTTP methods, jobs, commands). Runs first
 * in the CompositeRecordingPolicy and short-circuits: an ignored unit records nothing.
 * Patterns are compiled once (PatternList).
 */
final class IgnorePolicy implements RecordingPolicy
{
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
     * @param  array<string, mixed>  $config  the "tracing.ignore" config array
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
            EntryType::Job => $this->jobs->matches($candidate->name),
            EntryType::Command => $this->commands->matches($candidate->name),
        };
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
