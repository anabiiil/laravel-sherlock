<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Analyzers\ExceptionAnalyzer;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Support\BacktraceSourceLocator;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;

function exceptionLog(string $message, string $class = 'RuntimeException', string $file = 'app/Services/Billing.php', int $line = 42, array $frames = []): LogRecord
{
    return new LogRecord('error', $message, $class, $file, $line, $frames);
}

beforeEach(fn () => $this->analyzer = new ExceptionAnalyzer(F::fingerprinter(), new BacktraceSourceLocator('/var/www/app')));

it('reports one critical finding per distinct exception class and location', function (): void {
    $trace = F::trace(logs: [
        exceptionLog('Payment 1 failed'),
        exceptionLog('Payment 2 failed'),
        exceptionLog('Other', 'LogicException'),
        exceptionLog('Elsewhere', line: 77),
    ]);

    $findings = $this->analyzer->analyze($trace);

    expect($findings)->toHaveCount(3)
        ->and($findings[0]->type)->toBe(IssueType::Exception)
        ->and($findings[0]->severity)->toBe(Severity::Critical)
        ->and($findings[0]->title)->toBe('RuntimeException in app/Services/Billing.php:42')
        ->and($findings[0]->metrics['count'])->toBe(2)
        ->and($findings[0]->recommendation)->not->toBeEmpty();
});

it('keeps the fingerprint stable across messages, routes and absolute paths, but not across lines', function (): void {
    $a = $this->analyzer->analyze(F::trace(logs: [exceptionLog('Order 15 not found for user a@b.c')]))[0];
    $b = $this->analyzer->analyze(F::trace(name: '/other', logs: [exceptionLog('Order 99 not found', file: '/var/www/app/app/Services/Billing.php')]))[0];
    $c = $this->analyzer->analyze(F::trace(logs: [exceptionLog('Order 15 not found', line: 43)]))[0];

    expect($a->fingerprint)->toBe($b->fingerprint)
        ->and($a->fingerprint)->toBe(F::fingerprinter()->issue(IssueType::Exception, null, $a->location, null, 'RuntimeException'))
        ->and($a->fingerprint)->not->toBe($c->fingerprint);
});

it('locates the first application frame, skipping vendor frames', function (): void {
    $frames = [
        ['file' => 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 'line' => 800, 'class' => 'Illuminate\\Database\\Connection', 'function' => 'runQueryCallback'],
        ['file' => 'app/Repositories/OrderRepository.php', 'line' => 31, 'class' => 'App\\Repositories\\OrderRepository', 'function' => 'find'],
    ];
    $log = exceptionLog('SQLSTATE[42S02]', 'Illuminate\\Database\\QueryException', 'vendor/laravel/framework/src/Illuminate/Database/Connection.php', 800, $frames);

    $finding = $this->analyzer->analyze(F::trace(logs: [$log]))[0];

    expect($finding->location?->toString())->toBe('app/Repositories/OrderRepository.php:31')
        ->and($finding->title)->toBe('QueryException in app/Repositories/OrderRepository.php:31')
        ->and($finding->recommendation)->toContain('database query failed');
});

it('maps log levels to configurable severities and ignores unmapped levels', function (): void {
    $analyzer = new ExceptionAnalyzer(F::fingerprinter(), null, ['error' => 'info', 'critical' => 'critical']);
    $trace = F::trace(logs: [
        new LogRecord('error', 'Sync failed for shop 12', file: 'app/Jobs/Sync.php', line: 10),
        new LogRecord('error', 'Sync failed for shop 13', file: 'app/Jobs/Sync.php', line: 10),
        new LogRecord('critical', 'Disk full', file: 'app/Jobs/Sync.php', line: 20),
        new LogRecord('warning', 'Slow', file: 'app/Jobs/Sync.php', line: 30),
    ]);

    $findings = $analyzer->analyze($trace);

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->type)->toBe(IssueType::LogError)
        ->and($findings[0]->severity)->toBe(Severity::Info)
        ->and($findings[0]->metrics['count'])->toBe(2)
        ->and($findings[0]->title)->toStartWith('Log error in app/Jobs/Sync.php:10: Sync failed')
        ->and($findings[1]->severity)->toBe(Severity::Critical);
});

it('defaults error logs to warning and builds stable templates', function (): void {
    $a = $this->analyzer->analyze(F::trace(logs: [new LogRecord('error', "User 5 'ann' failed", file: 'app/A.php', line: 1)]))[0];
    $b = $this->analyzer->analyze(F::trace(logs: [new LogRecord('error', "User 77 'bob' failed", file: 'app/A.php', line: 1)]))[0];

    expect($a->severity)->toBe(Severity::Warning)
        ->and($a->fingerprint)->toBe($b->fingerprint)
        ->and(ExceptionAnalyzer::messageTemplate('Order 9f1c2a7e-3b4d-4c5e-8f90-123456789abc took 12.5s'))->toBe('Order ? took ?s');
});

it('returns nothing without logs', function (): void {
    expect($this->analyzer->analyze(F::trace()))->toBe([]);
});
