<?php

declare(strict_types=1);

use Anabil\Tracing\Data\SourceLocation;
use Anabil\Tracing\Enums\IssueType;
use Anabil\Tracing\Support\HashFingerprinter;
use Anabil\Tracing\Support\SqlQueryNormalizer;

beforeEach(function (): void {
    $this->fp = new HashFingerprinter(new SqlQueryNormalizer);
});

it('produces short stable hex hashes', function (): void {
    $hash = $this->fp->query('select * from users where id = ?');

    expect($hash)->toMatch('/^[a-f0-9]{32}$/')
        ->and($this->fp->query('select * from users where id = ?'))->toBe($hash)
        // stable across processes / versions (xxh128 of the lowercased SQL)
        ->and($hash)->toBe(hash('xxh128', 'select * from users where id = ?'));
});

it('is case-insensitive for queries', function (): void {
    expect($this->fp->query('SELECT * FROM users WHERE id = ?'))
        ->toBe($this->fp->query('select * from users where id = ?'));
});

it('fingerprints raw SQL through the normalizer', function (): void {
    expect($this->fp->rawQuery('select * from users where id = 5'))
        ->toBe($this->fp->rawQuery('SELECT * FROM users WHERE id = 42'))
        ->and($this->fp->rawQuery('select * from users where id = 5'))
        ->not->toBe($this->fp->rawQuery('select * from posts where id = 5'));
});

it('builds issue fingerprints from type, query, location and entry', function (): void {
    $location = new SourceLocation('app/Http/Controllers/OrderController.php', 42);
    $base = $this->fp->issue(IssueType::NPlusOne, 'abc', $location, 'GET /orders');

    expect($base)->toMatch('/^[a-f0-9]{32}$/')
        ->and($this->fp->issue(IssueType::NPlusOne, 'abc', new SourceLocation('app/Http/Controllers/OrderController.php', 42), 'GET /orders'))->toBe($base)
        ->and($this->fp->issue(IssueType::SlowQuery, 'abc', $location, 'GET /orders'))->not->toBe($base)
        ->and($this->fp->issue(IssueType::NPlusOne, 'abd', $location, 'GET /orders'))->not->toBe($base)
        ->and($this->fp->issue(IssueType::NPlusOne, 'abc', new SourceLocation('app/Http/Controllers/OrderController.php', 43), 'GET /orders'))->not->toBe($base)
        ->and($this->fp->issue(IssueType::NPlusOne, 'abc', $location, 'GET /users'))->not->toBe($base)
        ->and($this->fp->issue(IssueType::Exception, null, $location, 'GET /orders', 'RuntimeException'))
        ->not->toBe($this->fp->issue(IssueType::Exception, null, $location, 'GET /orders', 'LogicException'));
});

it('does not collide when fields shift between positions', function (): void {
    expect($this->fp->issue(IssueType::SlowRequest, null, null, 'ab'))
        ->not->toBe($this->fp->issue(IssueType::SlowRequest, 'ab'));
});
