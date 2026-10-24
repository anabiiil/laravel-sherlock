<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Filters;

use Anabil\Sherlock\Data\TraceFilter;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Illuminate\Http\Request;

/**
 * Traces list query string (type, name, bottleneck, min duration, issue, release, sort). Lenient:
 * unknown values are dropped instead of failing the page.
 */
final readonly class TraceFilters
{
    /** @var array<string, string> */
    public const SORTS = [
        'created_at' => 'Newest',
        'duration' => 'Slowest',
    ];

    private const MAX_NAME = 255;

    public function __construct(
        public ?EntryType $type = null,
        public string $name = '',
        public ?Bottleneck $bottleneck = null,
        public ?float $minDurationMs = null,
        public ?string $issueId = null,
        public string $sort = 'created_at',
        public ?string $release = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $min = Input::string($request, 'min_ms');
        $issue = Input::string($request, 'issue');
        $sort = Input::string($request, 'sort');

        return new self(
            EntryType::tryFrom(Input::string($request, 'type')),
            mb_substr(Input::string($request, 'name'), 0, self::MAX_NAME),
            Bottleneck::tryFrom(Input::string($request, 'bottleneck')),
            is_numeric($min) && (float) $min > 0 ? (float) $min : null,
            preg_match('/^[A-Za-z0-9_-]{1,64}$/', $issue) === 1 ? $issue : null,
            isset(self::SORTS[$sort]) ? $sort : 'created_at',
            Input::release($request),
        );
    }

    public function toFilter(): TraceFilter
    {
        return new TraceFilter(
            type: $this->type,
            name: $this->name === '' ? null : $this->name,
            bottleneck: $this->bottleneck,
            minDurationMs: $this->minDurationMs,
            issueId: $this->issueId,
            sort: $this->sort,
            release: $this->release,
        );
    }

    /**
     * Non-default values, appended to pagination links.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter([
            'type' => $this->type->value ?? '',
            'name' => $this->name,
            'bottleneck' => $this->bottleneck->value ?? '',
            'min_ms' => $this->minDurationMs === null ? '' : (string) $this->minDurationMs,
            'issue' => (string) $this->issueId,
            'sort' => $this->sort === 'created_at' ? '' : $this->sort,
            'release' => (string) $this->release,
        ], static fn (string $value): bool => $value !== '');
    }
}
