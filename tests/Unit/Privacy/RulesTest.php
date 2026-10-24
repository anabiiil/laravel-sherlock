<?php

declare(strict_types=1);

use Anabil\Sherlock\Privacy\Rules\BearerTokenRule;
use Anabil\Sherlock\Privacy\Rules\CardNumberRule;
use Anabil\Sherlock\Privacy\Rules\CustomPatternRule;
use Anabil\Sherlock\Privacy\Rules\EmailRule;
use Anabil\Sherlock\Privacy\Rules\IpAddressRule;
use Anabil\Sherlock\Privacy\Rules\LongSecretRule;
use Anabil\Sherlock\Privacy\Rules\PasswordHashRule;
use Anabil\Sherlock\Privacy\Rules\PhoneRule;
use Anabil\Sherlock\Privacy\Rules\SensitiveKeyRule;
use Anabil\Sherlock\Privacy\SensitiveKeys;

const R = '[REDACTED]';

dataset('hashes', [
    'bcrypt 2y' => '$2y$12$2ikW8Vh3B6aYRMJ9EbqNwOd6.8yGqf7mFl1s0X4T2u8o9r3cKz7m6',
    'bcrypt 2a' => '$2a$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy',
    'bcrypt 2b' => '$2b$04$abcdefghijklmnopqrstuuJ1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P',
    'argon2i' => '$argon2i$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG',
    'argon2id' => '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0$7mDYI5JVZ3hUsAiIbJx3PwOKTMw8hl2+Xbg6xN4WbPQ',
    'sha512 crypt' => '$6$rounds=5000$usesomesillystri$D4IrlXatmP7rx3P3InaxBeoomnAihCKRVQP22JZ6EY47Wc6BkroIuUUBOov1i.S5KPgErtP/EN5mcO.ChWQW21',
    'sha256 crypt' => '$5$saltstring$5B8vYYiY.CVt1RlTTf8KbXBH3hsxY/GNooZF2Hc6dB.',
    'md5 crypt' => '$1$saltsalt$qjXMvbEw8oaL.CzflDugX/',
    'scrypt' => '$scrypt$ln=16,r=8,p=1$aM15713r3Xsvxbi31lqr1Q$nFNh2CVHVjNldFVKDHDlm4CbdRSCdEBsjjJxD+iCs5E',
    'pbkdf2 django' => 'pbkdf2_sha256$260000$abcdefghijklmn$Zm9vYmFyYmF6cXV4cXV1eGZvb2Jhcg==',
]);

it('detects password hashes by shape', function (string $hash): void {
    $rule = new PasswordHashRule;

    expect($rule->isHash($hash))->toBeTrue()
        ->and($rule->redact("stored {$hash} here"))->toBe('stored '.R.' here');
})->with('hashes');

it('detects a real bcrypt and argon hash from password_hash()', function (): void {
    $rule = new PasswordHashRule;

    expect($rule->isHash(password_hash('secret-password', PASSWORD_BCRYPT)))->toBeTrue();

    if (defined('PASSWORD_ARGON2ID')) {
        expect($rule->isHash(password_hash('secret-password', PASSWORD_ARGON2ID)))->toBeTrue();
    }
});

it('does not treat ordinary values as hashes', function (string $value): void {
    expect((new PasswordHashRule)->isHash($value))->toBeFalse();
})->with(['$100', 'price is $2', 'hello world', '$2y$', 'abc$def']);

it('masks inline sensitive key values', function (string $input, string $expected): void {
    expect((new SensitiveKeyRule(new SensitiveKeys))->redact($input))->toBe($expected);
})->with([
    ['password=hunter2', 'password='.R],
    ['login failed: password: hunter2 for user 5', 'login failed: password: '.R.' for user 5'],
    ['{"api_key":"abc123","name":"x"}', '{"api_key":"'.R.'","name":"x"}'],
    ["token='abc'", "token='".R."'"],
    ['/callback?access_token=abc&state=ok', '/callback?access_token='.R.'&state=ok'],
    ['name=John&age=4', 'name=John&age=4'],
    ['https://example.com/path', 'https://example.com/path'],
    ['no separators at all', 'no separators at all'],
]);

