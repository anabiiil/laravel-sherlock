<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Filters;

use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Illuminate\Http\Request;

/**
 * Issues list query string (type, severity, status, search, sort). Lenient on purpose:
 * unknown values are dropped instead of failing the page. Status defaults to "open";
 * "all" disables the status filter.
 */
final readonly class IssueFilters
{
    public const STATUS_ALL = 'all';

    /** @var array<string, string> */
    public const SORTS = [
        'last_seen' => 'Last seen',
        'first_seen' => 'First seen',
        'occurrences' => 'Occurrences',
        'severity' => 'Severity',
    ];

    private const MAX_SEARCH = 200;

    public function __construct(
        public ?IssueType $type = null,
        public ?Severity $severity = null,
        public ?IssueStatus $status = IssueStatus::Open,
        public string $search = '',
        public string $sort = 'last_seen',
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = Input::string($request, 'status');
        $sort = Input::string($request, 'sort');

        return new self(
            IssueType::tryFrom(Input::string($request, 'type')),
            Severity::tryFrom(Input::string($request, 'severity')),
            $status === self::STATUS_ALL ? null : (IssueStatus::tryFrom($status) ?? IssueStatus::Open),
            mb_substr(Input::string($request, 'search'), 0, self::MAX_SEARCH),
            isset(self::SORTS[$sort]) ? $sort : 'last_seen',
        );
    }

    public function toFilter(): IssueFilter
    {
        return new IssueFilter(
            type: $this->type,
            severity: $this->severity,
            status: $this->status,
            search: $this->search === '' ? null : $this->search,
            sort: $this->sort,
        );
    }

    public function statusValue(): string
    {
        return $this->status->value ?? self::STATUS_ALL;
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
            'severity' => $this->severity->value ?? '',
            'status' => $this->status === IssueStatus::Open ? '' : $this->statusValue(),
            'search' => $this->search,
            'sort' => $this->sort === 'last_seen' ? '' : $this->sort,
        ], static fn (string $value): bool => $value !== '');
    }
}
