<?php

declare(strict_types=1);

use Anabil\Tracing\Data\NotificationMessage;
use Anabil\Tracing\Enums\Severity;
use Anabil\Tracing\Notifications\Formatters\MailFormatter;
use Anabil\Tracing\Notifications\IssueMessageFactory;
use Anabil\Tracing\Notifications\Mail\IssueNotificationMail;
use Anabil\Tracing\Tests\Feature\Notifications\NotificationFixtures as N;
use Illuminate\Support\Facades\Route;

function notifMail(array $overrides = []): IssueNotificationMail
{
    $formatter = new MailFormatter;
    $message = new NotificationMessage(...array_merge([
        'title' => 'Slow query on <script>alert(1)</script>',
        'severity' => Severity::Critical,
        'summary' => 'Occurred 340 more times in the last hour (341 in total).',
        'fields' => ['Entry' => 'GET orders.index', 'Verdict' => 'a < b & c'],
        'sql' => 'select * from `orders` where `total` > ?',
        'aiRecommendation' => 'Add an index on orders(user_id).',
        'url' => 'https://example.test/tracing/issues/7',
        'occurrences' => 340,
        'footer' => 'Tracing · Shop (testing)',
    ], $overrides));

    return new IssueNotificationMail($formatter->subject($message), $formatter->data($message));
}

it('renders the HTML mail with every value escaped', function (): void {
    $html = notifMail()->render();

    expect($html)
        ->toContain('Slow query on &lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>')
        ->toContain('a &lt; b &amp; c')
        ->toContain('select * from `orders` where `total` &gt; ?')
        ->toContain('AI recommendation')
        ->toContain('Occurrences in this message: <strong>340</strong>')
        ->toContain('href="https://example.test/tracing/issues/7"')
        ->toContain('#E01E5A')
        ->toContain('Tracing · Shop (testing)');
});

it('attaches a plain-text part', function (): void {
    $mail = notifMail(['aiRecommendation' => null, 'url' => null, 'sql' => null]);
    $mail->build();

    $email = new Symfony\Component\Mime\Email;
    foreach ((new ReflectionProperty($mail, 'callbacks'))->getValue($mail) as $callback) {
        $callback($email);
    }

    expect($email->getTextBody())
        ->toContain('🚨 [Critical] Slow query on <script>alert(1)</script>')
        ->toContain('Verdict: a < b & c')
        ->not->toContain('AI recommendation')
        ->not->toContain('Open in dashboard')
        ->and($mail->render())->not->toContain('Open in dashboard');
});

it('links to the dashboard issue page when the dashboard route exists', function (): void {
    N::configure();
    if (! Route::has('tracing.issues.show')) {
        Route::get('tracing/issues/{issue}', fn () => 'ok')->name('tracing.issues.show');
        app('router')->getRoutes()->refreshNameLookups();
    }

    $factory = app(IssueMessageFactory::class);

    expect($factory->dashboardUrl(7))->toBe('http://localhost/tracing/issues/7');

    config()->set('tracing.notifications.dashboard_url', 'https://ops.example.test/');
    expect($factory->dashboardUrl(7))->toBe('https://ops.example.test/tracing/issues/7');

    config()->set('tracing.dashboard.enabled', false);
    expect($factory->dashboardUrl(7))->toBeNull();
});
