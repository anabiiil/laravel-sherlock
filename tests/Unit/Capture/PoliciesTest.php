<?php

declare(strict_types=1);

use Anabil\Sherlock\Contracts\RecordingPolicy;
use Anabil\Sherlock\Data\EntryCandidate;
use Anabil\Sherlock\Data\RecordingDecision;
use Anabil\Sherlock\Enums\EntryType;
use Anabil\Sherlock\Enums\RecordReason;
use Anabil\Sherlock\Recording\PatternList;
use Anabil\Sherlock\Recording\Policies\CompositeRecordingPolicy;
use Anabil\Sherlock\Recording\Policies\HeaderTriggerPolicy;
use Anabil\Sherlock\Recording\Policies\IgnorePolicy;
use Anabil\Sherlock\Recording\Policies\LocalPolicy;
use Anabil\Sherlock\Recording\Policies\OffPolicy;
use Anabil\Sherlock\Recording\Policies\ProductionPolicy;
use Anabil\Sherlock\Recording\Policies\WatchWindowPolicy;
use Anabil\Sherlock\Support\Null\NullWatchWindow;

function candidateRequest(string $path = 'api/orders', string $method = 'GET', ?string $header = null, ?string $route = null): EntryCandidate
{
    return new EntryCandidate(EntryType::Request, $path, $method, $path, $route, $header);
}

it('never captures when off', function (): void {
    expect((new OffPolicy)->decide(candidateRequest())->capture)->toBeFalse();
});

it('fully captures everything locally', function (): void {
    $decision = (new LocalPolicy)->decide(candidateRequest());

    expect($decision->capture)->toBeTrue()
        ->and($decision->full)->toBeTrue()
        ->and($decision->reasons)->toBe([RecordReason::Local]);
});

it('captures lightly in production and samples a fraction', function (): void {
    $sampled = new ProductionPolicy(0.1, static fn (): float => 0.05);
    $notSampled = new ProductionPolicy(0.1, static fn (): float => 0.5);

    expect($sampled->decide(candidateRequest())->reasons)->toBe([RecordReason::Sample])
        ->and($sampled->decide(candidateRequest())->full)->toBeFalse()
        ->and($notSampled->decide(candidateRequest())->reasons)->toBe([])
        ->and($notSampled->decide(candidateRequest())->capture)->toBeTrue()
        ->and((new ProductionPolicy(0.0, static fn (): float => 0.0))->decide(candidateRequest())->reasons)->toBe([])
        ->and((new ProductionPolicy(1.0))->decide(candidateRequest())->reasons)->toBe([RecordReason::Sample]);
});

it('samples roughly at the configured rate', function (): void {
    $policy = new ProductionPolicy(0.2);
    $sampled = 0;
    for ($i = 0; $i < 5000; $i++) {
        $sampled += $policy->decide(candidateRequest())->reasons === [] ? 0 : 1;
    }

    expect($sampled)->toBeGreaterThan(800)->toBeLessThan(1200);
});

it('triggers full capture with the right header secret only', function (): void {
    $policy = new HeaderTriggerPolicy('s3cret');

    expect($policy->decide(candidateRequest(header: 's3cret'))->reasons)->toBe([RecordReason::Header])
        ->and($policy->decide(candidateRequest(header: 's3cret'))->full)->toBeTrue()
        ->and($policy->decide(candidateRequest(header: 'nope'))->capture)->toBeFalse()
        ->and($policy->decide(candidateRequest())->capture)->toBeFalse()
        ->and($policy->decide(new EntryCandidate(EntryType::Job, 'Job', triggerHeader: 's3cret'))->capture)->toBeFalse()
        ->and((new HeaderTriggerPolicy(''))->decide(candidateRequest(header: ''))->capture)->toBeFalse()
        ->and((new HeaderTriggerPolicy(null))->isEnabled())->toBeFalse()
        ->and((new HeaderTriggerPolicy('s3cret', false))->decide(candidateRequest(header: 's3cret'))->capture)->toBeFalse();
});

