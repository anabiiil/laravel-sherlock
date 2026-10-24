<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\ContextManager;
use Anabil\Sherlock\Contracts\Fingerprinter;
use Anabil\Sherlock\Contracts\QueryNormalizer;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Contracts\SourceLocator;
use Anabil\Sherlock\Contracts\TraceRecorder;
use Anabil\Sherlock\Data\TraceData;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\SherlockMode;
use Anabil\Sherlock\Facades\Sherlock;
use Anabil\Sherlock\Pipeline\DefaultTraceRecorder;
use Anabil\Sherlock\Privacy\Redactor as PrivacyRedactor;
use Anabil\Sherlock\SherlockServiceProvider;
use Illuminate\Support\Facades\DB;

it('merges the package config', function (): void {
    expect(config('sherlock.mode'))->toBeString()
        ->and(config('sherlock.thresholds.slow_query.warning_ms'))->toBe(100)
        ->and(config('sherlock.ai.drivers.anthropic.base_url'))->toBe('https://api.anthropic.com/v1')
        ->and(config('sherlock.dashboard.path'))->toBe('sherlock');
});

it('registers every feature provider', function (): void {
    foreach (SherlockServiceProvider::FEATURE_PROVIDERS as $provider) {
        expect(app()->getProvider($provider))->not->toBeNull();
    }
});

it('binds a Null Object (or real implementation) for every contract', function (): void {
    foreach (array_keys(SherlockServiceProvider::NULL_BINDINGS) as $contract) {
        expect(app($contract))->toBeInstanceOf($contract);
    }
});

it('binds the core services', function (): void {
    expect(app(Redactor::class))->toBeInstanceOf(PrivacyRedactor::class)
        ->and(app(QueryNormalizer::class))->toBeInstanceOf(QueryNormalizer::class)
        ->and(app(Fingerprinter::class))->toBeInstanceOf(Fingerprinter::class)
        ->and(app(SourceLocator::class))->toBeInstanceOf(SourceLocator::class)
        ->and(app(TraceRecorder::class))->toBeInstanceOf(DefaultTraceRecorder::class)
        ->and(app(Redactor::class))->toBe(app(Redactor::class));
});

it('applies user sensitive keys from config to the redactor', function (): void {
    config()->set('sherlock.privacy.sensitive_keys', ['national_id']);
    app()->forgetInstance(Redactor::class);

    expect(app(Redactor::class)->redactArray(['national_id' => '123', 'password' => 'x', 'name' => 'Ann']))
        ->toBe(['national_id' => '[REDACTED]', 'password' => '[REDACTED]', 'name' => 'Ann']);
});

it('exposes the facade', function (): void {
    config()->set('sherlock.mode', 'off');

    expect(Sherlock::mode())->toBe(SherlockMode::Off)
        ->and(Sherlock::enabled())->toBeFalse()
        ->and(Sherlock::isRecording())->toBeFalse();

    Sherlock::tag('order', 15); // no-op on the null context
});

it('records nothing through the null pipeline without throwing', function (): void {
    app(TraceRecorder::class)->record(new TraceData('01J', EntryType::Request, 'GET /', new DateTimeImmutable, 12.0));

    expect(app(ContextManager::class)->isRecording())->toBeFalse();
});

it('runs on the MySQL test database', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and((int) DB::selectOne('select 1 as one')->one)->toBe(1)
        ->and(DB::connection()->getDatabaseName())->toStartWith('tracing_test');
});
