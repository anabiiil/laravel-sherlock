<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\IssueRepository;
use Anabil\Sherlock\Data\EntryDelta;
use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Enums\Bottleneck;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Http\Support\Badge;
use Anabil\Sherlock\Http\Support\Format;
use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;
use Anabil\Sherlock\Tests\Feature\Dashboard\ReleaseFixtures as R;
use Carbon\CarbonImmutable;

beforeEach(fn () => D::allowAccess());

function impactIssue(string $fingerprint, string $title, float $impactMs, ?string $release = null, array $context = []): void
{
    app(IssueRepository::class)->recordOccurrence(
        new Finding(IssueType::NPlusOne, Severity::Warning, $title, 'd', $fingerprint, impactMs: $impactMs),
        $context,
        CarbonImmutable::now()->subMinutes(3),
        null,
        null,
        $release,
    );
}

it('formats impact for humans', function (): void {
    expect(Format::human(850.0))->toBe('850 ms')
        ->and(Format::human(12_400.0))->toBe('12.4 s')
        ->and(Format::human(192_000.0))->toBe('3.2 min')
        ->and(Format::human(5_400_000.0))->toBe('1.5 h')
        ->and(Format::signedRatio(0.25))->toBe('+25%')
        ->and(Format::signedRatio(-0.5))->toBe('-50%')
        ->and(Format::signedMs(-1200.0))->toBe('-1.20 s')
        ->and(Format::signedNumber(3.0))->toBe('+3')
        ->and(Format::signedNumber(null))->toBe('-');
});

it('has a badge for every bottleneck and comparison status', function (): void {
    foreach (Bottleneck::cases() as $bottleneck) {
        expect(Badge::bottleneck($bottleneck))->toBeString()->not->toBe('');
    }

    foreach ([EntryDelta::SLOWER, EntryDelta::FASTER, EntryDelta::NEW, EntryDelta::GONE, EntryDelta::UNCHANGED] as $status) {
        expect(Badge::delta($status))->toBeString()->not->toBe('');
    }
});

it('renders every page for every bottleneck without an unhandled match', function (Bottleneck $bottleneck): void {
    $id = D::trace('GET /'.$bottleneck->value, bottleneck: $bottleneck, at: CarbonImmutable::now()->subMinutes(5));

    $this->get('/sherlock')->assertOk()->assertSee($bottleneck->label());
    $this->get('/sherlock/traces')->assertOk()->assertSee($bottleneck->label());
    $this->get('/sherlock/traces?bottleneck='.$bottleneck->value)->assertOk()->assertSee('GET /'.$bottleneck->value);
    $this->get('/sherlock/traces/'.$id)->assertOk()->assertSee($bottleneck->label());

    D::issueWith('fp-bn-'.$bottleneck->value, 'Issue with '.$bottleneck->value, context: ['verdict' => ['bottleneck' => $bottleneck->value, 'reason' => 'r']]);
    $this->get('/sherlock/issues/'.D::issue('fp-bn-'.$bottleneck->value)->id)->assertOk()->assertSee($bottleneck->label());
})->with(fn (): array => array_combine(
    array_map(fn (Bottleneck $b): string => $b->value, Bottleneck::cases()),
    array_map(fn (Bottleneck $b): array => [$b], Bottleneck::cases()),
));

it('renders every page for every issue type without an unhandled match', function (IssueType $type): void {
    $issue = D::issueWith('fp-type-'.$type->value, 'Issue of type '.$type->value, $type);

    $this->get('/sherlock/issues')->assertOk()->assertSee('Issue of type '.$type->value)->assertSee($type->label());
    $this->get('/sherlock/issues?type='.$type->value)->assertOk()->assertSee('Issue of type '.$type->value);
    $this->get('/sherlock/issues/'.$issue->id)->assertOk()->assertSee($type->label());
    $this->get('/sherlock')->assertOk();
    $this->get('/sherlock/deploys')->assertOk();
})->with(fn (): array => array_combine(
    array_map(fn (IssueType $t): string => $t->value, IssueType::cases()),
    array_map(fn (IssueType $t): array => [$t], IssueType::cases()),
));

it('sorts issues by impact and shows an impact column', function (): void {
    impactIssue('imp-small', 'Small cost issue', 1500.0);
    impactIssue('imp-big', 'Big cost issue', 192_000.0);
    impactIssue('imp-none', 'No cost issue', 0.0);

    $this->get('/sherlock/issues?sort=impact')
        ->assertOk()
        ->assertSee('Impact')
        ->assertSeeInOrder(['Big cost issue', 'Small cost issue', 'No cost issue'])
        ->assertSee('3.2 min')
        ->assertSee('1.5 s');

    // Default sort stays "last seen"; the Impact option is offered.
    $this->get('/sherlock/issues')->assertOk()->assertSee('<option value="impact"', false);
});

