<?php

declare(strict_types=1);

use Anabil\Tracing\Tests\Feature\Integration\Support\BootsTracing;
use Anabil\Tracing\Tests\Feature\Integration\Support\Integration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| PLAN §9 end-to-end privacy suite. A small "application" registers a user with a known
| password, changes it, fails a login, throws exceptions and logs carrying the password,
| calls an external API with a token in the URL and a bearer header, all through the real
| HTTP kernel. Then EVERY column of EVERY row of EVERY tracing_* table (and, with queued
| persistence, the serialized job payloads) is scanned: neither the plain passwords, nor
| their bcrypt hashes, nor the tokens may appear anywhere.
*/

uses(BootsTracing::class);

beforeEach(function (): void {
    Integration::setUp('local');

    Schema::dropIfExists('privacy_users');
    Schema::create('privacy_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('email')->unique();
        $table->string('password');
        $table->string('api_token')->nullable();
    });

    // Unique per run: a hit can only come from this test.
    $this->secrets = [
        'plain' => 'Pw'.Str::random(18).'x9',
        'new_plain' => 'Nw'.Str::random(18).'y8',
        'wrong' => 'Wr'.Str::random(18).'z7',
        'token' => 'tk'.Str::random(22),
        'bearer' => 'br'.Str::random(30),
        'email' => 'privacy.'.Str::lower(Str::random(8)).'@example.com',
    ];

    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);
    privacyRoutes();
});

afterEach(function (): void {
    Schema::dropIfExists('privacy_users');
    Schema::dropIfExists('jobs');
    Integration::tearDown();
});

function privacyRoutes(): void
{
    Route::post('/privacy/register', function (Request $request) {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|confirmed', 'api_token' => 'required']);

        DB::table('privacy_users')->insert([
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'api_token' => $data['api_token'],
        ]);

        return ['ok' => true];
    });

    Route::post('/privacy/password', function (Request $request) {
        DB::update('UPDATE privacy_users SET password = ? WHERE email = ?', [Hash::make((string) $request->input('new_password')), $request->input('email')]);
        // A legacy lookup comparing the plain value: the binding sits on a password column.
        DB::select('select id from privacy_users where email = ? and password = ? limit 1', [$request->input('email'), $request->input('new_password')]);

        return ['ok' => true];
    });

    Route::post('/privacy/login', function (Request $request) {
        $user = DB::table('privacy_users')->where('email', $request->input('email'))->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), (string) $user->password)) {
            Log::warning('Login failed for '.$request->input('email'));

            return response()->json(['ok' => false], 422);
        }

        return ['ok' => true];
    });

    Route::post('/privacy/explode', function (Request $request): void {
        $plain = (string) $request->input('password');

        // Free text: the redactor cannot know this word is a password by its shape.
        throw new RuntimeException('Could not authenticate '.$request->input('email').' with password '.$plain);
    });

    Route::post('/privacy/explode-keyed', function (Request $request): void {
        throw new RuntimeException('Bad credentials: password='.$request->input('password').' api_token: '.$request->input('api_token'));
    });

    Route::post('/privacy/duplicate', function (Request $request) {
        // A failing INSERT: the QueryException message interpolates the bindings into the SQL.
        DB::insert('insert into privacy_users (email, password) values (?, ?)', [$request->input('email'), $request->input('password')]);

        return ['ok' => true];
    });

    Route::post('/privacy/log', function (Request $request) {
        Log::error('Sign-in provider rejected the credentials', [
            'password' => $request->input('password'),
            'nested' => ['api_token' => $request->input('api_token'), 'user' => ['passwd' => $request->input('password')]],
            'note' => 'password='.$request->input('password'),
        ]);

        return ['ok' => true];
    });

    Route::post('/privacy/outgoing', function (Request $request) {
        Http::withToken((string) $request->input('bearer'))
            ->withHeaders(['X-Api-Key' => (string) $request->input('api_token')])
            ->post('https://api.example.test/v1/hook?token='.$request->input('api_token').'&page=2', [
                'password' => $request->input('password'),
            ]);

        return ['ok' => true];
    });
}

/**
 * Drive the whole scenario. $headers carries the production trigger header when needed.
 *
 * @param  array<string, string>  $headers
 * @return list<string> bcrypt hashes stored by the application
 */
function privacyScenario(array $headers = []): array
{
    $s = test()->secrets;
    $base = ['email' => $s['email'], 'api_token' => $s['token']];

    test()->post('/privacy/register', $base + ['password' => $s['plain'], 'password_confirmation' => $s['plain']], $headers)->assertOk();
    $hashes = [(string) DB::table('privacy_users')->value('password')];

    test()->post('/privacy/password', $base + ['new_password' => $s['new_plain']], $headers)->assertOk();
    $hashes[] = (string) DB::table('privacy_users')->value('password');

    test()->post('/privacy/login', $base + ['password' => $s['wrong']], $headers)->assertStatus(422);
    test()->post('/privacy/explode', $base + ['password' => $s['plain']], $headers)->assertStatus(500);
    test()->post('/privacy/explode-keyed', $base + ['password' => $s['plain']], $headers)->assertStatus(500);
    test()->post('/privacy/duplicate', $base + ['password' => $s['plain']], $headers)->assertStatus(500);
    test()->post('/privacy/log', $base + ['password' => $s['plain']], $headers)->assertOk();
    test()->post('/privacy/outgoing', $base + ['password' => $s['plain'], 'bearer' => $s['bearer']], $headers)->assertOk();

    Http::assertSent(static fn (ClientRequest $request): bool => $request->hasHeader('Authorization', 'Bearer '.$s['bearer']));

    return $hashes;
}

