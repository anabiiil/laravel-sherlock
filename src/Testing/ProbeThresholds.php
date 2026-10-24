<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Testing;

use Illuminate\Contracts\Config\Repository;

/**
 * Thresholds used by the test assertions. Each one reads "sherlock.testing.*" first, then
 * the production threshold ("sherlock.thresholds.*"), then a built-in default; every
 * assertion can still override its own threshold.
 *
 * N+1 is the exception: test fixtures are small (a factory creates 2 or 3 rows), so the
 * production default (5 repetitions) would let most N+1 loops pass. In tests a query
 * repeated twice from the same line is already an N+1 unless "sherlock.testing.n_plus_one"
 * says otherwise.
 */
final readonly class ProbeThresholds
{
    public const N_PLUS_ONE = 2;

    public function __construct(
        public int $nPlusOne = self::N_PLUS_ONE,
        public int $duplicates = 2,
        public float $slowQueryMs = 100.0,
        public int $maxQueries = 50,
    ) {}

    public static function fromConfig(?Repository $config): self
    {
        return new self(
            max(2, self::int($config, ['sherlock.testing.n_plus_one'], self::N_PLUS_ONE)),
            max(2, self::int($config, ['sherlock.testing.duplicate_query', 'sherlock.thresholds.duplicate_query.min_count'], 2)),
            max(0.0, self::float($config, ['sherlock.testing.slow_query_ms', 'sherlock.thresholds.slow_query.warning_ms'], 100.0)),
            max(1, self::int($config, ['sherlock.testing.max_queries', 'sherlock.thresholds.excessive_queries.warning_count'], 50)),
        );
    }

    /**
     * @param  list<string>  $keys  first numeric value wins
     */
    private static function int(?Repository $config, array $keys, int $default): int
    {
        $value = self::first($config, $keys);

        return $value === null ? $default : (int) $value;
    }

    /**
     * @param  list<string>  $keys  first numeric value wins
     */
    private static function float(?Repository $config, array $keys, float $default): float
    {
        $value = self::first($config, $keys);

        return $value === null ? $default : (float) $value;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function first(?Repository $config, array $keys): int|float|string|null
    {
        foreach ($keys as $key) {
            $value = $config?->get($key);
            if (is_numeric($value)) {
                return $value;
            }
        }

        return null;
    }
}