it('filters issues and traces by release', function (): void {
    impactIssue('rel-a', 'Born in release A', 10.0, 'rel-a1');
    impactIssue('rel-b', 'Born in release B', 10.0, 'rel-b2');
    R::trace('rel-a1', 'GET /from-a', 100.0, CarbonImmutable::now()->subDays(2));
    R::trace('rel-b2', 'GET /from-b', 100.0, CarbonImmutable::now()->subDay());

    $this->get('/sherlock/issues?release=rel-a1')->assertOk()
        ->assertSee('Born in release A')->assertDontSee('Born in release B');
    $this->get('/sherlock/traces?release=rel-b2')->assertOk()
        ->assertSee('GET /from-b')->assertDontSee('GET /from-a');
    // Malformed values are ignored.
    $this->get('/sherlock/traces?release=%3Cx%3E')->assertOk()
        ->assertSee('GET /from-a')->assertSee('GET /from-b');
});

it('shows impact, releases, the relation and signals on the issue page', function (): void {
    impactIssue('imp-page', 'N+1 lazy loading Feed::items', 95_000.0, 'v9', [
        'metrics' => ['count' => 12, 'relation' => 'items', 'model' => 'App\\Models\\Feed', 'relation_source' => 'lazy_loading'],
        'trace' => ['duration_ms' => 2000.0, 'cpu_ms' => 200.0, 'load_avg' => 7.92, 'cpu_cores' => 4, 'memory_free_mb' => 512, 'queue_wait_ms' => 45000.0, 'release' => 'v9'],
        'lazy_loads' => [['model' => 'App\\Models\\Feed', 'relation' => 'items', 'count' => 12, 'table' => 'feed_items']],
    ]);

    $this->get('/sherlock/issues/'.D::issue('imp-page')->id)
        ->assertOk()
        ->assertSee('Impact')
        ->assertSee('1.6 min')
        ->assertSee('v9')
        ->assertSee('App\\Models\\Feed::items')
        ->assertSee('lazy loading')
        ->assertSee('Feed::items')
        ->assertSee("->with('items')", false)
        ->assertSee('Server signals')
        ->assertSee('10% of wall time')
        ->assertSee('7.92 on 4 cores')
        ->assertSee('198%')
        ->assertSee('512.0 MB')
        ->assertSee('45.00 s');
});

it('marks a guessed N+1 relation', function (): void {
    impactIssue('imp-guess', 'N+1 guessed', 10.0, null, ['metrics' => ['relation' => 'posts', 'model' => null, 'relation_source' => 'guess']]);

    $this->get('/sherlock/issues/'.D::issue('imp-guess')->id)->assertOk()->assertSee('posts')->assertSee('guessed');
});

it('shows server signals, release, lazy loads and the memory leak on the trace page', function (): void {
    $id = R::signalTrace();

    $this->get('/sherlock/traces/'.$id)
        ->assertOk()
        ->assertSee('Server load')
        ->assertSee('Server signals')
        ->assertSee('CPU time')
        ->assertSee('200 ms (10% of wall time)')
        ->assertSee('Wall time')
        ->assertSee('7.92 on 4 cores')
        ->assertSee('Load per core')
        ->assertSee('198%')
        ->assertSee('Free memory')
        ->assertSee('512.0 MB')
        ->assertSee('Queue wait')
        ->assertSee('45.00 s')
        ->assertSee('mostly')
        ->assertSee(R::CURRENT)
        ->assertSee('Lazy loading violations')
        ->assertSee('Feed::items')
        ->assertSee('12×')
        ->assertSee('Worker memory leak')
        ->assertSee('40.0 MB')
        ->assertSee('App\\Jobs\\ImportFeed');
});

it('says when a trace has no server signals', function (): void {
    $id = D::trace();

    $this->get('/sherlock/traces/'.$id)->assertOk()->assertSee('No server signals recorded')->assertDontSee('Worker memory leak');
});

it('shows the top issues by impact and the latest deploy on the overview', function (): void {
    impactIssue('ov-big', 'Costly N+1 on orders', 600_000.0);
    impactIssue('ov-zero', 'Zero cost issue', 0.0);

    $this->get('/sherlock')
        ->assertOk()
        ->assertSee('Top issues by impact')
        ->assertSee('Costly N+1 on orders')
        ->assertSee('10.0 min')
        ->assertSee('Latest deploy')
        ->assertSee('Not enough releases to compare yet.');

    R::seed();

    $this->get('/sherlock')
        ->assertOk()
        ->assertSee('1 route slower, 1 new issue, 1 regressed issue since deploy '.R::CURRENT)
        ->assertSee('GET /orders')
        ->assertSee('+200%');
});

it('shows the new bottleneck verdicts in the distribution', function (): void {
    D::trace('GET /a', bottleneck: Bottleneck::ServerLoad);
    D::trace('App\\Jobs\\B', EntryType::Job, bottleneck: Bottleneck::QueueBacklog);
    D::trace('GET /c', bottleneck: Bottleneck::Waiting);

    $this->get('/sherlock')
        ->assertOk()
        ->assertSee('Server load')
        ->assertSee('Queue backlog')
        ->assertSee('Waiting / blocked')
        ->assertSee('bg-orange-500')
        ->assertSee('bg-fuchsia-500')
        ->assertSee('bg-yellow-500');
});