it('masks bearer and basic credentials and JWTs', function (): void {
    $rule = new BearerTokenRule;
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

    expect($rule->redact('Authorization: Bearer abcdef123456.xyz'))->toBe('Authorization: Bearer '.R)
        ->and($rule->redact('Basic dXNlcjpwYXNzd29yZA=='))->toBe('Basic '.R)
        ->and($rule->redact("token {$jwt} end"))->toBe('token '.R.' end')
        ->and($rule->redact('Basic information about bearer'))->toBe('Basic information about bearer');
});

it('masks emails', function (): void {
    expect((new EmailRule)->redact('user john.doe+tag@example.co.uk failed'))->toBe('user '.R.' failed')
        ->and((new EmailRule)->redact('no email here'))->toBe('no email here');
});

it('masks phone numbers', function (string $input): void {
    expect((new PhoneRule)->redact("call {$input} now"))->toBe('call '.R.' now');
})->with(['+1 (555) 123-4567', '+201001234567', '+44 20 7946 0958', '0044 20 7946 0958', '555-123-4567', '(555) 123-4567', '555.123.4567', '01001234567', '010 0123 4567']);

it('leaves non-phone numbers alone', function (string $input): void {
    expect((new PhoneRule)->redact("value {$input} here"))->toBe("value {$input} here");
})->with(['12345678', '1700000000', '2024-01-15', '2024-01-15 10:30:00', 'order #4521', '3.14159265', 'v1.2.3']);

it('masks Luhn-valid card numbers only', function (): void {
    $rule = new CardNumberRule;

    expect($rule->redact('card 4111 1111 1111 1111 ok'))->toBe('card '.R.' ok')
        ->and($rule->redact('card 4111-1111-1111-1111'))->toBe('card '.R)
        ->and($rule->redact('5500005555555559'))->toBe(R)
        ->and($rule->redact('378282246310005'))->toBe(R)
        ->and($rule->redact('4111111111111112'))->toBe('4111111111111112')
        ->and($rule->redact('id 1234567890123'))->toBe('id 1234567890123')
        ->and(CardNumberRule::luhn('79927398713'))->toBeTrue()
        ->and(CardNumberRule::luhn('79927398710'))->toBeFalse();
});

it('masks IP addresses', function (): void {
    $rule = new IpAddressRule;

    expect($rule->redact('from 192.168.1.10 via 10.0.0.1'))->toBe('from '.R.' via '.R)
        ->and($rule->redact('ipv6 2001:0db8:85a3:0000:0000:8a2e:0370:7334 end'))->toBe('ipv6 '.R.' end')
        ->and($rule->redact('short fe80::1 end'))->toBe('short '.R.' end')
        ->and($rule->redact('at 10:30:00 today'))->toBe('at 10:30:00 today')
        ->and($rule->redact('App\Models\User::find'))->toBe('App\Models\User::find')
        ->and($rule->redact('999.1.1.1'))->toBe('999.1.1.1');
});

it('masks long secrets', function (): void {
    $rule = new LongSecretRule;

    expect($rule->redact('key sk-proj-abcdefghijklmnop1234 end'))->toBe('key '.R.' end')
        ->and($rule->redact('ghp_abcdefghijklmnopqrstuvwxyz123456'))->toBe(R)
        ->and($rule->redact('AKIAIOSFODNN7EXAMPLE'))->toBe(R)
        ->and($rule->redact('hex 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08'))->toBe('hex '.R)
        ->and($rule->redact('b64 aGVsbG8gd29ybGQgdGhpcyBpcyBhIHNlY3JldCBrZXkgMTIz'))->toBe('b64 '.R)
        ->and($rule->redact('app/Http/Controllers/Api/OrderController.php'))->toBe('app/Http/Controllers/Api/OrderController.php')
        ->and($rule->redact('2024_01_01_000000_create_users_table_for_everything'))->toBe('2024_01_01_000000_create_users_table_for_everything')
        ->and($rule->redact('uuid 9b2f0b3e-6a51-4c1e-9d7e-1c2b3a4d5e6f'))->toBe('uuid 9b2f0b3e-6a51-4c1e-9d7e-1c2b3a4d5e6f');
});

it('applies custom patterns and ignores invalid ones', function (): void {
    $rule = new CustomPatternRule(['/EMP-\d{5}/', '/[invalid', 42]);

    expect($rule->isEmpty())->toBeFalse()
        ->and($rule->redact('employee EMP-12345'))->toBe('employee '.R)
        ->and((new CustomPatternRule)->redact('EMP-12345'))->toBe('EMP-12345');
});
