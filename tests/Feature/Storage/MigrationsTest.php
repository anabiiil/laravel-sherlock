<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the Sherlock tables on MySQL', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    foreach (['sherlock_traces', 'sherlock_queries', 'sherlock_issues', 'sherlock_issue_traces'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasColumns('sherlock_traces', [
        'id', 'type', 'name', 'method', 'status_code', 'duration_ms', 'db_ms', 'db_count', 'http_ms', 'http_count',
        'cache_hits', 'cache_misses', 'bootstrap_ms', 'memory_peak', 'bottleneck', 'verdict_reason', 'record_reason',
        'user_id', 'details', 'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('sherlock_queries', [
            'id', 'trace_id', 'fingerprint', 'normalized_sql', 'bindings', 'duration_ms', 'offset_ms', 'connection', 'file', 'line', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('sherlock_issues', [
            'id', 'fingerprint', 'type', 'severity', 'status', 'title', 'context', 'occurrences', 'first_seen_at', 'last_seen_at',
            'resolved_at', 'regressed_at', 'last_notified_at', 'pending_notify_count', 'ai_status', 'ai_analysis', 'ai_model', 'ai_analyzed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('sherlock_issue_traces', ['id', 'issue_id', 'trace_id', 'created_at']))->toBeTrue();
});

it('creates an index for every dashboard / report / prune query', function (): void {
    $indexes = fn (string $table): array => collect(Schema::getIndexes($table))
        ->map(fn (array $index): string => ($index['unique'] ? 'unique:' : '').implode(',', $index['columns']))
        ->all();

    expect($indexes('sherlock_traces'))->toContain('unique:id', 'created_at', 'type,name,created_at', 'bottleneck,created_at')
        ->and($indexes('sherlock_queries'))->toContain('trace_id', 'fingerprint,created_at', 'created_at')
        ->and($indexes('sherlock_issues'))->toContain('unique:fingerprint', 'status,severity,last_seen_at', 'type,last_seen_at', 'last_seen_at', 'first_seen_at', 'regressed_at')
        ->and($indexes('sherlock_issue_traces'))->toContain('unique:issue_id,trace_id', 'issue_id,created_at', 'trace_id');
});
