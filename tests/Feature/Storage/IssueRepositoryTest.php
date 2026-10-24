<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Data\AiAnalysisResult;
use Anabil\Sherlock\Data\IssueFilter;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Storage\Repositories\DatabaseIssueRepository;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

function storageIssues(): DatabaseIssueRepository
{
    return app(IssueRepository::class);
}

it('is bound to the database repository', function (): void {
    expect(app(IssueRepository::class))->toBeInstanceOf(DatabaseIssueRepository::class);
});

it('creates a new issue on the first occurrence', function (): void {
    $seen = CarbonImmutable::parse('2026-10-20 10:00:00');

    $outcome = storageIssues()->recordOccurrence(F::finding(), ['entry' => ['name' => 'GET /users']], $seen, 'trace-1');
    $issue = storageIssues()->find($outcome->issueId);

    expect($outcome->occurrence)->toBe(OccurrenceType::New)
        ->and($outcome->occurrences)->toBe(1)
        ->and($outcome->traceId)->toBe('trace-1')
        ->and($outcome->status)->toBe(IssueStatus::Open)
        ->and($outcome->issueId)->toBeInt()->toBeGreaterThan(0)
        ->and($issue)->toBeInstanceOf(IssueRecord::class)
        ->and($issue->fingerprint)->toBe('issue-slow-users')
        ->and($issue->type)->toBe(IssueType::SlowQuery)
        ->and($issue->severity)->toBe(Severity::Warning)
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($issue->title)->toBe('Slow query on users')
        ->and($issue->context)->toBe(['entry' => ['name' => 'GET /users']])
        ->and($issue->firstSeenAt->format('Y-m-d H:i:s'))->toBe('2026-10-20 10:00:00')
        ->and($issue->lastSeenAt->format('Y-m-d H:i:s'))->toBe('2026-10-20 10:00:00')
        ->and($issue->aiStatus)->toBe(AiStatus::Pending)
        ->and($issue->pendingNotifyCount)->toBe(0)
        ->and(storageIssues()->findByFingerprint('issue-slow-users')?->id)->toBe($outcome->issueId)
        ->and(storageIssues()->find(999999))->toBeNull()
        ->and(storageIssues()->findByFingerprint('nope'))->toBeNull();
});

it('counts recurring occurrences atomically and updates context and last seen', function (): void {
    $first = storageIssues()->recordOccurrence(F::finding(), ['v' => 1], CarbonImmutable::parse('2026-10-20 10:00:00'));
    $second = storageIssues()->recordOccurrence(F::finding(title: 'Slow query on users (v2)'), ['v' => 2], CarbonImmutable::parse('2026-10-20 11:00:00'));

    $issue = storageIssues()->find($first->issueId);

    expect($second->occurrence)->toBe(OccurrenceType::Recurring)
        ->and($second->issueId)->toBe($first->issueId)
        ->and($second->occurrences)->toBe(2)
        ->and($issue->occurrences)->toBe(2)
        ->and($issue->title)->toBe('Slow query on users (v2)')
        ->and($issue->context)->toBe(['v' => 2])
        ->and($issue->firstSeenAt->format('H:i'))->toBe('10:00')
        ->and($issue->lastSeenAt->format('H:i'))->toBe('11:00')
        ->and(DB::table('sherlock_issues')->count())->toBe(1);
});

it('only escalates severity', function (): void {
    $id = storageIssues()->recordOccurrence(F::finding(severity: Severity::Warning), [], CarbonImmutable::now())->issueId;

    storageIssues()->recordOccurrence(F::finding(severity: Severity::Info), [], CarbonImmutable::now());
    expect(storageIssues()->find($id)->severity)->toBe(Severity::Warning);

    $outcome = storageIssues()->recordOccurrence(F::finding(severity: Severity::Critical), [], CarbonImmutable::now());
    expect(storageIssues()->find($id)->severity)->toBe(Severity::Critical)
        ->and($outcome->severity)->toBe(Severity::Critical);

    storageIssues()->recordOccurrence(F::finding(severity: Severity::Warning), [], CarbonImmutable::now());
    expect(storageIssues()->find($id)->severity)->toBe(Severity::Critical);

    $info = storageIssues()->recordOccurrence(F::finding('other', Severity::Info), [], CarbonImmutable::now())->issueId;
    storageIssues()->recordOccurrence(F::finding('other', Severity::Warning), [], CarbonImmutable::now());
    expect(storageIssues()->find($info)->severity)->toBe(Severity::Warning);
});

