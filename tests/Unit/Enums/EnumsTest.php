<?php

declare(strict_types=1);

use Anabil\Sherlock\Enums\AiStatus;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\OccurrenceType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Enums\SherlockMode;

it('resolves the Sherlock mode from config values', function (mixed $value, SherlockMode $expected): void {
    expect(SherlockMode::fromConfig($value))->toBe($expected);
})->with([
    ['off', SherlockMode::Off],
    ['LOCAL', SherlockMode::Local],
    [' production ', SherlockMode::Production],
    ['nonsense', SherlockMode::Production],
    [null, SherlockMode::Production],
    [false, SherlockMode::Off],
    [true, SherlockMode::Production],
    [SherlockMode::Local, SherlockMode::Local],
]);

it('knows which modes are enabled', function (): void {
    expect(SherlockMode::Off->isEnabled())->toBeFalse()
        ->and(SherlockMode::Off->isOff())->toBeTrue()
        ->and(SherlockMode::Local->isEnabled())->toBeTrue()
        ->and(SherlockMode::Local->recordsEverything())->toBeTrue()
        ->and(SherlockMode::Production->recordsEverything())->toBeFalse();
});

it('orders severities by weight', function (): void {
    expect(Severity::Info->weight())->toBeLessThan(Severity::Warning->weight())
        ->and(Severity::Warning->weight())->toBeLessThan(Severity::Critical->weight())
        ->and(Severity::Critical->isAtLeast(Severity::Warning))->toBeTrue()
        ->and(Severity::Warning->isAtLeast(Severity::Warning))->toBeTrue()
        ->and(Severity::Info->isAtLeast(Severity::Warning))->toBeFalse()
        ->and(Severity::Critical->isHigherThan(Severity::Critical))->toBeFalse()
        ->and(Severity::max(Severity::Info, Severity::Critical))->toBe(Severity::Critical)
        ->and(Severity::max(Severity::Warning, Severity::Info))->toBe(Severity::Warning)
        ->and(Severity::Critical->label())->toBe('Critical')
        ->and(Severity::Info->emoji())->not->toBe('');
});

it('resolves severities from config with a default', function (): void {
    expect(Severity::fromConfig('critical'))->toBe(Severity::Critical)
        ->and(Severity::fromConfig(' WARNING '))->toBe(Severity::Warning)
        ->and(Severity::fromConfig('bogus'))->toBe(Severity::Warning)
        ->and(Severity::fromConfig(null, Severity::Info))->toBe(Severity::Info)
        ->and(Severity::fromConfig(Severity::Info))->toBe(Severity::Info);
});

it('maps entry types to slow issue types', function (): void {
    expect(EntryType::Request->slowIssueType())->toBe(IssueType::SlowRequest)
        ->and(EntryType::Job->slowIssueType())->toBe(IssueType::SlowJob)
        ->and(EntryType::Command->slowIssueType())->toBe(IssueType::SlowCommand)
        ->and(EntryType::Job->label())->toBe('Job');
});

it('classifies issue types', function (): void {
    expect(IssueType::Exception->isError())->toBeTrue()
        ->and(IssueType::LogError->isError())->toBeTrue()
        ->and(IssueType::SlowQuery->isError())->toBeFalse()
        ->and(IssueType::SlowQuery->isPerformance())->toBeTrue()
        ->and(IssueType::NPlusOne->isQueryRelated())->toBeTrue()
        ->and(IssueType::MissingIndex->isQueryRelated())->toBeTrue()
        ->and(IssueType::SlowRequest->isQueryRelated())->toBeFalse()
        ->and(IssueType::ServerLoad->isPerformance())->toBeTrue()
        ->and(IssueType::MemoryLeak->isQueryRelated())->toBeFalse()
        ->and(IssueType::cases())->toHaveCount(16);

    foreach (IssueType::cases() as $type) {
        expect($type->label())->not->toBe('');
    }
});

it('has the issue statuses and bottlenecks from the plan', function (): void {
    expect(array_map(fn ($c) => $c->value, IssueStatus::cases()))->toBe(['open', 'resolved', 'ignored'])
        ->and(IssueStatus::Open->isOpen())->toBeTrue()
        ->and(IssueStatus::Resolved->label())->toBe('Resolved')
        ->and(array_map(fn ($c) => $c->value, Bottleneck::cases()))
        ->toBe(['database', 'application', 'external_service', 'cache', 'bootstrap', 'server_load', 'queue_backlog', 'waiting', 'mixed', 'unknown'])
        ->and(Bottleneck::Application->label())->toBe('Application / Server')
        ->and(Bottleneck::ServerLoad->label())->toBe('Server load')
        ->and(Bottleneck::QueueBacklog->label())->toBe('Queue backlog')
        ->and(Bottleneck::Waiting->label())->toBe('Waiting / blocked');
});

it('describes AI statuses', function (): void {
    expect(AiStatus::SkippedBudget->isSkipped())->toBeTrue()
        ->and(AiStatus::SkippedSeverity->isSkipped())->toBeTrue()
        ->and(AiStatus::Failed->isSkipped())->toBeFalse()
        ->and(AiStatus::Done->hasAnalysis())->toBeTrue()
        ->and(AiStatus::Pending->hasAnalysis())->toBeFalse()
        ->and(AiStatus::SkippedBudget->label())->toBe('Skipped budget');
});

it('ranks record reasons', function (): void {
    expect(RecordReason::Threshold->forcesPersistence())->toBeFalse()
        ->and(RecordReason::Sample->forcesPersistence())->toBeTrue()
        ->and(RecordReason::Watch->impliesFullCapture())->toBeTrue()
        ->and(RecordReason::Sample->impliesFullCapture())->toBeFalse()
        ->and(RecordReason::primary([RecordReason::Sample, RecordReason::Header]))->toBe(RecordReason::Header)
        ->and(RecordReason::primary([RecordReason::Sample, RecordReason::Threshold]))->toBe(RecordReason::Threshold)
        ->and(RecordReason::primary([]))->toBeNull();
});

it('flags noteworthy occurrences', function (): void {
    expect(OccurrenceType::New->isNoteworthy())->toBeTrue()
        ->and(OccurrenceType::Regressed->isNoteworthy())->toBeTrue()
        ->and(OccurrenceType::Recurring->isNoteworthy())->toBeFalse();
});
