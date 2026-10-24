<?php

declare(strict_types=1);

use Anabil\Sherlock\Data\NotificationMessage;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Notifications\Formatters\MailFormatter;
use Anabil\Sherlock\Notifications\Formatters\SlackFormatter;
use Anabil\Sherlock\Notifications\Formatters\TelegramFormatter;

function notifFormatterMessage(array $overrides = []): NotificationMessage
{
    return new NotificationMessage(...array_merge([
        'title' => 'Slow query on <orders> & co',
        'severity' => Severity::Critical,
        'summary' => 'Query took 912 ms',
        'fields' => ['Entry' => 'GET orders.index', 'Location' => 'app/Http/Controllers/OrderController.php:42'],
        'sql' => 'select * from `orders` where `user_id` = ? and total > ?',
        'aiRecommendation' => 'Add an index on orders(user_id).',
        'url' => 'https://example.test/sherlock/issues/7',
        'occurrences' => 1,
        'footer' => 'Sherlock · Shop (production)',
        'issueId' => 7,
        'fingerprint' => 'fp',
    ], $overrides));
}

describe('slack', function (): void {
    it('builds a Block Kit payload inside a severity-colored attachment', function (): void {
        $payload = (new SlackFormatter)->format(notifFormatterMessage(), ['username' => 'Sherlock', 'channel' => '#ops', 'unknown' => 'x']);

        $blocks = $payload['attachments'][0]['blocks'];
        $types = array_column($blocks, 'type');

        expect($payload['text'])->toBe('Critical: Slow query on <orders> & co')
            ->and($payload['username'])->toBe('Sherlock')
            ->and($payload['channel'])->toBe('#ops')
            ->and($payload)->not->toHaveKey('unknown')
            ->and($payload['attachments'][0]['color'])->toBe('#E01E5A')
            ->and($types)->toBe(['header', 'section', 'section', 'section', 'divider', 'section', 'context', 'actions'])
            ->and($blocks[0]['text'])->toBe(['type' => 'plain_text', 'text' => '🚨 Slow query on <orders> & co', 'emoji' => true])
            ->and($blocks[1]['text']['text'])->toBe('Query took 912 ms')
            ->and($blocks[2]['fields'][0])->toBe(['type' => 'mrkdwn', 'text' => "*Entry*\nGET orders.index"])
            ->and($blocks[3]['text']['text'])->toBe("```\nselect * from `orders` where `user_id` = ? and total &gt; ?\n```")
            ->and($blocks[5]['text']['text'])->toContain('AI recommendation')->toContain('orders(user_id)')
            ->and($blocks[6]['elements'][0]['text'])->toBe('Sherlock · Shop (production)')
            ->and($blocks[7]['elements'][0]['url'])->toBe('https://example.test/sherlock/issues/7');
    });

    it('omits optional blocks and maps severity colors', function (): void {
        $payload = (new SlackFormatter)->format(notifFormatterMessage([
            'severity' => Severity::Warning, 'fields' => [], 'sql' => null, 'aiRecommendation' => null, 'url' => null, 'footer' => null,
        ]));

        expect(array_column($payload['attachments'][0]['blocks'], 'type'))->toBe(['header', 'section'])
            ->and($payload['attachments'][0]['color'])->toBe('#ECB22E')
            ->and(SlackFormatter::color(Severity::Info))->toBe('#36C5F0');
    });

    it('respects Block Kit limits', function (): void {
        $fields = [];
        for ($i = 1; $i <= 12; $i++) {
            $fields['Field '.$i] = str_repeat('v', 2500);
        }

        $payload = (new SlackFormatter)->format(notifFormatterMessage([
            'title' => str_repeat('T', 300), 'summary' => str_repeat('s', 5000), 'fields' => $fields, 'occurrences' => 340,
        ]));
        $blocks = $payload['attachments'][0]['blocks'];

        expect(mb_strlen($blocks[0]['text']['text']))->toBe(150)
            ->and(mb_strlen($blocks[1]['text']['text']))->toBe(3000)
            ->and($blocks[2]['fields'])->toHaveCount(10)
            ->and($blocks[3]['fields'])->toHaveCount(2)
            ->and(mb_strlen($blocks[2]['fields'][0]['text']))->toBe(2000)
            ->and(collect($blocks)->firstWhere('type', 'context')['elements'][0]['text'])->toContain('*340*');
    });
});