it('regresses a resolved issue: reopens it, sets regressed_at and resets ai_status', function (): void {
    $id = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::parse('2026-10-01 10:00:00'))->issueId;
    storageIssues()->saveAiAnalysis($id, AiAnalysisResult::done('Add an index', 'claude'));
    storageIssues()->updateStatus($id, IssueStatus::Resolved, CarbonImmutable::parse('2026-10-02 10:00:00'));

    expect(storageIssues()->find($id)->status)->toBe(IssueStatus::Resolved)
        ->and(storageIssues()->find($id)->resolvedAt?->format('Y-m-d'))->toBe('2026-10-02');

    $outcome = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::parse('2026-10-05 10:00:00'));
    $issue = storageIssues()->find($id);

    expect($outcome->occurrence)->toBe(OccurrenceType::Regressed)
        ->and($outcome->status)->toBe(IssueStatus::Open)
        ->and($outcome->occurrences)->toBe(2)
        ->and($issue->status)->toBe(IssueStatus::Open)
        ->and($issue->resolvedAt)->toBeNull()
        ->and($issue->regressedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-05 10:00:00')
        ->and($issue->aiStatus)->toBe(AiStatus::Pending)
        ->and($issue->aiAnalysis)->toBe('Add an index');

    // The next occurrence is a plain repeat again.
    expect(storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now())->occurrence)->toBe(OccurrenceType::Recurring);
});

it('only counts occurrences of ignored issues', function (): void {
    $id = storageIssues()->recordOccurrence(F::finding(severity: Severity::Info), ['v' => 1], CarbonImmutable::parse('2026-10-01 10:00:00'))->issueId;
    storageIssues()->updateStatus($id, IssueStatus::Ignored);

    $outcome = storageIssues()->recordOccurrence(F::finding(severity: Severity::Critical, title: 'changed'), ['v' => 2], CarbonImmutable::parse('2026-10-03 10:00:00'));
    $issue = storageIssues()->find($id);

    expect($outcome->occurrence)->toBe(OccurrenceType::Recurring)
        ->and($outcome->status)->toBe(IssueStatus::Ignored)
        ->and($outcome->isIgnored())->toBeTrue()
        ->and($issue->status)->toBe(IssueStatus::Ignored)
        ->and($issue->occurrences)->toBe(2)
        ->and($issue->severity)->toBe(Severity::Info)
        ->and($issue->title)->toBe('Slow query on users')
        ->and($issue->context)->toBe(['v' => 1])
        ->and($issue->lastSeenAt->format('Y-m-d'))->toBe('2026-10-03');
});

it('counts a pre-existing fingerprint as recurring without creating a duplicate', function (): void {
    DB::table('sherlock_issues')->insert([
        'fingerprint' => 'issue-slow-users', 'type' => 'slow_query', 'severity' => 'warning', 'status' => 'open',
        'title' => 'Existing', 'context' => '{}', 'occurrences' => 4,
        'first_seen_at' => '2026-10-01 00:00:00', 'last_seen_at' => '2026-10-01 00:00:00',
    ]);

    $a = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now());
    $b = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now());

    expect($a->occurrence)->toBe(OccurrenceType::Recurring)
        ->and($b->occurrence)->toBe(OccurrenceType::Recurring)
        ->and($b->occurrences)->toBe(6)
        ->and(DB::table('sherlock_issues')->count())->toBe(1);
});

it('is race safe when another worker inserts the same new fingerprint first', function (): void {
    $raced = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$raced): void {
        if ($raced || ! str_contains($sql, 'insert ignore into `sherlock_issues`')) {
            return;
        }

        $raced = true;
        // The "other worker" wins the insert between our SELECT and our INSERT IGNORE.
        DB::table('sherlock_issues')->insert([
            'fingerprint' => 'issue-slow-users', 'type' => 'slow_query', 'severity' => 'warning', 'status' => 'open',
            'title' => 'Other worker', 'context' => '{}', 'occurrences' => 1,
            'first_seen_at' => '2026-10-01 00:00:00', 'last_seen_at' => '2026-10-01 00:00:00',
        ]);
    });

    $outcome = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now());

    expect($raced)->toBeTrue()
        ->and($outcome->occurrence)->toBe(OccurrenceType::Recurring)
        ->and($outcome->occurrences)->toBe(2)
        ->and(DB::table('sherlock_issues')->count())->toBe(1)
        ->and((int) DB::table('sherlock_issues')->value('occurrences'))->toBe(2);
});