it('ignores configured paths, methods, routes, jobs and commands', function (): void {
    $policy = new IgnorePolicy(
        paths: ['sherlock*', 'up', 'favicon.ico'],
        routes: ['internal.*'],
        jobs: ['Anabil\Sherlock\Jobs\*'],
        commands: ['queue:work', 'sherlock:*'],
        methods: ['options', 'HEAD'],
    );

    expect($policy->ignores(candidateRequest('sherlock/issues/1')))->toBeTrue()
        ->and($policy->ignores(candidateRequest('/up')))->toBeTrue()
        ->and($policy->ignores(candidateRequest('upload')))->toBeFalse()
        ->and($policy->ignores(candidateRequest('api/orders', 'OPTIONS')))->toBeTrue()
        ->and($policy->ignores(candidateRequest('api/orders', 'head')))->toBeTrue()
        ->and($policy->ignores(candidateRequest('api/orders', route: 'internal.ping')))->toBeTrue()
        ->and($policy->ignoresRoute('internal.ping'))->toBeTrue()
        ->and($policy->ignoresRoute(null))->toBeFalse()
        ->and($policy->ignores(candidateRequest('api/orders')))->toBeFalse()
        ->and($policy->ignores(new EntryCandidate(EntryType::Job, 'Anabil\Sherlock\Jobs\AnalyzeIssueJob')))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Job, 'App\Jobs\SendInvoice')))->toBeFalse()
        ->and($policy->ignores(new EntryCandidate(EntryType::Command, 'queue:work')))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Command, 'sherlock:watch')))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Command, 'app:import')))->toBeFalse()
        ->and($policy->decide(candidateRequest('up'))->capture)->toBeFalse()
        ->and($policy->decide(candidateRequest('api'))->capture)->toBeTrue();
});

it('builds the ignore policy from config', function (): void {
    $policy = IgnorePolicy::fromConfig(['paths' => ['admin*'], 'commands' => 'not-a-list']);

    expect($policy->ignores(candidateRequest('admin/users')))->toBeTrue()
        ->and($policy->ignores(new EntryCandidate(EntryType::Command, 'not-a-list')))->toBeFalse();
});

it('compiles wildcard patterns like Str::is', function (): void {
    $list = new PatternList(['api/*', 'exact', 'a.b?c*', 42, '']);

    expect($list->matches('api/orders/1'))->toBeTrue()
        ->and($list->matches('api'))->toBeFalse()
        ->and($list->matches('exact'))->toBeTrue()
        ->and($list->matches('exactly'))->toBeFalse()
        ->and($list->matches('a.b?c-anything'))->toBeTrue()
        ->and($list->matches('aXb?c'))->toBeFalse()
        ->and($list->matches(null))->toBeFalse()
        ->and($list->isEmpty())->toBeFalse()
        ->and((new PatternList([]))->isEmpty())->toBeTrue();
});

it('lets the ignore policy short-circuit the composite', function (): void {
    $exploding = new class implements RecordingPolicy
    {
        public function decide(EntryCandidate $candidate): RecordingDecision
        {
            throw new RuntimeException('must not be consulted');
        }
    };

    $policy = new CompositeRecordingPolicy([$exploding], new IgnorePolicy(paths: ['up']));

    expect($policy->decide(candidateRequest('up'))->capture)->toBeFalse();
});

it('merges decisions and stops once nothing can be added', function (): void {
    $exploding = new class implements RecordingPolicy
    {
        public function decide(EntryCandidate $candidate): RecordingDecision
        {
            throw new RuntimeException('must not be consulted');
        }
    };

    $header = new CompositeRecordingPolicy([new HeaderTriggerPolicy('s3cret'), $exploding]);
    $merged = new CompositeRecordingPolicy([
        new HeaderTriggerPolicy('s3cret'),
        new WatchWindowPolicy(new NullWatchWindow),
        new ProductionPolicy(1.0),
    ]);

    expect($header->decide(candidateRequest(header: 's3cret'))->reasons)->toBe([RecordReason::Header])
        ->and($merged->decide(candidateRequest())->capture)->toBeTrue()
        ->and($merged->decide(candidateRequest())->full)->toBeFalse()
        ->and($merged->decide(candidateRequest())->reasons)->toBe([RecordReason::Sample])
        ->and(new CompositeRecordingPolicy([]))->decide(candidateRequest())->capture->toBeFalse();
});
