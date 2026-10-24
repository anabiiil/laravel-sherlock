<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing\Constraints;

use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Testing\ProbeReport;
use Anabil\Sherlock\Testing\ProbeResult;
use Closure;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Matches a ProbeResult for which $finder returns no finding.
 */
final class RunsNoQueryIssues extends Constraint
{
    /** @var list<Finding>|null */
    private ?array $findings = null;

    /**
     * @param  string  $expectation  e.g. "ran no N+1 queries (2+ identical queries from one line)"
     * @param  Closure(ProbeResult): list<Finding>  $finder
     */
    public function __construct(
        private readonly string $expectation,
        private readonly Closure $finder,
    ) {}

    public function toString(): string
    {
        return $this->expectation;
    }

    protected function matches(mixed $other): bool
    {
        return $this->findingsOf($other) === [];
    }

    protected function failureDescription(mixed $other): string
    {
        return 'the code '.$this->expectation;
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        $findings = $this->findingsOf($other);
        $count = count($findings);

        return "\n".sprintf('Sherlock found %d %s:', $count, $count === 1 ? 'issue' : 'issues')."\n\n".ProbeReport::findings($findings, $other instanceof ProbeResult ? $other->trace : null)."\n";
    }

    /**
     * @return list<Finding>
     */
    private function findingsOf(mixed $other): array
    {
        if (! $other instanceof ProbeResult) {
            return [];
        }

        return $this->findings ??= ($this->finder)($other);
    }
}
