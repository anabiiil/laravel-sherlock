<?php

declare(strict_types=1);

use Anabil\Sherlock\Enums\IssueType;
use Anabil\Sherlock\Enums\Severity;
use Anabil\Sherlock\Tests\Feature\Dashboard\DashboardFixtures as D;

beforeEach(fn () => D::allowAccess());

const XSS = '<script>alert("xss")</script>';

it('escapes issue titles, SQL, context and AI analysis on the issue page', function (): void {
    $issue = D::issueWith('fp-xss', 'Slow '.XSS, IssueType::SlowQuery, Severity::Critical, [
        'sql' => 'select * from users where name = \'</code>'.XSS.'\'',
        'description' => XSS,
        'recommendation' => '<img src=x onerror=alert(1)>',
        'location' => ['file' => 'app/'.XSS.'.php', 'line' => 1],
        'metrics' => ['note' => XSS],
        'explain' => ['insights' => [['severity' => 'warning', 'message' => XSS, 'suggested_index' => 'users('.XSS.')']]],
        'exception' => ['class' => XSS, 'message' => XSS, 'frames' => [['file' => XSS, 'line' => 1]]],
        'http_calls' => [['method' => 'GET', 'url' => 'https://x.test/'.XSS, 'status_code' => 200, 'duration_ms' => 1.0]],
    ]);
    D::analyze($issue, "## Fix\n\n".XSS."\n\n<iframe src=\"https://evil.test\"></iframe>\n\n[click](javascript:alert(1))");

    $response = $this->get('/sherlock/issues/'.$issue->id)->assertOk();
    $html = $response->getContent();

    expect($html)->not->toContain('<script>alert')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<iframe')
        ->and($html)->not->toContain('javascript:alert')
        ->and($html)->not->toContain('</code><script>')
        ->and($html)->toContain('&lt;script&gt;alert(')
        ->and($html)->toContain('<h2>Fix</h2>');

    $this->get('/sherlock/issues')->assertOk()->assertDontSee('<script>alert', false)->assertSee(XSS);
});

it('escapes trace names, SQL, logs, tags and the query string in list pages', function (): void {
    D::trace('GET /'.XSS);

    $this->get('/sherlock/traces?name='.urlencode(XSS).'&search='.urlencode(XSS))->assertOk()->assertDontSee('<script>alert', false);
    $this->get('/sherlock/traces')->assertOk()->assertDontSee('<script>alert', false)->assertSee('GET /'.XSS);
    $this->get('/sherlock/issues?search='.urlencode('"><script>alert(1)</script>'))->assertOk()->assertDontSee('<script>alert', false);
    $this->get('/sherlock')->assertOk()->assertDontSee('<script>alert', false);
});