it('lets only one worker report a regression', function (): void {
    $id = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now())->issueId;
    storageIssues()->updateStatus($id, IssueStatus::Resolved);

    $raced = false;
    DB::connection()->beforeExecuting(function (string $sql) use (&$raced, $id): void {
        if ($raced || ! str_contains($sql, 'update `sherlock_issues`') || ! str_contains($sql, '`status` = ?')) {
            return;
        }

        $raced = true;
        // Another worker already reopened it.
        DB::table('sherlock_issues')->where('id', $id)->update(['status' => 'open', 'regressed_at' => now()]);
    });

    $outcome = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now());

    expect($raced)->toBeTrue()
        ->and($outcome->occurrence)->toBe(OccurrenceType::Recurring)
        ->and(storageIssues()->find($id)->occurrences)->toBe(2);
});

it('attaches example traces keeping only the most recent ones', function (): void {
    $id = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now())->issueId;

    foreach (['t1', 't2', 't3', 't4', 't5'] as $i => $trace) {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00')->addMinutes($i));
        storageIssues()->attachTrace($id, $trace, 3);
    }
    storageIssues()->attachTrace($id, 't5', 3); // duplicate link is ignored

    expect(storageIssues()->traceIds($id))->toBe(['t5', 't4', 't3'])
        ->and(storageIssues()->traceIds($id, 2))->toBe(['t5', 't4'])
        ->and(DB::table('sherlock_issue_traces')->where('issue_id', $id)->count())->toBe(3);
});

it('paginates issues with filters, search and sorting', function (): void {
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    $a = storageIssues()->recordOccurrence(F::finding('a', Severity::Info, title: 'Cache misses on home'), [], $now->subDays(3))->issueId;
    $b = storageIssues()->recordOccurrence(F::finding('b', Severity::Critical, IssueType::NPlusOne, 'N+1 on orders'), [], $now->subDays(2))->issueId;
    $c = storageIssues()->recordOccurrence(F::finding('c', Severity::Warning, IssueType::NPlusOne, 'N+1 on users_100%'), [], $now->subDay())->issueId;
    storageIssues()->recordOccurrence(F::finding('a', Severity::Info), [], $now->subDays(3));
    storageIssues()->recordOccurrence(F::finding('a', Severity::Info), [], $now->subDays(3));
    storageIssues()->updateStatus($b, IssueStatus::Resolved);

    $ids = fn (LengthAwarePaginator $page): array => array_map(fn (IssueRecord $i) => $i->id, $page->items());

    $page = storageIssues()->paginate(new IssueFilter, 2, 1);

    expect($page)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($page->total())->toBe(3)
        ->and($ids($page))->toBe([$c, $b])
        ->and($ids(storageIssues()->paginate(new IssueFilter, 2, 2)))->toBe([$a])
        ->and($ids(storageIssues()->paginate(new IssueFilter(sort: 'first_seen'))))->toBe([$c, $b, $a])
        ->and($ids(storageIssues()->paginate(new IssueFilter(sort: 'occurrences')))[0])->toBe($a)
        ->and($ids(storageIssues()->paginate(new IssueFilter(sort: 'severity'))))->toBe([$b, $c, $a])
        ->and($ids(storageIssues()->paginate(new IssueFilter(type: IssueType::NPlusOne))))->toBe([$c, $b])
        ->and($ids(storageIssues()->paginate(new IssueFilter(severity: Severity::Info))))->toBe([$a])
        ->and($ids(storageIssues()->paginate(new IssueFilter(status: IssueStatus::Resolved))))->toBe([$b])
        ->and($ids(storageIssues()->paginate(new IssueFilter(search: 'orders'))))->toBe([$b])
        ->and($ids(storageIssues()->paginate(new IssueFilter(search: '100%'))))->toBe([$c])
        ->and($ids(storageIssues()->paginate(new IssueFilter(search: 'a'))))->toContain($a)
        ->and($ids(storageIssues()->paginate(new IssueFilter(since: $now->subHours(36)))))->toBe([$c]);
});

