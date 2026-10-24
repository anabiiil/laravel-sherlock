<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\PersistenceStrategy;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Data\Finding;
use Anabil\Tracing\Data\LogRecord;
use Anabil\Tracing\Data\QueryRecord;
use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Storage\Tables;
use Anabil\Tracing\Tests\Feature\Storage\StorageFixtures as F;
use Illuminate\Support\Facades\DB;

it('never stores a plain password anywhere in the tracing tables', function (): void {
    $password = 'Sup3r-Secret-Pa55word!';
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $redactor = app(Redactor::class);

    // Data as the capture layer hands it over: every value already went through the Redactor.
    $bindings = $redactor->redactArray(['email' => 'ann@example.com', 'password' => $password, 'hash' => $hash]);
    $query = (new QueryRecord(
        'update users set password = ? where email = ?',
        array_values($bindings),
        12.0,
        'mysql',
        'mysql',
        new SourceLocation('app/Http/Controllers/PasswordController.php', 30),
    ))->withNormalization('update users set password = ? where email = ?', 'fp-pw');

    $log = new LogRecord(
        'error',
        $redactor->redactString('Login failed for ann@example.com with password='.$password),
        'RuntimeException',
        'app/Http/Controllers/PasswordController.php',
        31,
        [],
        $redactor->redactArray(['password' => $password, 'password_confirmation' => $password, 'token' => 'abc']),
    );

    $trace = F::traceData([$query], logs: [$log]);
    $finding = new Finding(IssueType::Exception, Severity::Critical, 'RuntimeException', $log->message, 'exc-pw', location: $log->location());

    config()->set('tracing.storage.persistence', 'after_response');
    app()->forgetInstance(PersistenceStrategy::class);
    app(PersistenceStrategy::class)->persist(F::recorded($trace, [$finding], storeBindings: true));

    expect(json_encode($bindings))->toContain('[REDACTED]');

    $dump = '';
    foreach (Tables::fromConfig()->all() as $table) {
        foreach (DB::table($table)->get() as $row) {
            $dump .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
    }

    expect(DB::table('tracing_traces')->count())->toBe(1)
        ->and(DB::table('tracing_queries')->count())->toBe(1)
        ->and(DB::table('tracing_issues')->count())->toBe(1)
        ->and($dump)->toContain('[REDACTED]')
        ->and($dump)->not->toContain($password)
        ->and($dump)->not->toContain($hash)
        ->and($dump)->not->toContain(substr($hash, 7));
});
