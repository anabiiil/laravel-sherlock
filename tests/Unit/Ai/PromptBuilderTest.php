<?php

declare(strict_types=1);

use Anabil\Sherlock\Ai\PromptBuilder;
use Anabil\Sherlock\Data\IssueRecord;
use Anabil\Sherlock\Enums\IssueStatus;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Privacy\Redactor;
use Illuminate\Config\Repository;

function aiPromptIssue(IssueType $type = IssueType::SlowQuery, array $context = []): IssueRecord
{
    $context += [
        'type' => $type->value,
        'severity' => 'critical',
        'title' => 'Slow query on orders',
        'description' => 'Query took 912ms',
        'metrics' => ['time_ms' => 912.4, 'count' => 1],
        'location' => ['file' => 'app/Http/Controllers/OrderController.php', 'line' => 3, 'class' => 'App\\Http\\Controllers\\OrderController', 'function' => 'index'],
        'sql' => 'select * from `orders` inner join `users` on `users`.`id` = `orders`.`user_id` where `orders`.`status` = ?',
        'recommendation' => 'Add an index on orders.status',
        // Must never reach the prompt (context is whitelisted).
        'bindings' => ['binding-secret-value'],
        'entry' => ['type' => 'request', 'name' => 'GET /orders', 'method' => 'GET', 'route' => 'orders.index', 'uri' => '/orders?email=uri-leak@example.com', 'status_code' => 200, 'failed' => false],
        'trace' => ['id' => 't1', 'duration_ms' => 1200.5, 'db_ms' => 950.0, 'db_count' => 4, 'http_ms' => 0.0, 'http_count' => 0, 'cache_hits' => 1, 'cache_misses' => 2, 'bootstrap_ms' => 10.0, 'memory_peak' => 16_777_216],
        'verdict' => ['bottleneck' => 'database', 'reason' => '79% of 1.2s spent in 4 queries', 'total_ms' => 1200.5, 'breakdown_ms' => ['db' => 950.0], 'percentages' => ['db' => 79.1]],
        'explain' => [
            'connection' => 'mysql', 'driver' => 'mysql', 'sql' => 'select ...',
            'raw' => ['attached_condition' => "status = 'raw-plan-secret'"],
            'insights' => [['type' => 'full_table_scan', 'severity' => 'critical', 'message' => 'Full table scan on `orders`', 'table' => 'orders', 'suggested_index' => 'orders(status)', 'estimated_rows' => 120000]],
        ],
        'query' => ['fingerprint' => 'fp', 'count' => 1, 'total_ms' => 912.4, 'max_ms' => 912.4, 'connection' => 'mysql', 'driver' => 'mysql'],
    ];

    return new IssueRecord(
        7, 'fp-issue', $type, Severity::Critical, IssueStatus::Open, 'Slow query on orders', $context, 12,
        new DateTimeImmutable('2026-10-01 10:00'), new DateTimeImmutable('2026-10-24 10:00'),
    );
}

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/sherlock-ai-'.bin2hex(random_bytes(4));
    $this->outside = $this->base.'-outside';
    mkdir($this->base.'/app/Http/Controllers', 0777, true);
    mkdir($this->outside, 0777, true);

    file_put_contents($this->base.'/app/Http/Controllers/OrderController.php', implode("\n", [
        '<?php',
        'class OrderController {',
        '    public function index() { return Order::where("status", "paid")->get(); }',
        '    private $token = "password=hunter2";',
        '    // contact admin@example.com',
        '}',
    ]));
    file_put_contents($this->base.'/.env', "APP_KEY=base64:supersecret\nDB_PASSWORD=dbsecret\n");
    file_put_contents($this->outside.'/secret.php', "<?php // outside-secret\n");
    @symlink($this->outside.'/secret.php', $this->base.'/app/linked.php');
    mkdir($this->base.'/storage/logs', 0777, true);
    file_put_contents($this->base.'/storage/logs/laravel.log', "dbsecret in a log line\n");
    file_put_contents($this->base.'/storage/logs/cached.php', "<?php // dbsecret\n");
    file_put_contents($this->base.'/app/notes.txt', "supersecret\n");

    $this->config = new Repository(['sherlock' => ['ai' => [
        'language' => 'en', 'max_tokens' => 1200, 'timeout' => 25,
        'send_code_snippet' => false, 'snippet_lines' => 2, 'include_schema' => true,
    ]]]);
    $this->builder = new PromptBuilder($this->config, Redactor::make(), null, $this->base);
});

