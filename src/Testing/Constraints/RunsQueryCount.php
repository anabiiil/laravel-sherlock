<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing\Constraints;

use Anabil\Sherlock\Testing\ProbeReport;
use Anabil\Sherlock\Testing\ProbeResult;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Matches a ProbeResult whose query count is exactly $expected, or below it ($lessThan).
 */
final class RunsQueryCount extends Constraint
{
    public function __construct(
        private readonly int $expected,
        private readonly bool $lessThan = false,
    ) {}

    public function toString(): string
    {
        return $this->lessThan
            ? sprintf('ran fewer than %d %s', $this->expected, $this->expected === 1 ? 'query' : 'queries')
            : sprintf('ran exactly %d %s', $this->expected, $this->expected === 1 ? 'query' : 'queries');
    }

    protected function matches(mixed $other): bool
    {
        if (! $other instanceof ProbeResult) {
            return false;
        }

        return $this->lessThan ? $other->count() < $this->expected : $other->count() === $this->expected;
    }

    protected function failureDescription(mixed $other): string
    {
        $actual = $other instanceof ProbeResult ? $other->count() : 0;

        return sprintf('the code %s (it ran %d)', $this->toString(), $actual);
    }

    protected function additionalFailureDescription(mixed $other): string
    {
        if (! $other instanceof ProbeResult) {
            return '';
        }

        $report = "\n".ProbeReport::queries($other)."\n";

        $nPlusOnes = $other->nPlusOnes();
        if ($nPlusOnes !== []) {
            $report .= "\nLikely cause:\n\n".ProbeReport::findings($nPlusOnes, $other->trace)."\n";
        }

        return $report;
    }
}
