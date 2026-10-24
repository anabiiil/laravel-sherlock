<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai;

use Anabil\Tracing\Contracts\AiBudget;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Daily AI call counter in a cache store, keyed by the date in the app timezone, taken with
 * an atomic increment. Semantics of ai.daily_limit (fail-safe):
 *  - N > 0  : at most N calls per day;
 *  - 0      : no calls at all (every analysis is skipped_budget);
 *  - N < 0  : unlimited (limit() returns -1, remaining() PHP_INT_MAX).
 * Units are not refunded on provider errors (a timed-out call may still be billed).
 */
final class CacheAiBudget implements AiBudget
{
    private const KEY = 'tracing:ai:budget:';

    /** Two days: the key outlives its day whatever the store's clock skew. */
    private const TTL = 172800;

    /**
     * @param  Closure(): DateTimeImmutable|null  $clock
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly int $limit,
        private readonly string $timezone = 'UTC',
        private readonly ?Closure $clock = null,
    ) {}

    public function limit(): int
    {
        return $this->limit < 0 ? -1 : $this->limit;
    }

    public function used(): int
    {
        $used = $this->cache->get($this->key(), 0);

        return is_numeric($used) ? max(0, (int) $used) : 0;
    }

    public function remaining(): int
    {
        if ($this->limit < 0) {
            return PHP_INT_MAX;
        }

        return max(0, $this->limit - $this->used());
    }

    public function consume(): bool
    {
        if ($this->limit === 0) {
            return false;
        }

        $key = $this->key();

        // add() is a no-op when the key exists, so the TTL is set once and increments stay atomic.
        $this->cache->add($key, 0, self::TTL);
        $used = $this->cache->increment($key);

        if ($used === false) {
            return false;
        }

        if ($this->limit > 0 && (int) $used > $this->limit) {
            $this->cache->decrement($key);

            return false;
        }

        return true;
    }

    public function key(): string
    {
        return self::KEY.$this->today()->format('Y-m-d');
    }

    private function today(): DateTimeImmutable
    {
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable;

        try {
            $zone = new DateTimeZone($this->timezone);
        } catch (Exception) {
            $zone = new DateTimeZone('UTC');
        }

        return $now->setTimezone($zone);
    }
}
