<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\PersistenceStrategy;
use Anabil\Sherlock\Contracts\Redactor;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Data\LogRecord;
use Anabil\Sherlock\Data\QueryRecord;
use Anabil\Sherlock\Data\SourceLocation;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Storage\Tables;
use Anabil\Sherlock\Tests\Feature\Storage\StorageFixtures as F;
use Illuminate\Support\Facades\DB;

it('never stores a plain password anywhere in the Sherlock tables', function (): void {
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

    config()->set('sherlock.storage.persistence', 'after_response');
    app()->forgetInstance(PersistenceStrategy::class);
    app(PersistenceStrategy::class)->persist(F::recorded($trace, [$finding], storeBindings: true));

    expect(json_encode($bindings))->toContain('[REDACTED]');

    $dump = '';
    foreach (Tables::fromConfig()->all() as $table) {
        foreach (DB::table($table)->get() as $row) {
            $dump .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
    }

    expect(DB::table('sherlock_traces')->count())->toBe(1)
        ->and(DB::table('sherlock_queries')->count())->toBe(1)
        ->and(DB::table('sherlock_issues')->count())->toBe(1)
        ->and($dump)->toContain('[REDACTED]')
        ->and($dump)->not->toContain($password)
        ->and($dump)->not->toContain($hash)
        ->and($dump)->not->toContain(substr($hash, 7));
});
