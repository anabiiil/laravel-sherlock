<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Digest;

use Anabil\Sherlock\Contracts\DigestService;
use Anabil\Sherlock\Contracts\NotificationDispatcher;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Periodic digest (Service Layer). The last-sent time lives in the cache under a key with no
 * expiry (forever): no extra table / migration is needed, and losing it (cache flush) only
 * means the next daily check sends one digest a little early. "Due" is counted in calendar
 * days of the app timezone, so a digest sent at 09:00:05 is due again at the next 09:00
 * check when every_days = 1 (a strict 24 h comparison would skip a day).
 *
 * Reads stored data only; never calls the AI.
 */
final class DefaultDigestService implements DigestService
{
    public const LAST_SENT_KEY = 'sherlock:digest:last_sent_at';

    public const LOCK_KEY = 'sherlock:digest:sending';

    private const LOCK_SECONDS = 600;

    /**
     * @param  list<string>  $channels  empty = every enabled notification channel
     */
    public function __construct(
        private readonly DigestBuilder $builder,
        private readonly NotificationDispatcher $dispatcher,
        private readonly Cache $cache,
        private readonly bool $enabled = false,
        private readonly int $everyDays = 1,
        private readonly array $channels = [],
        private readonly string $timezone = 'UTC',
    ) {}

    public function isDue(?DateTimeInterface $now = null): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $last = $this->lastSentAt();

        if ($last === null) {
            return true;
        }

        $now = $this->local(CarbonImmutable::instance($now ?? CarbonImmutable::now()))->startOfDay();

        return (int) $this->local($last)->startOfDay()->diffInDays($now, false) >= $this->everyDays();
    }

    public function send(bool $force = false): bool
    {
        if (! $force && ! $this->isDue()) {
            return false;
        }

        $lock = $this->cache->getStore() instanceof LockProvider
            ? $this->cache->getStore()->lock(self::LOCK_KEY, self::LOCK_SECONDS)
            : null;

        if ($lock !== null && ! $lock->get()) {
            return false; // another worker is sending it right now
        }

        try {
            // Re-check under the lock: a concurrent run may have just sent it.
            if (! $force && ! $this->isDue()) {
                return false;
            }

            $now = CarbonImmutable::now();
            $since = $this->lastSentAt() ?? $now->subDays($this->everyDays());
            $report = $this->builder->build($since, $now);

            $this->dispatcher->send($this->builder->message($report), $this->channels === [] ? null : $this->channels);
            $this->cache->forever(self::LAST_SENT_KEY, $now->getTimestamp());

            return true;
        } finally {
            $lock?->release();
        }
    }

    public function lastSentAt(): ?CarbonImmutable
    {
        $value = $this->cache->get(self::LAST_SENT_KEY);

        return is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value) : null;
    }

    private function everyDays(): int
    {
        return max(1, $this->everyDays);
    }

    private function local(DateTimeImmutable $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone($this->timezone);
    }
}
