<?php

declare(strict_types=1);

use Anabil\Tracing\Context\FailureReporter;
use Anabil\Tracing\Enums\EntryType;
use Anabil\Tracing\Enums\RecordReason;
use Anabil\Tracing\Facades\Tracing;
use Anabil\Tracing\Tests\Feature\Capture\Support\Capture;
use Anabil\Tracing\Tests\Feature\Capture\Support\FakeTraceRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

const CAPTURE_PASSWORD = 'S3cret-Pass!word';

beforeEach(function (): void {
    config()->set('tracing.mode', 'production');
    config()->set('tracing.sample_rate', 0.0);

    Capture::createTables();
    DB::table('capture_items')->insert([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);

    $this->recorder = Capture::fakeRecorder();

    Route::get('/items/{id}', function (string $id) {
        for ($i = 0; $i < 5; $i++) {
            DB::table('capture_items')->where('id', $id)->first();
        }
        DB::table('capture_items')->count();

        return response()->json(['ok' => true]);
    })->name('items.show');
});

afterEach(function (): void {
    Capture::dropTables();
});

function captureRecorder(): FakeTraceRecorder
{
    return test()->recorder;
}

it('captures a request end to end after the response, from events only', function (): void {
    $this->get('/items/1?token=abcdef')->assertOk();

    expect(captureRecorder()->traces)->toHaveCount(1);
    $trace = captureRecorder()->last();

    expect($trace->type)->toBe(EntryType::Request)
        ->and($trace->name)->toBe('GET /items/{id}')
        ->and($trace->route)->toBe('items.show')
        ->and($trace->method)->toBe('GET')
        ->and($trace->uri)->toBe('/items/1')
        ->and($trace->statusCode)->toBe(200)
        ->and($trace->failed)->toBeFalse()
        ->and($trace->full)->toBeFalse()
        ->and($trace->recordReasons)->toBe([])
        ->and($trace->queryCount())->toBe(6)
        ->and($trace->durationMs)->toBeGreaterThan(0.0)
        ->and($trace->memoryPeakBytes)->toBeGreaterThan(0)
        ->and($trace->id)->toHaveLength(26)
        ->and($trace->queries[0]->connection)->toBe('mysql')
        ->and($trace->queries[0]->driver)->toBe('mysql')
        ->and($trace->queries[0]->normalizedSql)->toBeNull()
        ->and(captureRecorder()->json())->not->toContain('abcdef');
});

it('takes one backtrace per distinct SQL string, not per query', function (): void {
    $spy = Capture::spyLocator();

    $this->get('/items/1')->assertOk();

    $queries = captureRecorder()->last()->queries;
    $located = array_values(array_filter($queries, static fn ($q): bool => $q->location !== null));

    expect($spy->calls)->toBe(2)
        ->and($queries)->toHaveCount(6)
        ->and($located)->toHaveCount(2)
        ->and($queries[0]->location)->not->toBeNull()
        ->and($queries[1]->location)->toBeNull()
        ->and($queries[5]->location)->not->toBeNull();
});

it('takes a backtrace for every query slower than the slow-query threshold', function (): void {
    config()->set('tracing.thresholds.slow_query.warning_ms', 0);
    $spy = Capture::spyLocator();

    $this->get('/items/1')->assertOk();

    expect($spy->calls)->toBe(6);
});

it('keeps no bindings in production light capture', function (): void {
    Route::post('/login', function () {
        DB::table('capture_users')->insert(['email' => 'ann@example.com', 'password' => CAPTURE_PASSWORD]);
        DB::table('capture_users')->where('password', CAPTURE_PASSWORD)->first();

        return 'ok';
    });

    $this->post('/login')->assertOk();

    $trace = captureRecorder()->last();
    expect($trace->full)->toBeFalse();
    foreach ($trace->queries as $query) {
        expect($query->bindings)->toBeNull();
    }
    expect(captureRecorder()->json())->not->toContain(CAPTURE_PASSWORD)->not->toContain('ann@example.com');
});

it('keeps redacted bindings in full capture and never the password or its hash', function (): void {
    config()->set('tracing.mode', 'local');
    $hash = password_hash(CAPTURE_PASSWORD, PASSWORD_BCRYPT);

    Route::post('/register', function () use ($hash) {
        DB::table('capture_users')->insert(['email' => 'ann@example.com', 'password' => $hash]);
        DB::update('update capture_users set password = ? where email = ?', [CAPTURE_PASSWORD, 'ann@example.com']);
        DB::table('capture_users')->where('email', 'ann@example.com')->where('password', CAPTURE_PASSWORD)->first();
        DB::table('capture_items')->where('name', 'b')->first();

        return 'ok';
    });

    $this->post('/register')->assertOk();

    $trace = captureRecorder()->last();
    $json = captureRecorder()->json();

    expect($trace->full)->toBeTrue()
        ->and($trace->recordReasons)->toBe([RecordReason::Local])
        ->and($trace->queries[0]->bindings)->toBeArray()
        ->and($json)->not->toContain(CAPTURE_PASSWORD)
        ->and($json)->not->toContain($hash)
        ->and($json)->not->toContain('ann@example.com')
        ->and($trace->queries[3]->bindings)->toBe(['b']);
});

it('captures error logs and reported exceptions redacted, with relative frames', function (): void {
    Route::get('/boom', function (): void {
        Log::error('Login failed password='.CAPTURE_PASSWORD, [
            'password' => CAPTURE_PASSWORD,
            'user' => ['email' => 'ann@example.com', 'api_token' => 'tok_abcdefghijklmnop'],
            'attempt' => 3,
        ]);
        Log::warning('below the captured level');

        throw new RuntimeException('Boom for ann@example.com password='.CAPTURE_PASSWORD);
    });

    $this->get('/boom')->assertStatus(500);

    $trace = captureRecorder()->last();
    $json = captureRecorder()->json();

    expect($trace->failed)->toBeTrue()
        ->and($trace->statusCode)->toBe(500)
        ->and($trace->logs)->toHaveCount(2)
        ->and($trace->hasException())->toBeTrue()
        ->and($trace->logs[0]->context['password'])->toBe('[REDACTED]')
        ->and($trace->logs[0]->context['attempt'])->toBe(3)
        ->and($trace->logs[1]->exceptionClass)->toBe(RuntimeException::class)
        ->and($trace->logs[1]->frames)->not->toBeEmpty()
        ->and($trace->logs[1]->line)->toBeInt()
        ->and($json)->not->toContain(CAPTURE_PASSWORD)
        ->and($json)->not->toContain('ann@example.com')
        ->and($json)->not->toContain('tok_abcdefghijklmnop')
        ->and($json)->not->toContain('"args"');

    foreach ($trace->logs[1]->frames as $frame) {
        expect($frame['file'])->not->toStartWith(base_path());
    }
});

it('captures outgoing HTTP calls with a redacted URL and no headers or body', function (): void {
    Http::fake(['api.example.com/*' => Http::response(['ok' => true], 201)]);

    Route::get('/remote', function () {
        Http::withToken('bearer-secret-value')->get('https://api.example.com/v1/orders?api_key=SECRET123456&page=2');

        return 'ok';
    });

    $this->get('/remote')->assertOk();

    $trace = captureRecorder()->last();
    $json = captureRecorder()->json();

    expect($trace->httpCalls)->toHaveCount(1)
        ->and($trace->httpCalls[0]->method)->toBe('GET')
        ->and($trace->httpCalls[0]->statusCode)->toBe(201)
        ->and($trace->httpCalls[0]->host())->toBe('api.example.com')
        ->and($trace->httpCalls[0]->url)->toContain('page=2')
        ->and($trace->httpCalls[0]->url)->toContain('api_key=[REDACTED]')
        ->and($trace->httpCalls[0]->durationMs)->toBeGreaterThanOrEqual(0.0)
        ->and($json)->not->toContain('SECRET123456')
        ->and($json)->not->toContain('bearer-secret-value');
});

it('counts cache hits, misses and writes without storing keys', function (): void {
    Route::get('/cached', function () {
        Cache::get('capture-unique-key-missing');
        Cache::put('capture-unique-key', 'capture-unique-value', 60);
        Cache::get('capture-unique-key');
        Cache::get('capture-unique-key');

        return 'ok';
    });

    $this->get('/cached')->assertOk();

    $trace = captureRecorder()->last();

    expect($trace->cacheHits)->toBe(2)
        ->and($trace->cacheMisses)->toBe(1)
        ->and($trace->cacheWrites)->toBe(1)
        ->and($trace->cacheMs)->toBeGreaterThanOrEqual(0.0)
        ->and(captureRecorder()->json())->not->toContain('capture-unique');
});

it('never records the package\'s own paused work', function (): void {
    Route::get('/paused', function () {
        DB::select('select 1 as visible');
        $value = Tracing::pause(fn () => DB::select('select 2 as paused_query'));
        Tracing::pause(fn () => Log::error('paused log'));

        return response()->json(['paused' => $value[0]->paused_query]);
    });

    $this->get('/paused')->assertOk()->assertJson(['paused' => 2]);

    $trace = captureRecorder()->last();

    expect($trace->queries)->toHaveCount(1)
        ->and($trace->queries[0]->sql)->toBe('select 1 as visible')
        ->and($trace->logs)->toBe([]);
});

it('exposes isRecording and tag through the facade', function (): void {
    Route::get('/tagged', function () {
        Tracing::tag('order', 15);
        Tracing::tag('customer_email', 'ann@example.com');
        Tracing::tag('api_token', 'should-not-appear');

        return response()->json(['recording' => Tracing::isRecording(), 'paused' => Tracing::pause(fn () => Tracing::isRecording())]);
    });

    $this->get('/tagged')->assertOk()->assertJson(['recording' => true, 'paused' => false]);

    expect(captureRecorder()->last()->tags)->toBe([
        'order' => 15,
        'customer_email' => '[REDACTED]',
        'api_token' => '[REDACTED]',
    ])->and(Tracing::isRecording())->toBeFalse();
});

it('fully traces a request carrying the trigger header secret', function (): void {
    config()->set('tracing.header.secret', 'let-me-in');

    $this->get('/items/1', ['X-Tracing' => 'let-me-in'])->assertOk();
    $this->get('/items/1', ['X-Tracing' => 'wrong'])->assertOk();
    $this->get('/items/1')->assertOk();

    [$triggered, $wrong, $plain] = captureRecorder()->traces;

    expect($triggered->full)->toBeTrue()
        ->and($triggered->recordReasons)->toBe([RecordReason::Header])
        ->and($triggered->queries[0]->bindings)->toBe(['1'])
        ->and($wrong->full)->toBeFalse()
        ->and($plain->full)->toBeFalse()
        ->and(captureRecorder()->json())->not->toContain('let-me-in');
});

it('ignores the trigger header while the secret is empty', function (): void {
    config()->set('tracing.header.secret', '');

    $this->get('/items/1', ['X-Tracing' => ''])->assertOk();

    expect(captureRecorder()->last()->full)->toBeFalse();
});

it('marks sampled traces', function (): void {
    config()->set('tracing.sample_rate', 1.0);

    $this->get('/items/1')->assertOk();

    expect(captureRecorder()->last()->recordReasons)->toBe([RecordReason::Sample])
        ->and(captureRecorder()->last()->full)->toBeFalse();
});

it('records nothing for ignored paths, methods and route names', function (): void {
    config()->set('tracing.ignore.routes', ['internal.*']);
    Route::get('/up', fn () => DB::select('select 1'));
    Route::get('/tracing/issues', fn () => DB::select('select 1'));
    Route::get('/internal/ping', fn () => DB::select('select 1'))->name('internal.ping');
    Route::match(['OPTIONS'], '/items/{id}', fn () => 'ok');

    $this->get('/up')->assertOk();
    $this->get('/tracing/issues')->assertOk();
    $this->get('/internal/ping')->assertOk();
    $this->call('OPTIONS', '/items/1')->assertOk();

    expect(captureRecorder()->traces)->toBe([]);

    $this->get('/items/1')->assertOk();
    expect(captureRecorder()->traces)->toHaveCount(1);
});

it('attributes a sync job dispatched in a request to the request trace', function (): void {
    Route::get('/dispatch', function () {
        DB::select('select 1');
        dispatch(new Anabil\Tracing\Tests\Feature\Capture\Support\CaptureJob(3));

        return 'ok';
    });

    $this->get('/dispatch')->assertOk();

    expect(captureRecorder()->traces)->toHaveCount(1)
        ->and(captureRecorder()->last()->type)->toBe(EntryType::Request)
        ->and(captureRecorder()->last()->queryCount())->toBe(4);
});

it('never breaks the request when the recorder fails', function (): void {
    captureRecorder()->throw = true;

    $this->get('/items/1')->assertOk()->assertJson(['ok' => true]);
    $this->get('/items/2')->assertOk();

    expect(app(FailureReporter::class)->count())->toBe(2);
});

it('never breaks the request when a collector fails', function (): void {
    Capture::spyLocator()->throw = true;

    $this->get('/items/1')->assertOk()->assertJson(['ok' => true]);

    expect(app(FailureReporter::class)->count())->toBeGreaterThan(0)
        ->and(captureRecorder()->traces)->toHaveCount(1);
});