describe('telegram', function (): void {
    it('formats HTML with every value escaped', function (): void {
        $payload = (new TelegramFormatter)->format(notifFormatterMessage([
            'fields' => ['<b>Label</b>' => 'a < b && c > "d"'],
        ]));

        expect($payload['parse_mode'])->toBe('HTML')
            ->and($payload['disable_web_page_preview'])->toBeTrue()
            ->and($payload['text'])
            ->toStartWith('🚨 <b>Slow query on &lt;orders&gt; &amp; co</b>')
            ->toContain('<b>&lt;b&gt;Label&lt;/b&gt;:</b> a &lt; b &amp;&amp; c &gt; &quot;d&quot;')
            ->toContain('<pre><code class="language-sql">select * from `orders` where `user_id` = ? and total &gt; ?</code></pre>')
            ->toContain('<b>💡 AI recommendation</b>')
            ->toContain('<i>Sherlock · Shop (production)</i>')
            ->toEndWith('<a href="https://example.test/sherlock/issues/7">Open in dashboard</a>')
            ->not->toContain('<orders>');
    });

    it('truncates to the 4096 character limit without breaking tags or entities and keeps the link', function (): void {
        $payload = (new TelegramFormatter)->format(notifFormatterMessage([
            'sql' => str_repeat('select <x> & ', 600),
            'aiRecommendation' => str_repeat('y', 3000),
        ]));
        $text = $payload['text'];

        expect(mb_strlen($text))->toBeLessThanOrEqual(TelegramFormatter::LIMIT)
            ->and($text)->toContain('…</code></pre>')
            ->and($text)->not->toContain('AI recommendation')
            ->and($text)->toEndWith('Open in dashboard</a>')
            ->and(substr_count($text, '<pre>'))->toBe(substr_count($text, '</pre>'))
            ->and(preg_match('/&[a-z]*…/', $text))->toBe(0);
    });

    it('escapes within a budget on character boundaries', function (): void {
        expect(TelegramFormatter::escapeWithin('a&b', 10))->toBe('a&amp;b')
            ->and(TelegramFormatter::escapeWithin('&&&&', 7))->toBe('&amp;…')
            ->and(TelegramFormatter::escapeWithin('ééééé', 3))->toBe('éé…')
            ->and(TelegramFormatter::escape("bad \xB1 utf8 <"))->toContain('&lt;');
    });
});

describe('mail', function (): void {
    it('builds the subject and view data', function (): void {
        $formatter = new MailFormatter;
        $message = notifFormatterMessage(['title' => "Multi\nline   title"]);

        expect($formatter->subject($message))->toBe('[Sherlock] Critical: Multi line title')
            ->and(mb_strlen($formatter->subject(notifFormatterMessage(['title' => str_repeat('x', 500)]))))->toBe(180)
            ->and($formatter->data($message))->toMatchArray([
                'severity' => 'Critical',
                'emoji' => '🚨',
                'color' => '#E01E5A',
                'summary' => 'Query took 912 ms',
                'sql' => 'select * from `orders` where `user_id` = ? and total > ?',
                'ai' => 'Add an index on orders(user_id).',
                'url' => 'https://example.test/sherlock/issues/7',
                'occurrences' => 1,
            ]);
    });
});

it('truncates long multi-line digests for telegram and slack', function (): void {
    $fields = [];
    for ($i = 1; $i <= 10; $i++) {
        $fields['Section '.$i] = implode("\n", array_fill(0, 10, $i.'. Slow query on <orders> & '.str_repeat('y', 50)));
    }
    $message = notifFormatterMessage(['fields' => $fields, 'sql' => null, 'aiRecommendation' => null, 'fingerprint' => 'sherlock-digest']);

    $text = (new TelegramFormatter)->text($message);
    $slack = (new SlackFormatter)->format($message);

    expect(mb_strlen($text))->toBeLessThanOrEqual(TelegramFormatter::LIMIT)
        ->and($text)->toContain('…')
        ->and($text)->toEndWith('Open in dashboard</a>')
        ->and(substr_count($text, '<b>'))->toBe(substr_count($text, '</b>'))
        ->and(collect($slack['attachments'][0]['blocks'][2]['fields'])->every(fn (array $f): bool => mb_strlen($f['text']) <= 2000))->toBeTrue();
});

describe('digest and test messages', function (): void {
    it('never label a digest or a test message with its routing severity', function (string $kind, string $title, string $subject): void {
        $message = notifFormatterMessage(['title' => $title, 'kind' => $kind, 'severity' => Severity::Critical]);

        $slack = (new SlackFormatter)->format($message);
        $telegram = (new TelegramFormatter)->text($message);
        $mail = new MailFormatter;

        expect($mail->subject($message))->toBe($subject)
            ->and($mail->data($message)['severity'])->toBe('')
            ->and($mail->data($message)['emoji'])->not->toBe('🚨')
            ->and($mail->data($message)['color'])->not->toBe('#E01E5A')
            ->and($slack['text'])->toBe($title)
            ->and($slack['attachments'][0]['blocks'][0]['text']['text'])->not->toContain('🚨')
            ->and($slack['attachments'][0]['color'])->not->toBe('#E01E5A')
            ->and($telegram)->not->toContain('🚨')->not->toContain('Critical');
    })->with([
        'digest' => [NotificationMessage::KIND_DIGEST, 'Digest: Shop', '[Sherlock] Digest: Shop'],
        'test' => [NotificationMessage::KIND_TEST, 'Test notification', '[Sherlock] Test notification'],
    ]);
});
