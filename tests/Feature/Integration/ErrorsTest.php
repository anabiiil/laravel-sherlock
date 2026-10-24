<?php

declare(strict_types=1);

use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Tests\Feature\Integration\Support\BootsTracing;
use Anabil\Tracing\Tests\Feature\Integration\Support\Integration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(BootsTracing::class);

beforeEach(fn () => Integration::setUp('production'));

afterEach(fn () => Integration::tearDown());

it('turns an uncaught exception into one critical issue per class + location, message redacted', function (): void {
    $this->get('/integration/boom')->assertStatus(500);
    $this->get('/integration/boom')->assertStatus(500);

    // Exceptions are always persisted, even in production without sampling.
    $traces = Integration::rows('tracing_traces');
    expect($traces)->toHaveCount(2)
        ->and((int) $traces[0]['status_code'])->toBe(500)
        ->and($traces[0]['record_reason'])->toBe('threshold')
        ->and(Integration::json($traces[0]['details'])['failed'])->toBeTrue();

    $issues = Integration::rows('tracing_issues');
    expect($issues)->toHaveCount(1);

    $issue = $issues[0];
    $context = Integration::json($issue['context']);
    expect($issue['type'])->toBe(IssueType::Exception->value)
        ->and($issue['severity'])->toBe('critical')
        ->and((int) $issue['occurrences'])->toBe(2)
        ->and($context['location']['file'])->toBe(Integration::relative(Integration::controllerFile()))
        ->and($context['location']['line'])->toBe(Integration::lineOf(Integration::controllerFile(), 'boom'))
        ->and($context['exception']['class'])->toBe(RuntimeException::class)
        ->and($context['exception']['message'])->toContain('[REDACTED]')
        ->and($context['exception']['message'])->not->toContain('anna.secret@example.com');

    // Frames are relative and carry no argument values.
    foreach ($context['exception']['frames'] as $frame) {
        expect($frame['file'])->not->toStartWith('/')
            ->and($frame)->not->toHaveKey('args');
    }

    // Nothing anywhere keeps the e-mail.
    foreach (Integration::storageTables() as $table) {
        expect(json_encode(Integration::rows($table)))->not->toContain('anna.secret@example.com');
    }
});

it('turns Log::error() with context into a log_error issue with the context redacted', function (): void {
    $this->get('/integration/log-error')->assertOk();

    $issues = Integration::rows('tracing_issues');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]['type'])->toBe(IssueType::LogError->value)
        ->and($issues[0]['severity'])->toBe('warning');

    $context = Integration::json($issues[0]['context']);
    expect($context['location']['file'])->toBe(Integration::relative(Integration::controllerFile()))
        ->and($context['location']['line'])->toBe(Integration::lineOf(Integration::controllerFile(), 'log-error'));

    $logs = Integration::json(Integration::rows('tracing_traces')[0]['details'])['logs'];
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['context']['order'])->toBe(42)
        ->and($logs[0]['context']['password'])->toBe('[REDACTED]')
        ->and($logs[0]['context']['nested']['api_token'])->toBe('[REDACTED]');

    foreach (Integration::storageTables() as $table) {
        $dump = json_encode(Integration::rows($table));
        expect($dump)->not->toContain('log-context-password')
            ->and($dump)->not->toContain('log-context-token');
    }
});

it('puts the placeholders back into a QueryException message instead of the bound values', function (): void {
    Route::get('/integration/bad-insert', function (): void {
        // Not request input: only the QueryException rewrite can remove it.
        DB::insert('insert into integration_authors (id, name) values (?, ?)', [1, 'hard-coded-secret-value']);
    });

    $this->get('/integration/bad-insert')->assertStatus(500);

    $issue = Integration::rows('tracing_issues')[0];
    $message = Integration::json($issue['context'])['exception']['message'];
    expect($message)->toContain('Duplicate entry')
        ->and($message)->toContain('values (?, ?)')
        ->and($message)->not->toContain('hard-coded-secret-value');

    foreach (Integration::storageTables() as $table) {
        expect(json_encode(Integration::rows($table)))->not->toContain('hard-coded-secret-value');
    }
});
