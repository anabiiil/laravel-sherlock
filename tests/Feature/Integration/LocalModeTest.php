<?php

declare(strict_types=1);

use Anabil\Tracing\Enums\Bottleneck;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Tests\Feature\Integration\Support\BootsTracing;
use Anabil\Tracing\Tests\Feature\Integration\Support\Integration;
use Illuminate\Support\Facades\Http;

uses(BootsTracing::class);

beforeEach(function (): void {
    Integration::setUp('local');
    Http::fake(['api.example.test/*' => Http::response(['rate' => 1.1])]);
});

afterEach(fn () => Integration::tearDown());

/** @return array<string, array<string, mixed>> issues keyed by type */
function localIssuesByType(): array
{
    $issues = [];
    foreach (Integration::rows('tracing_issues') as $row) {
        $issues[(string) $row['type']] = $row;
    }

    return $issues;
}

it('records a real request end to end: trace, queries, n+1 and slow query issues, verdict', function (): void {
    $this->get('/integration/report')->assertOk()->assertJson(['authors' => Integration::POSTS]);

    // Trace row.
    $traces = Integration::rows('tracing_traces');
    expect($traces)->toHaveCount(1);
    $trace = $traces[0];

    expect($trace['type'])->toBe('request')
        ->and($trace['name'])->toBe('GET /integration/report')
        ->and($trace['method'])->toBe('GET')
        ->and((int) $trace['status_code'])->toBe(200)
        ->and($trace['record_reason'])->toBe('local')
        // 1 posts query + 8 lazy author loads + 1 slow query.
        ->and((int) $trace['db_count'])->toBe(Integration::POSTS + 2)
        ->and((int) $trace['http_count'])->toBe(1)
        ->and((float) $trace['db_ms'])->toBeGreaterThan(Integration::SLOW_QUERY_MS)
        ->and((float) $trace['duration_ms'])->toBeGreaterThanOrEqual((float) $trace['db_ms'])
        ->and($trace['bottleneck'])->not->toBe('')
        ->and(Bottleneck::tryFrom((string) $trace['bottleneck']))->not->toBeNull()
        ->and($trace['verdict_reason'])->toBeString()->not->toBe('');

    $details = Integration::json($trace['details']);
    expect($details['route'])->toBe('integration.report')
        ->and($details['uri'])->toBe('/integration/report')
        ->and($details['full'])->toBeTrue()
        ->and($details['http_calls'])->toHaveCount(1)
        ->and($details['http_calls'][0]['url'])->not->toContain('integration-url-token');

    // Query rows: every query stored, with bindings (local = full capture) and locations.
    $queries = Integration::rows('tracing_queries');
    expect($queries)->toHaveCount(Integration::POSTS + 2);

    $controller = Integration::relative(Integration::controllerFile());
    $nPlusOneLine = Integration::lineOf(Integration::controllerFile(), 'n-plus-one');
    $authorQueries = array_values(array_filter($queries, static fn (array $q): bool => str_contains((string) $q['normalized_sql'], 'integration_authors')));
    expect($authorQueries)->toHaveCount(Integration::POSTS)
        ->and(count(array_unique(array_column($authorQueries, 'fingerprint'))))->toBe(1)
        ->and($authorQueries[0]['file'])->toBe($controller)
        ->and((int) $authorQueries[0]['line'])->toBe($nPlusOneLine)
        ->and(Integration::json($authorQueries[0]['bindings']))->toBe([1]);

    // Issues: N+1 and slow query, located in the controller.
    $issues = localIssuesByType();
    expect($issues)->toHaveKeys([IssueType::NPlusOne->value, IssueType::SlowQuery->value]);

    $nPlusOne = $issues[IssueType::NPlusOne->value];
    $context = Integration::json($nPlusOne['context']);
    expect($nPlusOne['severity'])->toBe('warning')
        ->and($nPlusOne['status'])->toBe('open')
        ->and((int) $nPlusOne['occurrences'])->toBe(1)
        ->and($context['location']['file'])->toBe($controller)
        ->and($context['location']['line'])->toBe($nPlusOneLine)
        ->and($context['metrics']['count'])->toBe(Integration::POSTS)
        ->and($context['entry']['name'])->toBe('GET /integration/report')
        ->and($context['verdict']['bottleneck'])->toBe($trace['bottleneck']);

    $slow = $issues[IssueType::SlowQuery->value];
    $slowContext = Integration::json($slow['context']);
    expect($slowContext['location']['file'])->toBe($controller)
        ->and($slowContext['location']['line'])->toBe(Integration::lineOf(Integration::controllerFile(), 'slow-query'))
        ->and($slowContext['metrics']['time_ms'])->toBeGreaterThanOrEqual((float) Integration::SLOW_QUERY_MS);

    // Every issue is linked to the trace.
    $links = Integration::rows('tracing_issue_traces');
    expect(array_unique(array_column($links, 'trace_id')))->toBe([$trace['id']])
        ->and(count($links))->toBe(count(Integration::rows('tracing_issues')));
});

it('counts a repeated request as the same issue, without duplicate issue rows', function (): void {
    $this->get('/integration/report')->assertOk();
    $first = localIssuesByType();

    $this->get('/integration/report')->assertOk();
    $this->get('/integration/report')->assertOk();
    $after = localIssuesByType();

    expect(Integration::count('tracing_traces'))->toBe(3)
        ->and(Integration::count('tracing_issues'))->toBe(count($first));

    foreach ([IssueType::NPlusOne->value, IssueType::SlowQuery->value] as $type) {
        expect($after[$type]['fingerprint'])->toBe($first[$type]['fingerprint'])
            ->and($after[$type]['id'])->toBe($first[$type]['id'])
            ->and((int) $after[$type]['occurrences'])->toBe(3);
    }

    // Each issue keeps one link per trace.
    $nPlusOneId = $after[IssueType::NPlusOne->value]['id'];
    expect(count(array_filter(Integration::rows('tracing_issue_traces'), static fn (array $l): bool => $l['issue_id'] === $nPlusOneId)))->toBe(3);
});
