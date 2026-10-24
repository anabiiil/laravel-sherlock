<?php

declare(strict_types=1);

use Anabil\Tracing\Contracts\Redactor as RedactorContract;
use Anabil\Tracing\Privacy\Redactor;

const RED = RedactorContract::REDACTED;

beforeEach(function (): void {
    $this->redactor = Redactor::make();
});

it('uses a single redaction constant', function (): void {
    expect(RedactorContract::REDACTED)->toBe('[REDACTED]');
});

it('redacts free text with every rule', function (): void {
    $text = 'User a@b.com from 10.0.0.1 password=hunter2 card 4111111111111111 Bearer abcdefgh1234';

    expect($this->redactor->redactString($text))
        ->toBe('User '.RED.' from '.RED.' password='.RED.' card '.RED.' Bearer '.RED);
});

it('leaves short and harmless strings untouched', function (): void {
    expect($this->redactor->redactString('ok'))->toBe('ok')
        ->and($this->redactor->redactString('select * from users'))->toBe('select * from users');
});

it('truncates very long strings', function (): void {
    $long = str_repeat('a ', 20000);

    expect(strlen($this->redactor->redactString($long)))->toBeLessThan(Redactor::MAX_STRING_LENGTH + 20);
});

it('redacts nested arrays recursively at any depth', function (): void {
    $input = [
        'name' => 'Ann',
        'password' => 'hunter2',
        'profile' => [
            'email' => 'ann@example.com',
            'settings' => ['api_key' => 'k', 'deep' => ['otp' => '123456', 'note' => 'token=abc']],
        ],
        'list' => ['x', 'a@b.com'],
        'count' => 3,
        'ok' => true,
        'none' => null,
    ];

    expect($this->redactor->redactArray($input))->toBe([
        'name' => 'Ann',
        'password' => RED,
        'profile' => [
            'email' => RED,
            'settings' => ['api_key' => RED, 'deep' => ['otp' => RED, 'note' => 'token='.RED]],
        ],
        'list' => ['x', RED],
        'count' => 3,
        'ok' => true,
        'none' => null,
    ]);
});

it('stops at the depth limit', function (): void {
    $deep = ['v' => 'x'];
    for ($i = 0; $i < 15; $i++) {
        $deep = ['n' => $deep];
    }

    expect(json_encode($this->redactor->redactArray($deep)))->toContain('[depth limit]');
});

it('converts objects to safe values', function (): void {
    $stringable = new class
    {
        public function __toString(): string
        {
            return 'mail me at x@y.com';
        }
    };

    expect($this->redactor->redactValue(new DateTimeImmutable('2024-01-01T00:00:00+00:00')))->toBe('2024-01-01T00:00:00+00:00')
        ->and($this->redactor->redactValue($stringable))->toBe('mail me at '.RED)
        ->and($this->redactor->redactValue(new stdClass))->toBe('[object stdClass]')
        ->and($this->redactor->redactValue(INF))->toBe('INF')
        ->and($this->redactor->redactValue("\xB1\x31\xFF"))->toBe('[binary]');
});

it('redacts UPDATE bindings by column', function (): void {
    $sql = 'update `users` set `name` = ?, `password` = ?, `remember_token` = ?, `updated_at` = ? where `id` = ?';
    $bindings = ['Ann', 'plain-secret', 'tok', '2024-01-01 00:00:00', 5];

    expect($this->redactor->redactBindings($sql, $bindings))
        ->toBe(['Ann', RED, RED, '2024-01-01 00:00:00', 5]);
});

it('redacts INSERT bindings by column, including multi-row inserts', function (): void {
    $sql = 'insert into `users` (`name`, `email`, `password`) values (?, ?, ?), (?, ?, ?)';
    $bindings = ['Ann', 'ann@x.com', 'p1', 'Bob', 'bob@x.com', 'p2'];

    expect($this->redactor->redactBindings($sql, $bindings))
        ->toBe(['Ann', RED, RED, 'Bob', RED, RED]);
});

it('redacts upsert tails correctly', function (): void {
    $sql = 'insert into `t` (`a`, `secret`) values (?, ?) on duplicate key update `a` = ?';

    expect($this->redactor->redactBindings($sql, ['x', 's', 'y']))->toBe(['x', RED, 'y']);
});