afterEach(function (): void {
    foreach ([$this->base, $this->outside] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
});

it('builds a request with config limits and a structured system prompt', function (): void {
    $request = $this->builder->build(aiPromptIssue());

    expect($request->maxTokens)->toBe(1200)
        ->and($request->timeout)->toBe(25)
        ->and($request->temperature)->toBeNull()
        ->and($request->model)->toBeNull()
        ->and($request->systemPrompt)->toContain('senior Laravel performance engineer')
        ->toContain('## Root cause')
        ->toContain('## Fix')
        ->toContain('## Code example')
        ->toContain('## Expected impact')
        ->toContain('under 1200 tokens')
        ->toContain('in English');
});

it('answers in the configured language', function (): void {
    $this->config->set('sherlock.ai.language', 'ar');

    expect($this->builder->systemPrompt())->toContain('in Arabic');
});

it('includes the evidence: normalized SQL, explain insights, verdict, metrics, location', function (): void {
    $prompt = $this->builder->userPrompt(aiPromptIssue());

    expect($prompt)
        ->toContain('Slow query on orders')
        ->toContain('Occurrences: 12')
        ->toContain('Severity: Critical')
        ->toContain('where `orders`.`status` = ?')
        ->toContain('Full table scan on `orders`')
        ->toContain('suggested index: orders(status)')
        ->toContain('≈120000 rows')
        ->toContain('79% of 1.2s spent in 4 queries')
        ->toContain('db: 950')
        ->toContain('time_ms: 912.4')
        ->toContain('Peak memory (MB): 16')
        ->toContain('GET /orders')
        ->toContain('app/Http/Controllers/OrderController.php:3 in App\\Http\\Controllers\\OrderController::index()')
        ->toContain('Add an index on orders.status');
});

it('never includes bindings, the raw plan, the URI or the code snippet by default', function (): void {
    $prompt = $this->builder->userPrompt(aiPromptIssue());

    expect($prompt)
        ->not->toContain('binding-secret-value')
        ->not->toContain('raw-plan-secret')
        ->not->toContain('uri-leak@example.com')
        ->not->toContain('Order::where')
        ->not->toContain('```php');
});

it('uses a dedicated task template for every issue type', function (IssueType $type): void {
    $task = $this->builder->task($type);

    expect($task)->not->toBeEmpty()
        ->and($this->builder->userPrompt(aiPromptIssue($type)))->toContain("## Task\n".$task);
})->with(IssueType::cases());

it('finds the tables referenced by the SQL and the insights', function (): void {
    expect($this->builder->tables([
        'sql' => 'select * from `shop`.`orders` left join "users" on x = y where id in (select id from [payments])',
        'explain' => ['insights' => [['table' => 'line_items'], ['table' => 'orders']]],
    ]))->toBe(['orders', 'users', 'payments', 'line_items']);
});

it('includes a redacted snippet only when send_code_snippet is enabled', function (): void {
    $this->config->set('sherlock.ai.send_code_snippet', true);

    $prompt = $this->builder->userPrompt(aiPromptIssue());

    expect($prompt)->toContain('```php')
        ->toContain('>   3 |     public function index()')
        ->toContain('   1 | <?php')
        ->toContain('   5 |')
        ->not->toContain('hunter2')
        ->not->toContain('admin@example.com')
        // ±2 lines only.
        ->not->toContain('   6 | }');
});

it('never reads .env files or anything outside the base path', function (string $file): void {
    expect($this->builder->snippet($file, 1, 5))->toBeNull();

    $this->config->set('sherlock.ai.send_code_snippet', true);
    $prompt = $this->builder->userPrompt(aiPromptIssue(context: ['location' => ['file' => $file, 'line' => 1]]));

    expect($prompt)->not->toContain('supersecret')
        ->not->toContain('dbsecret')
        ->not->toContain('outside-secret')
        ->not->toContain('```php');
})->with([
    '.env' => '.env',
    'traversal to .env' => 'app/../.env',
    'outside base path' => '../'.'sherlock-outside-does-not-matter.php',
    'absolute outside path' => '/etc/hosts',
    'symlink pointing outside' => 'app/linked.php',
    'missing file' => 'app/Nope.php',
    'runtime log under storage' => 'storage/logs/laravel.log',
    'php file under storage' => 'storage/logs/cached.php',
    'non-php file' => 'app/notes.txt',
]);

it('rejects a traversal into a sibling directory', function (): void {
    $sibling = '../'.basename($this->outside).'/secret.php';

    expect($this->builder->snippet($sibling, 1, 5))->toBeNull();
});

it('includes exception class, redacted message and app frames only', function (): void {
    $prompt = $this->builder->userPrompt(aiPromptIssue(IssueType::Exception, [
        'sql' => null,
        'explain' => null,
        'exception' => [
            'level' => 'error',
            'class' => 'RuntimeException',
            'message' => 'Payment failed for jane@example.com',
            'file' => 'app/Services/Billing.php',
            'line' => 10,
            'frames' => [
                ['file' => 'vendor/laravel/framework/src/Foo.php', 'line' => 1, 'class' => 'Foo', 'function' => 'bar'],
                ['file' => 'app/Services/Billing.php', 'line' => 10, 'class' => 'App\\Services\\Billing', 'function' => 'charge'],
            ],
        ],
    ]));

    expect($prompt)->toContain('Class: RuntimeException')
        ->toContain('Payment failed for [REDACTED]')
        ->toContain('- app/Services/Billing.php:10 App\\Services\\Billing::charge()')
        ->not->toContain('vendor/laravel')
        ->not->toContain('jane@example.com')
        ->not->toContain('## SQL');
});

it('lists slow external calls by host and path only', function (): void {
    $prompt = $this->builder->userPrompt(aiPromptIssue(IssueType::SlowExternalCall, [
        'sql' => null,
        'http_calls' => [['method' => 'POST', 'url' => 'https://api.example.com/charge?customer=cus_123', 'status_code' => 200, 'duration_ms' => 1400.0]],
    ]));

    expect($prompt)->toContain('- POST api.example.com/charge → 200 in 1400 ms')
        ->not->toContain('cus_123');
});

it('skips the schema section without a database connection', function (): void {
    expect($this->builder->userPrompt(aiPromptIssue()))->not->toContain('Table schema');
});

it('says an issue appeared after the latest deploy, with server and lazy-load evidence', function (): void {
    $repository = Mockery::mock(Anabil\Sherlock\Contracts\TraceRepository::class);
    $repository->allows('releases')->with(2)->andReturn([
        new Anabil\Sherlock\Data\ReleaseRecord('v2', new DateTimeImmutable('2026-10-20'), new DateTimeImmutable('2026-10-24')),
        new Anabil\Sherlock\Data\ReleaseRecord('v1', new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-19')),
    ]);
    $builder = new PromptBuilder($this->config, Redactor::make(), null, $this->base, $repository);

    $base = aiPromptIssue(IssueType::NPlusOne, [
        'trace' => ['duration_ms' => 1200.5, 'cpu_ms' => 40.0, 'load_avg' => 7.9, 'cpu_cores' => 4, 'queue_wait_ms' => 1500.0],
        'lazy_loads' => [['model' => 'App\\Models\\User', 'relation' => 'posts', 'count' => 12, 'table' => 'posts']],
    ]);
    $issue = new IssueRecord(
        $base->id, $base->fingerprint, $base->type, $base->severity, $base->status, $base->title, $base->context, $base->occurrences,
        $base->firstSeenAt, $base->lastSeenAt, firstSeenRelease: 'v2', lastSeenRelease: 'v2',
    );

    $prompt = $builder->userPrompt($issue);

    expect($prompt)->toContain('- First seen in release: v2')
        ->and($prompt)->toContain('- Deploy: appeared after deploy v2 (previous release v1 did not have it)')
        ->and($prompt)->toContain('- CPU time of the process (ms): 40')
        ->and($prompt)->toContain('- Server load average (1 min): 7.9')
        ->and($prompt)->toContain('- Server CPU cores: 4')
        ->and($prompt)->toContain('- Queue wait before start (ms): 1500')
        ->and($prompt)->toContain('- App\\Models\\User::posts lazy loaded 12× (table posts)');

    $older = new IssueRecord(
        $base->id, $base->fingerprint, $base->type, $base->severity, $base->status, $base->title, $base->context, $base->occurrences,
        $base->firstSeenAt, $base->lastSeenAt, firstSeenRelease: 'v1',
    );

    expect($builder->userPrompt($older))->not->toContain('appeared after deploy')
        ->and($this->builder->userPrompt($issue))->not->toContain('appeared after deploy');
});

it('has task templates for server load, queue wait and memory leaks', function (): void {
    expect($this->builder->task(IssueType::ServerLoad))->toContain('overloaded')
        ->and($this->builder->task(IssueType::QueueWait))->toContain('worker')
        ->and($this->builder->task(IssueType::MemoryLeak))->toContain('--max-jobs');
});
