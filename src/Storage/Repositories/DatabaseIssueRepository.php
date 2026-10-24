<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Storage\Repositories;

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\IssueOutcome;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Storage\Support\Values;
use Anabil\Sherlock\Storage\Tables;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * sherlock_issues + sherlock_issue_traces through the query builder.
 *
 * recordOccurrence() is race-safe without locks: the unique fingerprint + insertOrIgnore
 * decides who creates an issue, a conditional UPDATE ... WHERE status = 'resolved' decides
 * who reports a regression, and counters use atomic "col = col + 1" updates.
 */
final class DatabaseIssueRepository implements IssueRepository
{
    private const CHILD_DELETE_CHUNK = 500;

    /** Severity::weight() in SQL, for sorting by severity. */
    private const SEVERITY_WEIGHT_SQL = "(CASE severity WHEN 'critical' THEN 3 WHEN 'warning' THEN 2 WHEN 'info' THEN 1 ELSE 0 END)";

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Tables $tables,
        private readonly ?string $connection = null,
    ) {}

    public function find(int|string $id): ?IssueRecord
    {
        $row = $this->issues()->where('id', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByFingerprint(string $fingerprint): ?IssueRecord
    {
        $row = $this->issues()->where('fingerprint', $fingerprint)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function recordOccurrence(Finding $finding, array $context, DateTimeImmutable $seenAt, ?string $traceId = null, ?AiStatus $aiStatus = AiStatus::Pending): IssueOutcome
    {
        $now = Values::date($seenAt);
        $current = $this->currentState($finding->fingerprint);

        if ($current === null) {
            $inserted = $this->issues()->insertOrIgnore([
                'fingerprint' => $finding->fingerprint,
                'type' => $finding->type->value,
                'severity' => $finding->severity->value,
                'status' => IssueStatus::Open->value,
                'title' => (string) Values::truncate($finding->title, 255),
                'context' => Values::json($context),
                'occurrences' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'pending_notify_count' => 0,
                'ai_status' => $aiStatus?->value,
            ]);

            $current = $this->currentState($finding->fingerprint);

            if ($current === null) {
                // Deleted between insert and read (prune / clear); report it as new without an id.
                return new IssueOutcome(0, $finding->fingerprint, $finding->type, $finding->severity, OccurrenceType::New, 1, $traceId, IssueStatus::Open);
            }

            if ($inserted > 0) {
                return $this->outcome($current, $finding, OccurrenceType::New, $traceId);
            }

            // Another worker created it first: fall through and count this one as a repeat.
        }

        if ($current['status'] === IssueStatus::Resolved && $this->regress($current['id'], $finding, $context, $now, $aiStatus)) {
            return $this->outcome($this->currentState($finding->fingerprint) ?? $current, $finding, OccurrenceType::Regressed, $traceId);
        }

        $this->recur($current['id'], $current['status'], $finding, $context, $now);

        return $this->outcome($this->currentState($finding->fingerprint) ?? $current, $finding, OccurrenceType::Recurring, $traceId);
    }

    public function attachTrace(int|string $issueId, string $traceId, int $cap = 10): void
    {
        $connection = $this->connection();
        $links = $connection->table($this->tables->issueTraces());

        $links->insertOrIgnore([
            'issue_id' => $issueId,
            'trace_id' => $traceId,
            'created_at' => Values::date(CarbonImmutable::now()),
        ]);

        $cap = max(1, $cap);

        // The (cap + 1)-th most recent link marks the cut-off; one indexed lookup, then one delete.
        $boundary = $connection->table($this->tables->issueTraces())
            ->where('issue_id', $issueId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($cap)
            ->limit(1)
            ->value('id');

        if ($boundary !== null) {
            $connection->table($this->tables->issueTraces())
                ->where('issue_id', $issueId)
                ->where('id', '<=', $boundary)
                ->delete();
        }
    }

    public function traceIds(int|string $issueId, int $limit = 10): array
    {
        return array_values($this->connection()->table($this->tables->issueTraces())
            ->where('issue_id', $issueId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->pluck('trace_id')
            ->map(static fn (mixed $id): string => Values::string($id))
            ->all());
    }

    /**
     * @return LengthAwarePaginatorContract<int, IssueRecord>
     */
    public function paginate(IssueFilter $filter, int $perPage = 25, int $page = 1): LengthAwarePaginatorContract
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $query = $this->issues();

        if ($filter->type !== null) {
            $query->where('type', $filter->type->value);
        }

        if ($filter->severity !== null) {
            $query->where('severity', $filter->severity->value);
        }

        if ($filter->status !== null) {
            $query->where('status', $filter->status->value);
        }

        if ($filter->since !== null) {
            $query->where('last_seen_at', '>=', Values::date($filter->since));
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            $term = '%'.addcslashes(trim($filter->search), '\\%_').'%';
            $query->where(static function (Builder $where) use ($term, $filter): void {
                $where->where('title', 'like', $term)->orWhere('fingerprint', trim((string) $filter->search));
            });
        }

        $total = (clone $query)->count();

        match ($filter->sort) {
            'first_seen' => $query->orderByDesc('first_seen_at'),
            'occurrences' => $query->orderByDesc('occurrences'),
            'severity' => $query->orderByRaw(self::SEVERITY_WEIGHT_SQL.' desc')->orderByDesc('last_seen_at'),
            default => $query->orderByDesc('last_seen_at'),
        };

        $items = $total === 0 ? [] : $query->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (object $row): IssueRecord => $this->hydrate($row))
            ->values()
            ->all();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function updateStatus(int|string $id, IssueStatus $status, ?DateTimeImmutable $at = null): void
    {
        $this->issues()->where('id', $id)->update([
            'status' => $status->value,
            'resolved_at' => $status === IssueStatus::Resolved ? Values::date($at ?? CarbonImmutable::now()) : null,
        ]);
    }

    public function markNotified(int|string $id, DateTimeImmutable $at): void
    {
        $this->issues()->where('id', $id)->update([
            'last_notified_at' => Values::date($at),
            'pending_notify_count' => 0,
        ]);
    }

    public function incrementPendingNotifications(int|string $id): int
    {
        $this->issues()->where('id', $id)->increment('pending_notify_count');

        return Values::int($this->issues()->where('id', $id)->value('pending_notify_count'));
    }

    public function updateAiStatus(int|string $id, AiStatus $status): void
    {
        $this->issues()->where('id', $id)->update(['ai_status' => $status->value]);
    }

    public function saveAiAnalysis(int|string $id, AiAnalysisResult $result): void
    {
        $values = ['ai_status' => $result->status->value];

        // A failed / skipped run keeps the previous analysis (if any) for the dashboard.
        if ($result->analysis !== null) {
            $values['ai_analysis'] = $result->analysis;
        }

        if ($result->model !== null) {
            $values['ai_model'] = Values::truncate($result->model, 128);
        }

        if ($result->analyzedAt !== null) {
            $values['ai_analyzed_at'] = Values::date($result->analyzedAt);
        }

        $this->issues()->where('id', $id)->update($values);
    }

    public function newSince(DateTimeImmutable $since, int $limit = 10): array
    {
        return $this->list(
            $this->issues()->where('first_seen_at', '>=', Values::date($since))->orderByDesc('first_seen_at'),
            $limit,
        );
    }

    public function regressedSince(DateTimeImmutable $since, int $limit = 10): array
    {
        return $this->list(
            $this->issues()->where('regressed_at', '>=', Values::date($since))->orderByDesc('regressed_at'),
            $limit,
        );
    }

    public function mostFrequent(DateTimeImmutable $since, int $limit = 10): array
    {
        return $this->list(
            $this->issues()->where('last_seen_at', '>=', Values::date($since))->orderByDesc('occurrences'),
            $limit,
        );
    }

    public function countBySeverity(?IssueStatus $status = IssueStatus::Open): array
    {
        $query = $this->issues()->selectRaw('severity, COUNT(*) as aggregate_count')->groupBy('severity');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $counts = [];
        foreach ($query->get() as $row) {
            $counts[Values::string($row->severity ?? null)] = Values::int($row->aggregate_count ?? null);
        }

        return $counts;
    }

    public function pruneOlderThan(DateTimeImmutable $before, int $chunkSize = 1000): int
    {
        $connection = $this->connection();
        $cutoff = Values::date($before);
        $chunkSize = max(1, $chunkSize);
        $deleted = 0;

        do {
            $ids = $connection->table($this->tables->issues())
                ->where('last_seen_at', '<', $cutoff)
                ->orderBy('last_seen_at')
                ->limit($chunkSize)
                ->pluck('id')
                ->map(static fn (mixed $id): int|string => Values::id($id))
                ->all();

            if ($ids === []) {
                break;
            }

            foreach (array_chunk($ids, self::CHILD_DELETE_CHUNK) as $batch) {
                $connection->table($this->tables->issueTraces())->whereIn('issue_id', $batch)->delete();
            }

            $deleted += $connection->table($this->tables->issues())->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunkSize);

        return $deleted;
    }

    /**
     * Minimal state needed to decide the occurrence type (status, id, occurrences).
     *
     * @return array{id: int|string, status: IssueStatus, severity: Severity, occurrences: int}|null
     */
    private function currentState(string $fingerprint): ?array
    {
        $row = $this->issues()
            ->where('fingerprint', $fingerprint)
            ->first(['id', 'status', 'severity', 'occurrences']);

        if ($row === null) {
            return null;
        }

        return [
            'id' => Values::id($row->id ?? null),
            'status' => IssueStatus::tryFrom(Values::string($row->status ?? null)) ?? IssueStatus::Open,
            'severity' => Severity::tryFrom(Values::string($row->severity ?? null)) ?? Severity::Info,
            'occurrences' => Values::int($row->occurrences ?? null, 1),
        ];
    }

    /**
     * Reopen a resolved issue. Only the worker whose UPDATE still sees status = resolved wins.
     *
     * @param  array<string, mixed>  $context
     */
    private function regress(int|string $id, Finding $finding, array $context, string $now, ?AiStatus $aiStatus): bool
    {
        // Re-analyze on regression (PLAN §12) when an analysis will run; otherwise keep a stored
        // analysis visible and record why none will be made.
        $ai = $aiStatus === AiStatus::Pending
            ? AiStatus::Pending->value
            : $this->raw("CASE WHEN ai_status = '".AiStatus::Done->value."' THEN ai_status ELSE "
                .($aiStatus === null ? 'NULL' : "'".$aiStatus->value."'").' END');

        $affected = $this->issues()
            ->where('id', $id)
            ->where('status', IssueStatus::Resolved->value)
            ->update([
                'status' => IssueStatus::Open->value,
                'regressed_at' => $now,
                'resolved_at' => null,
                'ai_status' => $ai,
                ...$this->repeatValues($finding, $context, $now),
            ]);

        return $affected > 0;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recur(int|string $id, IssueStatus $status, Finding $finding, array $context, string $now): void
    {
        $values = $status === IssueStatus::Ignored
            ? [
                // Ignored issues only count.
                'occurrences' => $this->raw('occurrences + 1'),
                'last_seen_at' => $now,
            ]
            : $this->repeatValues($finding, $context, $now);

        $this->issues()->where('id', $id)->update($values);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function repeatValues(Finding $finding, array $context, string $now): array
    {
        $values = [
            'occurrences' => $this->raw('occurrences + 1'),
            'last_seen_at' => $now,
            'title' => (string) Values::truncate($finding->title, 255),
            'context' => Values::json($context),
        ];

        // Severity only escalates, evaluated atomically against the current row.
        match ($finding->severity) {
            Severity::Critical => $values['severity'] = Severity::Critical->value,
            Severity::Warning => $values['severity'] = $this->raw("CASE WHEN severity = 'info' THEN 'warning' ELSE severity END"),
            Severity::Info => null,
        };

        return $values;
    }

    /**
     * @param  array{id: int|string, status: IssueStatus, severity: Severity, occurrences: int}  $state
     */
    private function outcome(array $state, Finding $finding, OccurrenceType $occurrence, ?string $traceId): IssueOutcome
    {
        return new IssueOutcome(
            $state['id'],
            $finding->fingerprint,
            $finding->type,
            $state['severity'],
            $occurrence,
            $state['occurrences'],
            $traceId,
            $state['status'],
        );
    }

    /**
     * @return list<IssueRecord>
     */
    private function list(Builder $query, int $limit): array
    {
        return array_values($query->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (object $row): IssueRecord => $this->hydrate($row))
            ->all());
    }

    private function hydrate(object $row): IssueRecord
    {
        /** @var array<string, mixed> $context */
        $context = Values::fromJson($row->context ?? null) ?? [];
        $aiStatus = Values::nullableString($row->ai_status ?? null);

        return new IssueRecord(
            Values::id($row->id ?? null),
            Values::string($row->fingerprint ?? null),
            IssueType::tryFrom(Values::string($row->type ?? null)) ?? IssueType::Exception,
            Severity::tryFrom(Values::string($row->severity ?? null)) ?? Severity::Info,
            IssueStatus::tryFrom(Values::string($row->status ?? null)) ?? IssueStatus::Open,
            Values::string($row->title ?? null),
            $context,
            Values::int($row->occurrences ?? null, 1),
            Values::toDate($row->first_seen_at ?? null) ?? new DateTimeImmutable,
            Values::toDate($row->last_seen_at ?? null) ?? new DateTimeImmutable,
            Values::toDate($row->resolved_at ?? null),
            Values::toDate($row->regressed_at ?? null),
            Values::toDate($row->last_notified_at ?? null),
            Values::int($row->pending_notify_count ?? null),
            $aiStatus === null ? null : AiStatus::tryFrom($aiStatus),
            Values::nullableString($row->ai_analysis ?? null),
            Values::nullableString($row->ai_model ?? null),
            Values::toDate($row->ai_analyzed_at ?? null),
        );
    }

    /**
     * @param  literal-string  $sql
     */
    private function raw(string $sql): mixed
    {
        return $this->connection()->raw($sql);
    }

    private function issues(): Builder
    {
        return $this->connection()->table($this->tables->issues());
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