/**
 * Every value of every row of every tracing_* table.
 *
 * @return list<array{string, string, string}> [table, column, value]
 */
function privacyStoredValues(): array
{
    $values = [];
    foreach (Integration::storageTables() as $table) {
        foreach (DB::table($table)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                if ($value !== null) {
                    $values[] = [$table, (string) $column, (string) $value];
                }
            }
        }
    }

    return $values;
}

/**
 * Forms a secret may take once stored: raw, JSON-escaped, URL-encoded, or (for hashes) the
 * part after the "$2y$10$" prefix.
 *
 * @return list<string>
 */
function privacyNeedles(string $secret): array
{
    $needles = [$secret, trim((string) json_encode($secret), '"'), rawurlencode($secret), urlencode($secret)];
    if (str_starts_with($secret, '$2')) {
        $needles[] = substr($secret, 7);
        $needles[] = substr($secret, 29); // the 31-char hash part after the salt
    }

    return array_values(array_unique($needles));
}

/**
 * @param  list<array{string, string, string}>  $haystack  [where, column, value]
 * @param  list<string>  $hashes
 */
function privacyAssertClean(array $haystack, array $hashes): void
{
    $secrets = test()->secrets;
    unset($secrets['email']);
    $secrets += array_combine(array_map(static fn (int $i): string => 'hash'.$i, array_keys($hashes)), $hashes);

    $leaks = [];
    foreach ($haystack as [$where, $column, $value]) {
        foreach ($secrets as $name => $secret) {
            foreach (privacyNeedles($secret) as $needle) {
                if (str_contains($value, $needle) || str_contains(stripslashes($value), $needle)) {
                    $leaks[] = "{$name} found in {$where}.{$column}";
                }
            }
        }
    }

    expect(array_values(array_unique($leaks)))->toBe([]);
}

/**
 * @param  list<array{string, string, string}>  $values
 */
function privacyAssertMarkers(array $values): void
{
    $in = static function (string $table, string $column) use ($values): string {
        // MySQL returns JSON columns as `"key": "value"`; compare without that space.
        return str_replace('": ', '":', implode("\n", array_map(
            static fn (array $v): string => $v[2],
            array_filter($values, static fn (array $v): bool => $v[0] === $table && $v[1] === $column),
        )));
    };

    $bindings = $in('tracing_queries', 'bindings');
    $details = $in('tracing_traces', 'details');
    $issues = $in('tracing_issues', 'context');

    // Bound password / hash values were replaced, not dropped.
    expect($bindings)->toContain('[REDACTED]')
        // Log context keys kept, values redacted.
        ->and($details)->toContain('"password":"[REDACTED]"')
        ->and($details)->toContain('"api_token":"[REDACTED]"')
        ->and($details)->toContain('"passwd":"[REDACTED]"')
        // The token in the outgoing URL is redacted, the harmless parameter kept.
        ->and($details)->toContain('token=[REDACTED]&page=2')
        // Exception messages are redacted in the issue context.
        ->and($issues)->toContain('[REDACTED]');

    // Headers and bodies are never stored at all.
    expect($details)->not->toContain('Authorization')
        ->and($details)->not->toContain('X-Api-Key')
        ->and($details)->not->toContain('password_confirmation');
}

it('stores no password, hash or token anywhere in local mode (full capture, bindings stored)', function (): void {
    $hashes = privacyScenario();

    $values = privacyStoredValues();
    expect(Integration::count('tracing_traces'))->toBe(8)
        ->and(Integration::count('tracing_issues'))->toBeGreaterThanOrEqual(4)
        // Bindings are stored in local mode: the scan is meaningful.
        ->and(DB::table('tracing_queries')->whereNotNull('bindings')->count())->toBeGreaterThan(0);

    privacyAssertClean($values, $hashes);
    privacyAssertMarkers($values);
});

it('stores no password, hash or token anywhere in production with the trigger header', function (): void {
    config()->set('tracing.mode', 'production');

    $hashes = privacyScenario(['X-Tracing' => 'integration-header-secret']);

    $values = privacyStoredValues();
    expect(array_unique(array_column(Integration::rows('tracing_traces'), 'record_reason')))->toBe(['header'])
        ->and(Integration::count('tracing_traces'))->toBe(8)
        ->and(DB::table('tracing_queries')->whereNotNull('bindings')->count())->toBeGreaterThan(0);

    privacyAssertClean($values, $hashes);
    privacyAssertMarkers($values);
});

it('stores no password, hash or token in production light traces (no bindings at all)', function (): void {
    config()->set('tracing.mode', 'production');

    $hashes = privacyScenario();

    // Only the failing / logging requests cross a threshold; none stores bindings.
    expect(Integration::count('tracing_traces'))->toBeGreaterThan(0)
        ->and(DB::table('tracing_queries')->whereNotNull('bindings')->count())->toBe(0);

    privacyAssertClean(privacyStoredValues(), $hashes);
});

it('keeps queued job payloads and the stored rows clean with queued persistence', function (): void {
    Schema::create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('tracing.storage.persistence', 'queue');
    config()->set('tracing.queue.connection', 'database');
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);

    $hashes = privacyScenario();

    // The serialized PersistTraceJob payloads (what sits in Redis / SQS / the jobs table).
    $payloads = array_map(
        static fn (object $job): array => ['jobs', 'payload', (string) $job->payload],
        DB::table('jobs')->get()->all(),
    );
    expect($payloads)->toHaveCount(8);
    privacyAssertClean(array_values($payloads), $hashes);

    $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();
    app()->terminate();

    expect(Integration::count('tracing_traces'))->toBe(8);
    $values = privacyStoredValues();
    privacyAssertClean($values, $hashes);
    privacyAssertMarkers($values);
});