it('updates status, notification and AI fields', function (): void {
    $id = storageIssues()->recordOccurrence(F::finding(), [], CarbonImmutable::now())->issueId;

    expect(storageIssues()->incrementPendingNotifications($id))->toBe(1)
        ->and(storageIssues()->incrementPendingNotifications($id))->toBe(2);

    storageIssues()->markNotified($id, CarbonImmutable::parse('2026-10-20 09:30:00'));
    $issue = storageIssues()->find($id);
    expect($issue->pendingNotifyCount)->toBe(0)
        ->and($issue->lastNotifiedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-20 09:30:00');

    storageIssues()->updateAiStatus($id, AiStatus::SkippedBudget);
    expect(storageIssues()->find($id)->aiStatus)->toBe(AiStatus::SkippedBudget);

    storageIssues()->saveAiAnalysis($id, AiAnalysisResult::done('Root cause: missing index', 'claude-x', CarbonImmutable::parse('2026-10-20 10:00:00')));
    $issue = storageIssues()->find($id);
    expect($issue->aiStatus)->toBe(AiStatus::Done)
        ->and($issue->aiAnalysis)->toBe('Root cause: missing index')
        ->and($issue->aiModel)->toBe('claude-x')
        ->and($issue->aiAnalyzedAt?->format('Y-m-d H:i'))->toBe('2026-10-20 10:00')
        ->and($issue->hasAiAnalysis())->toBeTrue();

    storageIssues()->saveAiAnalysis($id, AiAnalysisResult::failed('timeout'));
    expect(storageIssues()->find($id)->aiStatus)->toBe(AiStatus::Failed)
        ->and(storageIssues()->find($id)->aiAnalysis)->toBe('Root cause: missing index');

    storageIssues()->updateStatus($id, IssueStatus::Resolved);
    expect(storageIssues()->find($id)->resolvedAt)->not->toBeNull();
    storageIssues()->updateStatus($id, IssueStatus::Open);
    expect(storageIssues()->find($id)->resolvedAt)->toBeNull()
        ->and(storageIssues()->find($id)->status)->toBe(IssueStatus::Open);
});

it('lists new, regressed and most frequent issues and counts by severity', function (): void {
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    $old = storageIssues()->recordOccurrence(F::finding('old', Severity::Warning), [], $now->subDays(10))->issueId;
    $new = storageIssues()->recordOccurrence(F::finding('new', Severity::Critical), [], $now->subHour())->issueId;
    $freq = storageIssues()->recordOccurrence(F::finding('freq', Severity::Warning), [], $now->subDays(10))->issueId;
    foreach (range(1, 3) as $_) {
        storageIssues()->recordOccurrence(F::finding('freq', Severity::Warning), [], $now->subMinutes(5));
    }
    storageIssues()->updateStatus($old, IssueStatus::Resolved);
    storageIssues()->recordOccurrence(F::finding('old', Severity::Warning), [], $now->subMinutes(30));
    storageIssues()->recordOccurrence(F::finding('ign', Severity::Info), [], $now);
    storageIssues()->updateStatus(storageIssues()->findByFingerprint('ign')->id, IssueStatus::Ignored);

    $since = $now->subDay();
    $ids = fn (array $issues): array => array_map(fn (IssueRecord $i) => $i->id, $issues);

    expect($ids(storageIssues()->newSince($since)))->toContain($new)->not->toContain($old)
        ->and($ids(storageIssues()->regressedSince($since)))->toBe([$old])
        ->and($ids(storageIssues()->mostFrequent($since))[0])->toBe($freq)
        ->and(storageIssues()->mostFrequent($since, 1))->toHaveCount(1)
        ->and(storageIssues()->countBySeverity())->toEqualCanonicalizing(['warning' => 2, 'critical' => 1])
        ->and(storageIssues()->countBySeverity(IssueStatus::Ignored))->toBe(['info' => 1])
        ->and(array_sum(storageIssues()->countBySeverity(null)))->toBe(4);
});

it('prunes issues last seen before the cutoff with their trace links', function (): void {
    $now = CarbonImmutable::parse('2026-10-20 12:00:00');
    foreach (range(1, 5) as $i) {
        $id = storageIssues()->recordOccurrence(F::finding("old-{$i}"), [], $now->subDays(100))->issueId;
        storageIssues()->attachTrace($id, "t{$i}");
    }
    $keep = storageIssues()->recordOccurrence(F::finding('keep'), [], $now->subDays(1))->issueId;
    storageIssues()->attachTrace($keep, 'tk');

    expect(storageIssues()->pruneOlderThan($now->subDays(90), 2))->toBe(5)
        ->and(DB::table('sherlock_issues')->pluck('id')->all())->toBe([$keep])
        ->and(DB::table('sherlock_issue_traces')->pluck('trace_id')->all())->toBe(['tk']);
});
