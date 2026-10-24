<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * The "?period=" selector (1h / 24h / 7d / 30d). Unknown values fall back to 24h.
 */
final readonly class Period
{
    public const DEFAULT = '24h';

    /** @var array<string, string> key => label */
    public const OPTIONS = [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    private const MINUTES = [
        '1h' => 60,
        '24h' => 1440,
        '7d' => 10080,
        '30d' => 43200,
    ];

    private function __construct(
        public string $key,
        public DateTimeImmutable $since,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $key = $request->query('period');

        return self::of(is_string($key) ? $key : self::DEFAULT);
    }

    public static function of(string $key): self
    {
        $key = isset(self::MINUTES[$key]) ? $key : self::DEFAULT;

        return new self($key, CarbonImmutable::now()->subMinutes(self::MINUTES[$key]));
    }

    public function label(): string
    {
        return self::OPTIONS[$this->key];
    }
}
