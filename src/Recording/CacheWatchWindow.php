<?php

declare(strict_types=1);

namespace Anabil\Tracing\Recording;

use Anabil\Tracing\Contracts\WatchWindow;
use Anabil\Tracing\Data\EntryCandidate;
use Anabil\Tracing\Data\WatchState;
use Anabil\Tracing\Enums\EntryType;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cache-backed "tracing:watch" window. Use a shared store (redis) so every server sees it.
 * The entry is written with a TTL equal to the window, so it expires on its own; the
 * expiry timestamp is also checked on read.
 *
 * Cost: one cache get() per memo period (memo_seconds, default 5) per process. On FPM that
 * is at most one get per request; Octane / queue workers / long commands reuse the last
 * read for a few seconds, so the check is effectively free. A failing cache store counts
 * as "no window" for the memo period (never throws into the request).
 */
final class CacheWatchWindow implements WatchWindow
{
    private ?WatchState $memo = null;

    private ?float $memoAt = null;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  unix time in seconds (injectable for tests)
     */
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ?string $store = null,
        private readonly string $key = 'tracing:watch',
        private readonly int $maxMinutes = 1440,
        private readonly int $memoSeconds = 5,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function start(int $minutes, ?string $routePattern = null, int|string|null $userId = null): WatchState
    {
        $minutes = max(1, min($this->maxMinutes, $minutes));
        $now = $this->now();

        $state = new WatchState(
            $now,
            $now->modify('+'.$minutes.' minutes'),
            $routePattern !== null && $routePattern !== '' ? $routePattern : null,
            $userId === '' ? null : $userId,
        );

        $this->repository()->put($this->key, $state->toArray(), $minutes * 60);
        $this->remember($state);

        return $state;
    }

    public function stop(): void
    {
        $this->repository()->forget($this->key);
        $this->remember(null);
    }

    public function current(): ?WatchState
    {
        $now = ($this->clock)();

        if ($this->memoAt === null || ($now - $this->memoAt) >= $this->memoSeconds) {
            $this->memo = $this->fetch();
            $this->memoAt = $now;
        }

        return $this->memo !== null && $this->memo->isActive($this->now()) ? $this->memo : null;
    }

    public function matches(EntryCandidate $candidate): bool
    {
        $state = $this->current();
        if ($state === null) {
            return false;
        }

        // Route / user filters only make sense for HTTP requests.
        if (($state->routePattern !== null || $state->userId !== null) && $candidate->type !== EntryType::Request) {
            return false;
        }

        if ($state->routePattern !== null && ! $this->routeMatches($state->routePattern, $candidate)) {
            return false;
        }

        // The user is usually not resolved yet at start: an unknown user is a potential match,
        // re-checked by the request collector at terminate.
        return $state->userId === null
            || $candidate->userId === null
            || (string) $state->userId === (string) $candidate->userId;
    }

    /** Forget the memoized read (tests / after external changes). */
    public function flushMemo(): void
    {
        $this->memo = null;
        $this->memoAt = null;
    }

    private function routeMatches(string $pattern, EntryCandidate $candidate): bool
    {
        $path = trim($candidate->path ?? $candidate->name, '/');
        $trimmed = trim($pattern, '/');

        return Str::is($trimmed, $path === '' ? '/' : $path)
            || ($candidate->routeName !== null && Str::is($pattern, $candidate->routeName));
    }

    private function fetch(): ?WatchState
    {
        try {
            $value = $this->repository()->get($this->key);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value) || ! is_int($value['started_at'] ?? null) || ! is_int($value['expires_at'] ?? null)) {
            return null;
        }

        $route = $value['route'] ?? null;
        $user = $value['user'] ?? null;

        return WatchState::fromArray([
            'started_at' => $value['started_at'],
            'expires_at' => $value['expires_at'],
            'route' => is_string($route) ? $route : null,
            'user' => is_int($user) || is_string($user) ? $user : null,
        ]);
    }

    private function remember(?WatchState $state): void
    {
        $this->memo = $state;
        $this->memoAt = ($this->clock)();
    }

    private function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp((int) floor(($this->clock)()));
    }

    private function repository(): CacheRepository
    {
        return $this->cache->store($this->store);
    }
}
