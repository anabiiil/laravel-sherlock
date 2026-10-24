<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\Redactor;
use Illuminate\Support\Facades\Hash;

/*
| Privacy guarantees of the Redactor as bound in the container (PLAN §9).
| The full end-to-end suite (tables + outgoing payloads) is added with storage/notifications.
*/

beforeEach(function (): void {
    $this->password = 'Sup3r-Secret-P@ss!';
    $this->redactor = app(Redactor::class);
});

it('never lets a password hash through bindings, whatever the column', function (): void {
    $hash = Hash::make($this->password);

    $bindings = $this->redactor->redactBindings('update `users` set `whatever` = ? where `id` = ?', [$hash, 1]);

    expect(json_encode($bindings))->not->toContain($hash)
        ->and(json_encode($bindings))->not->toContain(substr($hash, 7));
});

it('never lets a plain password through bindings of a password column', function (): void {
    $cases = [
        'insert into `users` (`name`, `email`, `password`) values (?, ?, ?)' => ['Ann', 'a@b.com', $this->password],
        'update `users` set `password` = ? where `id` = ?' => [$this->password, 1],
        'select * from `users` where `email` = ? and `password` = ? limit 1' => ['a@b.com', $this->password],
        'update `users` set `password` = :password where `id` = :id' => ['password' => $this->password, 'id' => 1],
    ];

    foreach ($cases as $sql => $bindings) {
        expect(json_encode($this->redactor->redactBindings($sql, $bindings)))->not->toContain($this->password);
    }
});

it('never lets a password through log context or exception messages', function (): void {
    $hash = Hash::make($this->password);

    $context = $this->redactor->redactArray([
        'request' => ['password' => $this->password, 'password_confirmation' => $this->password, 'current_password' => $this->password],
        'user' => ['email' => 'ann@example.com', 'password' => $hash, 'remember_token' => 'abc'],
        'message' => "Login failed password={$this->password} hash {$hash}",
    ]);
    $message = $this->redactor->redactString("SQLSTATE: insert ... values ('ann@example.com', '{$hash}')");

    $dump = json_encode($context).$message;

    expect($dump)->not->toContain($this->password)
        ->and($dump)->not->toContain($hash)
        ->and($dump)->not->toContain('ann@example.com');
});

it('never keeps credential headers', function (): void {
    $headers = $this->redactor->redactHeaders([
        'authorization' => ['Bearer '.$this->password],
        'cookie' => ['session='.$this->password],
        'x-api-key' => [$this->password],
        'x-tracing' => [$this->password],
    ]);

    expect($headers)->toBe([]);
});

it('cannot have its locked keys removed via config', function (): void {
    config()->set('tracing.privacy.sensitive_keys', []);
    app()->forgetInstance(Redactor::class);

    expect(app(Redactor::class)->redactArray(['password' => 'x', 'token' => 'y', 'otp' => '1', 'ssn' => '2']))
        ->toBe(['password' => '[REDACTED]', 'token' => '[REDACTED]', 'otp' => '[REDACTED]', 'ssn' => '[REDACTED]']);
});

it('is idempotent (redacting twice changes nothing more)', function (): void {
    $once = $this->redactor->redactString('mail a@b.com token=abc ip 10.1.1.1');

    expect($this->redactor->redactString($once))->toBe($once);
});
