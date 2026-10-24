<?php

declare(strict_types=1);

use Anabil\Sherlock\Data\Finding;
use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Notifications\Formatters\MailFormatter;
use Anabil\Sherlock\Notifications\Formatters\SlackFormatter;
use Anabil\Sherlock\Notifications\Formatters\TelegramFormatter;
use Anabil\Sherlock\Notifications\IssueMessageFactory;
use Anabil\Sherlock\Notifications\Mail\IssueNotificationMail;
use Anabil\Sherlock\Tests\Feature\Notifications\NotificationFixtures as N;
use Carbon\CarbonImmutable;

it('renders the release, first-seen release and total impact in Slack, Telegram and mail', function (): void {
    N::configure();
    $finding = new Finding(
        IssueType::NPlusOne,
        Severity::Critical,
        'N+1 queries on orders',
        'The same query ran 38 times.',
        'notif-release',
        impactMs: 95_000.0,
    );
    $outcome = N::issues()->recordOccurrence($finding, $finding->toArray(), CarbonImmutable::now(), null, null, 'abc1234');
    $message = app(IssueMessageFactory::class)->newIssue(N::find($outcome->issueId));

    expect($message->summary)->toContain('First seen in release abc1234.')
        ->and($message->fields['Release'])->toBe('abc1234')
        ->and($message->fields)->toHaveKey('Total impact');

    $slack = json_encode((new SlackFormatter)->format($message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $telegram = (new TelegramFormatter)->text($message);
    $formatter = new MailFormatter;
    $mail = (new IssueNotificationMail($formatter->subject($message), $formatter->data($message)))->render();

    foreach ([$slack, $telegram, $mail] as $rendered) {
        expect($rendered)->toContain('Release')
            ->toContain('abc1234')
            ->toContain('Total impact')
            ->toContain($message->fields['Total impact']);
    }
});
