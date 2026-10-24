<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\ContextManager;
use Anabil\Tracing\Contracts\Fingerprinter;
use Anabil\Tracing\Contracts\QueryNormalizer;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Contracts\SourceLocator;
use Anabil\Tracing\Contracts\TraceRecorder;
use Anabil\Tracing\Data\TraceData;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\TracingMode;
use Anabil\Tracing\Facades\Tracing;
use Anabil\Tracing\Pipeline\DefaultTraceRecorder;
use Anabil\Tracing\Privacy\Redactor as PrivacyRedactor;
use Anabil\Tracing\TracingServiceProvider;
use Illuminate\Support\Facades\DB;

it('merges the package config', function (): void {
    expect(config('tracing.mode'))->toBeString()
        ->and(config('tracing.thresholds.slow_query.warning_ms'))->toBe(100)
        ->and(config('tracing.ai.drivers.anthropic.base_url'))->toBe('https://api.anthropic.com/v1')
        ->and(config('tracing.dashboard.path'))->toBe('tracing');
});

it('registers every feature provider', function (): void {
    foreach (TracingServiceProvider::FEATURE_PROVIDERS as $provider) {
        expect(app()->getProvider($provider))->not->toBeNull();
    }
});

it('binds a Null Object (or real implementation) for every contract', function (): void {
    foreach (array_keys(TracingServiceProvider::NULL_BINDINGS) as $contract) {
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
    config()->set('tracing.privacy.sensitive_keys', ['national_id']);
    app()->forgetInstance(Redactor::class);

    expect(app(Redactor::class)->redactArray(['national_id' => '123', 'password' => 'x', 'name' => 'Ann']))
        ->toBe(['national_id' => '[REDACTED]', 'password' => '[REDACTED]', 'name' => 'Ann']);
});

it('exposes the facade', function (): void {
    config()->set('tracing.mode', 'off');

    expect(Tracing::mode())->toBe(TracingMode::Off)
        ->and(Tracing::enabled())->toBeFalse()
        ->and(Tracing::isRecording())->toBeFalse();

    Tracing::tag('order', 15); // no-op on the null context
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
