<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\ContextManager;
use Anabil\Sherlock\Sherlock;
use Anabil\Sherlock\Storage\Support\QuietReporter;
use Anabil\Sherlock\Storage\Support\RecordingPauser;
use Anabil\Sherlock\Storage\Support\Values;
use Anabil\Sherlock\Storage\Tables;
use Illuminate\Container\Container;
use Psr\Log\AbstractLogger;

it('names the tables with the prefix', function (): void {
    expect((new Tables)->all())->toBe(['sherlock_traces', 'sherlock_queries', 'sherlock_issues', 'sherlock_issue_traces'])
        ->and((new Tables('perf_'))->issues())->toBe('perf_issues');
});

it('converts dates to the default timezone and back', function (): void {
    $previous = date_default_timezone_get();
    date_default_timezone_set('UTC');

    try {
        $date = new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('Africa/Cairo'));

        expect(Values::date($date))->toBe('2026-10-20 09:00:00')
            ->and(Values::toDate('2026-10-20 09:00:00')?->format(DATE_ATOM))->toBe('2026-10-20T09:00:00+00:00')
            ->and(Values::toDate(null))->toBeNull()
            ->and(Values::toDate(''))->toBeNull();
    } finally {
        date_default_timezone_set($previous);
    }
});

it('encodes json defensively and coerces scalars', function (): void {
    expect(Values::json(['a' => "\xB1\x31", 'n' => INF]))->toBeString()
        ->and(Values::fromJson('{"a":1}'))->toBe(['a' => 1])
        ->and(Values::fromJson('nope'))->toBeNull()
        ->and(Values::fromJson(null))->toBeNull()
        ->and(Values::truncate(str_repeat('x', 300), 255))->toHaveLength(255)
        ->and(Values::truncate('short', 255))->toBe('short')
        ->and(Values::id('42'))->toBe(42)
        ->and(Values::id('01J9ABC'))->toBe('01J9ABC')
        ->and(Values::userId(''))->toBeNull()
        ->and(Values::userId('15'))->toBe(15)
        ->and(Values::userId('uuid-1'))->toBe('uuid-1')
        ->and(Values::nullableInt(null))->toBeNull()
        ->and(Values::float('1.5'))->toBe(1.5);
});

it('runs callbacks directly when no pause hook exists', function (): void {
    expect((new RecordingPauser)->run(fn (): int => 7))->toBe(7);

    $container = new Container;
    $container->instance(Sherlock::class, new stdClass);

    expect((new RecordingPauser($container))->run(fn (): string => 'ok'))->toBe('ok');
});

it('uses a pause hook on the context manager', function (): void {
    $manager = new class
    {
        public int $paused = 0;

        public function pause(Closure $callback): void
        {
            $this->paused++;
            $callback(); // returns nothing: the pauser still hands back the callback result
        }
    };
    $container = new Container;
    $container->instance(ContextManager::class, $manager);
    $pauser = new RecordingPauser($container);

    expect($pauser->run(fn (): string => 'stored'))->toBe('stored')
        ->and($pauser->run(fn (): int => 2))->toBe(2)
        ->and($manager->paused)->toBe(2);
});

it('reports each distinct failure once', function (): void {
    QuietReporter::flush();
    $logger = new class extends AbstractLogger
    {
        public array $messages = [];

        public function log($level, Stringable|string $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
    $reporter = new QuietReporter($logger);

    $reporter->report('failed to persist trace', new RuntimeException('down'));
    $reporter->report('failed to persist trace', new RuntimeException('down'));
    $reporter->report('failed to persist trace', new RuntimeException('other'));
    (new QuietReporter)->report('no logger', new RuntimeException('x'));

    expect($logger->messages)->toBe([
        '[sherlock] failed to persist trace: down',
        '[sherlock] failed to persist trace: other',
    ]);

    QuietReporter::flush();
});
