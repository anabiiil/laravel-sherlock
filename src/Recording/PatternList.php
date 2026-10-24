<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording;

/**
 * A list of Str::is()-style wildcard patterns compiled once into a hash set (exact
 * entries) plus a single alternation regex (wildcard entries), so matching costs one
 * isset() and at most one preg_match() instead of one regex build per pattern per call.
 */
final class PatternList
{
    /** @var array<string, true> */
    private readonly array $exact;

    private readonly ?string $regex;

    /**
     * @param  iterable<mixed>  $patterns
     */
    public function __construct(iterable $patterns)
    {
        $exact = [];
        $wildcards = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (str_contains($pattern, '*')) {
                $wildcards[] = str_replace('\*', '.*', preg_quote($pattern, '#'));
            } else {
                $exact[$pattern] = true;
            }
        }

        $this->exact = $exact;
        $this->regex = $wildcards === [] ? null : '#^(?:'.implode('|', $wildcards).')\z#su';
    }

    public function isEmpty(): bool
    {
        return $this->exact === [] && $this->regex === null;
    }

    public function matches(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (isset($this->exact[$value])) {
            return true;
        }

        return $this->regex !== null && preg_match($this->regex, $value) === 1;
    }
}
