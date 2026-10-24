<?php

declare(strict_types=1);

use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Tests\Feature\Capture\Support\Capture;
use Anabil\Sherlock\Tests\Feature\Capture\Support\CaptureJob;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('sherlock.mode', 'production');
    config()->set('sherlock.sample_rate', 0.0);

    $this->recorder = Capture::fakeRecorder();
});

/**
 * Unit tests do not reroute Symfony console events to CommandStarting / CommandFinished;
 * real artisan runs do. Enable it and rebuild the artisan application.
 */
function captureRerouteCommandEvents(): void
{
    $kernel = app(ConsoleKernel::class);
    $kernel->setArtisan(null);
    $kernel->rerouteSymfonyCommandEvents();
}

it('captures a sync queue job as its own trace', function (): void {
    dispatch(new CaptureJob(3));

    $trace = $this->recorder->last();

    expect($this->recorder->traces)->toHaveCount(1)
        ->and($trace->type)->toBe(EntryType::Job)
        ->and($trace->name)->toBe(CaptureJob::class)
        ->and($trace->queryCount())->toBe(3)
        ->and($trace->failed)->toBeFalse()
        ->and($trace->metadata['connection'])->toBe('sync')
        ->and($trace->metadata['attempts'])->toBe(1);
});

it('captures a failing job with its exception once, redacted', function (): void {
    expect(fn () => dispatch(new CaptureJob(1, fail: true)))->toThrow(RuntimeException::class);

    // The framework reports the exception after the job ended: it must not become a second trace.
    report(new RuntimeException('unrelated'));
    $jobTrace = $this->recorder->traces[0];

    expect($jobTrace->type)->toBe(EntryType::Job)
        ->and($jobTrace->failed)->toBeTrue()
        ->and($jobTrace->exceptions())->toHaveCount(1)
        ->and($jobTrace->exceptions()[0]->exceptionClass)->toBe(RuntimeException::class)
        ->and($this->recorder->json())->not->toContain('Hunter2-Secret!');
});

it('does not record a reported job exception twice', function (): void {
    $job = new CaptureJob(1, fail: true);

    try {
        dispatch($job);
    } catch (RuntimeException $e) {
        report($e);
    }
    app()->terminate();

    expect($this->recorder->traces)->toHaveCount(1)
        ->and($this->recorder->last()->exceptions())->toHaveCount(1);
});

it('ignores the package\'s own jobs', function (): void {
    config()->set('sherlock.ignore.jobs', [CaptureJob::class]);

    dispatch(new CaptureJob(2));

    expect($this->recorder->traces)->toBe([]);
});

it('captures an artisan command with its exit code', function (): void {
    captureRerouteCommandEvents();
    Artisan::command('capture:demo', function () {
        DB::select('select 1');
        DB::select('select 2');

        return 3;
    });

    Artisan::call('capture:demo');
    app()->terminate();

    $trace = $this->recorder->last();

    expect($this->recorder->traces)->toHaveCount(1)
        ->and($trace->type)->toBe(EntryType::Command)
        ->and($trace->name)->toBe('capture:demo')
        ->and($trace->statusCode)->toBe(3)
        ->and($trace->failed)->toBeTrue()
        ->and($trace->queryCount())->toBe(2);
});

it('attaches an exception reported after the command finished to the command trace', function (): void {
    captureRerouteCommandEvents();
    Artisan::command('capture:explode', function (): void {
        DB::select('select 1');

        throw new RuntimeException('command failed token=abcdef0123456789abcdef');
    });

    try {
        Artisan::call('capture:explode');
    } catch (RuntimeException $e) {
        report($e); // what the console kernel does after CommandFinished
    }
    app()->terminate();

    $trace = $this->recorder->last();

    expect($this->recorder->traces)->toHaveCount(1)
        ->and($trace->name)->toBe('capture:explode')
        ->and($trace->failed)->toBeTrue()
        ->and($trace->exceptions())->toHaveCount(1)
        ->and($this->recorder->json())->not->toContain('abcdef0123456789abcdef');
});

it('attributes nested Artisan::call() to the outer command', function (): void {
    captureRerouteCommandEvents();
    Artisan::command('capture:inner', fn () => DB::select('select 1'));
    Artisan::command('capture:outer', function () {
        DB::select('select 0');
        Artisan::call('capture:inner');

        return 0;
    });

    Artisan::call('capture:outer');
    app()->terminate();

    expect($this->recorder->traces)->toHaveCount(1)
        ->and($this->recorder->last()->name)->toBe('capture:outer')
        ->and($this->recorder->last()->queryCount())->toBe(2);
});

it('records nothing for ignored commands', function (): void {
    captureRerouteCommandEvents();

    Artisan::call('sherlock:status');
    app()->terminate();

    expect($this->recorder->traces)->toBe([]);
});

it('captures errors logged outside any unit as a standalone trace', function (): void {
    Log::error('scheduler closure failed password=Hunter2-Secret!', ['token' => 'abc']);
    Log::info('not captured');

    expect($this->recorder->traces)->toBe([]);

    app()->terminate();

    $trace = $this->recorder->last();

    expect($this->recorder->traces)->toHaveCount(1)
        ->and($trace->type)->toBe(EntryType::Command)
        ->and($trace->logs)->toHaveCount(1)
        ->and($trace->logs[0]->context)->toBe(['token' => '[REDACTED]'])
        ->and($this->recorder->json())->not->toContain('Hunter2-Secret!');
});

it('flushes orphan logs before the next unit starts', function (): void {
    Log::error('between jobs');
    dispatch(new CaptureJob(1));

    expect($this->recorder->traces)->toHaveCount(2)
        ->and($this->recorder->traces[0]->logs)->toHaveCount(1)
        ->and($this->recorder->traces[1]->type)->toBe(EntryType::Job)
        ->and($this->recorder->traces[1]->logs)->toBe([]);
});