it('redacts SELECT bindings in WHERE, IN and BETWEEN', function (): void {
    $sql = 'select * from `users` where `email` = ? and `users`.`api_token` = ? and `id` in (?, ?) and `pin` in (?) and `created_at` between ? and ? and lower(password) = ?';
    $bindings = ['a@b.com', 'tok', 1, 2, '1234', '2024-01-01', '2024-02-01', 'x'];

    expect($this->redactor->redactBindings($sql, $bindings))
        ->toBe([RED, RED, 1, 2, RED, '2024-01-01', '2024-02-01', RED]);
});

it('ignores question marks inside string literals', function (): void {
    $sql = "select * from t where note = 'why?' and password = ?";

    expect($this->redactor->redactBindings($sql, ['p']))->toBe([RED]);
});

it('redacts named bindings by name and by column', function (): void {
    $sql = 'select * from users where password = :p and name = :name and x = :secret_value';

    expect($this->redactor->redactBindings($sql, [':p' => 'a', 'name' => 'Ann', 'secret_value' => 'b']))
        ->toBe([':p' => RED, 'name' => 'Ann', 'secret_value' => RED]);
});

it('always redacts hash-shaped bindings whatever the column', function (): void {
    $hash = password_hash('secret', PASSWORD_BCRYPT);
    $sql = 'update users set `col_x` = ? where id = ?';

    expect($this->redactor->redactBindings($sql, [$hash, 1]))->toBe([RED, 1]);
});

it('normalizes binding types', function (): void {
    $sql = 'insert into t (a, b, c, d, e) values (?, ?, ?, ?, ?)';

    expect($this->redactor->redactBindings($sql, [new DateTimeImmutable('2024-05-06 07:08:09'), true, null, 1.5, new stdClass]))
        ->toBe(['2024-05-06 07:08:09', true, null, 1.5, '[object stdClass]']);
});

it('drops sensitive headers', function (): void {
    $headers = [
        'Authorization' => ['Bearer abc'],
        'Cookie' => ['laravel_session=abc'],
        'X-Api-Key' => ['k'],
        'X-Tracing' => ['secret'],
        'X-Custom-Token' => ['t'],
        'Accept' => ['application/json'],
        'X-Forwarded-For' => ['10.0.0.1'],
    ];

    expect($this->redactor->redactHeaders($headers))->toBe([
        'Accept' => ['application/json'],
        'X-Forwarded-For' => [RED],
    ]);
});

it('redacts URLs', function (): void {
    expect($this->redactor->redactUrl('https://user:pass@api.example.com/v1/users?token=abc&page=2&email=a%40b.com'))
        ->toBe('https://'.RED.'@api.example.com/v1/users?token='.RED.'&page=2&email='.RED)
        ->and($this->redactor->redactUrl('https://api.example.com/reset/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b'))
        ->toBe('https://api.example.com/reset/'.RED);
});

it('keeps clean URL-encoded query values as they are', function (): void {
    expect($this->redactor->redactUrl('https://api.example.com/search?q=red%20shoes&sort=price+asc&page=2'))
        ->toBe('https://api.example.com/search?q=red%20shoes&sort=price+asc&page=2')
        ->and($this->redactor->redactUrl('https://api.example.com/u?contact=jane.doe%40example.com&x=1'))
        ->toBe('https://api.example.com/u?contact='.RED.'&x=1')
        ->and($this->redactor->redactUrl('/users/jane.doe%40example.com/orders'))
        ->toBe('/users/'.RED.'/orders')
        ->and($this->redactor->redactUrl('/files/annual%20report'))
        ->toBe('/files/annual%20report');
});

it('does not memoize the column map of huge statements (bulk inserts)', function (): void {
    $sql = 'insert into t (a, b) values '.implode(', ', array_fill(0, 2000, '(?, ?)'));
    $this->redactor->redactBindings($sql, array_fill(0, 4000, 'x'));

    $cache = (new ReflectionProperty(Redactor::class, 'columnCache'))->getValue($this->redactor);

    expect($cache)->not->toHaveKey($sql);
});

it('caches the column map per SQL and stays bounded', function (): void {
    for ($i = 0; $i < 600; $i++) {
        $this->redactor->redactBindings("select * from t{$i} where password = ?", ['x']);
    }

    $cache = (new ReflectionProperty(Redactor::class, 'columnCache'))->getValue($this->redactor);

    expect(count($cache))->toBeLessThanOrEqual(500);
});

it('is fast on typical bindings', function (): void {
    $sql = 'select * from `orders` where `user_id` = ? and `status` = ? and `password` = ? limit 15';
    $start = hrtime(true);
    for ($i = 0; $i < 5000; $i++) {
        $this->redactor->redactBindings($sql, [$i, 'paid', 'x']);
    }

    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(1000.0);
});
