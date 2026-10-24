<?php

declare(strict_types=1);

use Anabil\Tracing\Support\SqlQueryNormalizer;

beforeEach(function (): void {
    $this->normalizer = new SqlQueryNormalizer;
});

it('normalizes SQL', function (string $sql, string $expected): void {
    expect($this->normalizer->normalize($sql))->toBe($expected);
})->with([
    'numbers' => ['select * from users where id = 15', 'select * from users where id = ?'],
    'decimals and exponents' => ['select * from p where price > 10.50 and x < 1e10', 'select * from p where price > ? and x < ?'],
    'single quoted string' => ["select * from users where email = 'a@b.com'", 'select * from users where email = ?'],
    'escaped quotes' => ["select * from t where name = 'O''Brien' and b = 'it\\'s'", 'select * from t where name = ? and b = ?'],
    'string with comment markers' => ["select * from t where a = '-- not a comment /* x */'", 'select * from t where a = ?'],
    'prefixed strings' => ["select * from t where a = N'abc' and b = E'x'", 'select * from t where a = ? and b = ?'],
    'identifiers with digits kept' => ['select t1.col2 from table3 t1', 'select t1.col2 from table3 t1'],
    'backtick identifiers kept' => ['select `users`.* from `users` where `users`.`id` = ?', 'select `users`.* from `users` where `users`.`id` = ?'],
    'postgres identifiers kept' => ['select "users".* from "users" where "id" = $1', 'select "users".* from "users" where "id" = ?'],
    'named placeholders' => ['select * from t where a = :email and b = :id', 'select * from t where a = ? and b = ?'],
    'postgres casts kept' => ['select a::text from t where b = 5', 'select a::text from t where b = ?'],
    'hex' => ['select * from t where h = 0xFF', 'select * from t where h = ?'],
    'in list of placeholders' => ['select * from users where id in (?, ?, ?)', 'select * from users where id IN (?+)'],
    'in list of literals' => ['select * from users where id IN (1,2,3,4)', 'select * from users where id IN (?+)'],
    'in list single' => ['select * from users where id in (?)', 'select * from users where id IN (?+)'],
    'not in' => ["select * from t where s not in ('a', 'b')", 'select * from t where s not IN (?+)'],
    'multi-row values collapse' => ['insert into t (a, b) values (?, ?), (?, ?), (?, ?)', 'insert into t (a, b) VALUES (?, ?)'],
    'values literals' => ["insert into t (a,b) values (1,'x'),(2,'y')", 'insert into t (a, b) VALUES (?, ?)'],
    'single values unchanged' => ['insert into t (a, b) values (?, ?)', 'insert into t (a, b) values (?, ?)'],
    'whitespace collapse' => ["select *\n  from   users\twhere id = ?", 'select * from users where id = ?'],
    'paren spacing' => ['select count( * ) from t where ( a = 1 )', 'select count(*) from t where (a = ?)'],
    'block comment' => ['select /* hint */ * from t', 'select * from t'],
    'line comment' => ["select * from t -- trailing\nwhere a = 1", 'select * from t where a = ?'],
    'limit offset' => ['select * from t limit 10 offset 20', 'select * from t limit ? offset ?'],
    'trailing semicolon' => ['select 1;', 'select ?'],
    'question mark operators kept' => ['select * from t where data ?? ?', 'select * from t where data ?? ?'],
]);

it('produces the same output for queries differing only in literals', function (): void {
    $a = $this->normalizer->normalize("select * from orders where user_id = 1 and status = 'paid' and id in (1, 2)");
    $b = $this->normalizer->normalize("select * from orders where user_id = 99 and status = 'open' and id in (7,8,9,10)");

    expect($a)->toBe($b);
});

it('memoizes results and stays bounded', function (): void {
    for ($i = 0; $i < 1500; $i++) {
        $this->normalizer->normalize("select * from t where id = {$i} /* {$i} */");
    }

    expect($this->normalizer->normalize('select * from t where id = 1'))->toBe('select * from t where id = ?');

    $cache = (new ReflectionProperty(SqlQueryNormalizer::class, 'cache'))->getValue($this->normalizer);
    expect(count($cache))->toBeLessThanOrEqual(1000);
});

it('is fast enough for terminate-time use', function (): void {
    $start = hrtime(true);
    for ($i = 0; $i < 2000; $i++) {
        (new SqlQueryNormalizer)->normalize("select * from `orders` where `user_id` = {$i} and `status` in ('a', 'b', 'c') limit 15");
    }
    $ms = (hrtime(true) - $start) / 1e6;

    expect($ms)->toBeLessThan(500.0);
});
