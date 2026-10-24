<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\CacheMissRateAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\HighMemoryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowEntryAnalyzer;
use Anabil\Sherlock\Analysis\Analyzers\SlowExternalCallAnalyzer;
use Anabil\Sherlock\Data\HttpCallRecord;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;

describe('SlowEntryAnalyzer', function (): void {
    beforeEach(fn () => $this->analyzer = new SlowEntryAnalyzer(F::fingerprinter()));

    it('uses per entry type thresholds', function (EntryType $type, float $warning, float $critical, IssueType $issue): void {
        $name = $type === EntryType::Request ? '/orders' : 'App\\Jobs\\SyncOrders';

        expect($this->analyzer->analyze(F::trace(durationMs: $warning - 0.1, type: $type, name: $name)))->toBe([]);

        $atWarning = $this->analyzer->analyze(F::trace(durationMs: $warning, type: $type, name: $name));
        expect($atWarning)->toHaveCount(1)
            ->and($atWarning[0]->type)->toBe($issue)
            ->and($atWarning[0]->severity)->toBe(Severity::Warning);

        $atCritical = $this->analyzer->analyze(F::trace(durationMs: $critical, type: $type, name: $name));
        expect($atCritical[0]->severity)->toBe(Severity::Critical)
            ->and($atCritical[0]->fingerprint)->toBe($atWarning[0]->fingerprint);
    })->with([
        'request' => [EntryType::Request, 1000, 3000, IssueType::SlowRequest],
        'job' => [EntryType::Job, 10000, 60000, IssueType::SlowJob],
        'command' => [EntryType::Command, 30000, 300000, IssueType::SlowCommand],
    ]);

    it('titles the entry and points at the dominant segment', function (): void {
        $trace = F::trace([F::query('select 1', 1800)], durationMs: 2400);
        $finding = $this->analyzer->analyze($trace)[0];

        expect($finding->title)->toBe('Slow request GET /orders (2.40s)')
            ->and($finding->metrics['dominant'])->toBe('database')
            ->and($finding->recommendation)->toContain('database')->toContain('queued job');
    });

    it('accepts custom thresholds and distinguishes methods', function (): void {
        $analyzer = new SlowEntryAnalyzer(F::fingerprinter(), ['request' => ['warning_ms' => 200, 'critical_ms' => 400]]);

        $get = $analyzer->analyze(F::trace(durationMs: 250));
        $post = $analyzer->analyze(F::trace(durationMs: 250, method: 'POST'));

        expect($get)->toHaveCount(1)
            ->and($get[0]->fingerprint)->not->toBe($post[0]->fingerprint)
            ->and($analyzer->analyze(F::trace(durationMs: 9000, type: EntryType::Job, name: 'J')))->toBe([]);
    });
});

describe('HighMemoryAnalyzer', function (): void {
    it('flags peak memory at the warning and critical thresholds', function (): void {
        $analyzer = new HighMemoryAnalyzer(F::fingerprinter(), 128, 512);
        $mb = 1048576;

        expect($analyzer->analyze(F::trace(memoryPeakBytes: 127 * $mb)))->toBe([])
            ->and($analyzer->analyze(F::trace(memoryPeakBytes: 0)))->toBe([]);

        $warning = $analyzer->analyze(F::trace(memoryPeakBytes: 128 * $mb));
        expect($warning[0]->severity)->toBe(Severity::Warning)
            ->and($warning[0]->type)->toBe(IssueType::HighMemory)
            ->and($warning[0]->title)->toBe('High memory usage in GET /orders (128MB peak)')
            ->and($warning[0]->recommendation)->toContain('chunkById()');

        expect($analyzer->analyze(F::trace(memoryPeakBytes: 600 * $mb))[0]->severity)->toBe(Severity::Critical);
    });
});

describe('SlowExternalCallAnalyzer', function (): void {
    it('groups slow calls per host', function (): void {
        $analyzer = new SlowExternalCallAnalyzer(F::fingerprinter(), 1000, 5000);
        $trace = F::trace(httpCalls: [
            new HttpCallRecord('POST', 'https://api.stripe.com/v1/charges', 200, 1200),
            new HttpCallRecord('GET', 'https://api.stripe.com/v1/customers/cus_1', 200, 2300),
            new HttpCallRecord('GET', 'https://maps.example.com/geo?q=1', 500, 999),
            new HttpCallRecord('GET', 'https://slow.example.com/', null, 5000, error: 'timeout'),
        ]);

        $findings = $analyzer->analyze($trace);

        expect($findings)->toHaveCount(2)
            ->and($findings[0]->title)->toBe('Slow external call to api.stripe.com (2.30s, 2×) in GET /orders')
            ->and($findings[0]->severity)->toBe(Severity::Warning)
            ->and($findings[0]->metrics['count'])->toBe(2)
            ->and($findings[0]->metrics['host'])->toBe('api.stripe.com')
            ->and($findings[1]->severity)->toBe(Severity::Critical)
            ->and($findings[1]->metrics['failed'])->toBe(1)
            ->and($findings[0]->fingerprint)->not->toBe($findings[1]->fingerprint)
            ->and($findings[0]->recommendation)->toContain('timeout');
    });

    it('keeps the fingerprint stable across different paths and query strings', function (): void {
        $analyzer = new SlowExternalCallAnalyzer(F::fingerprinter());

        $a = $analyzer->analyze(F::trace(httpCalls: [new HttpCallRecord('GET', 'https://api.x.com/a?id=1', 200, 1500)]));
        $b = $analyzer->analyze(F::trace(httpCalls: [new HttpCallRecord('GET', 'https://API.x.com/b?id=2', 200, 3000)]));

        expect($a[0]->fingerprint)->toBe($b[0]->fingerprint);
    });
});

describe('CacheMissRateAnalyzer', function (): void {
    beforeEach(fn () => $this->analyzer = new CacheMissRateAnalyzer(F::fingerprinter(), 10, 0.5, 0.8));

    it('needs a minimum number of lookups', function (): void {
        expect($this->analyzer->analyze(F::trace(cacheHits: 0, cacheMisses: 9)))->toBe([]);
    });

    it('is info from the info ratio and warning from the warning ratio', function (): void {
        expect($this->analyzer->analyze(F::trace(cacheHits: 6, cacheMisses: 4)))->toBe([]);

        $info = $this->analyzer->analyze(F::trace(cacheHits: 5, cacheMisses: 5));
        expect($info[0]->severity)->toBe(Severity::Info)
            ->and($info[0]->type)->toBe(IssueType::CacheMissRate)
            ->and($info[0]->title)->toBe('High cache miss rate in GET /orders (50% of 10 lookups)');

        $warning = $this->analyzer->analyze(F::trace(cacheHits: 2, cacheMisses: 8));
        expect($warning[0]->severity)->toBe(Severity::Warning)
            ->and($warning[0]->fingerprint)->toBe($info[0]->fingerprint)
            ->and($warning[0]->recommendation)->toContain('Cache::remember()');
    });
});
