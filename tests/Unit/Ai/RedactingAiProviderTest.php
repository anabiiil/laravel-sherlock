<?php

declare(strict_types=1);

use Anabil\Tracing\Ai\RedactingAiProvider;
use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;
use Anabil\Tracing\Privacy\Redactor;

function aiSpyProvider(): AiProvider
{
    return new class implements AiProvider
    {
        public ?AiRequest $last = null;

        public function name(): string
        {
            return 'spy';
        }

        public function complete(AiRequest $request): AiResponse
        {
            $this->last = $request;

            return new AiResponse('ok', 'spy-model');
        }
    };
}

it('redacts both prompts before delegating', function (): void {
    $inner = aiSpyProvider();
    $provider = new RedactingAiProvider($inner, Redactor::make());

    $response = $provider->complete(new AiRequest(
        'System for admin@example.com',
        "User john.doe@example.com\npassword=hunter2\nAuthorization: Bearer abcdef1234567890\n\$2y\$10\$abcdefghijklmnopqrstuuJ5y5k5Y5k5Y5k5Y5k5Y5k5Y5k5Y5k5Y",
        900,
        'model-x',
        12,
    ));

    $sent = $inner->last;

    expect($response->content)->toBe('ok')
        ->and($provider->name())->toBe('spy')
        ->and($sent->systemPrompt)->not->toContain('admin@example.com')
        ->and($sent->userPrompt)->not->toContain('john.doe@example.com')
        ->not->toContain('hunter2')
        ->not->toContain('abcdef1234567890')
        ->not->toContain('$2y$10$')
        ->toContain('[REDACTED]')
        // Everything else is untouched.
        ->and($sent->maxTokens)->toBe(900)
        ->and($sent->model)->toBe('model-x')
        ->and($sent->timeout)->toBe(12);
});

it('never truncates long prompts and keeps line structure', function (): void {
    $inner = aiSpyProvider();
    $provider = new RedactingAiProvider($inner, Redactor::make());

    $lines = array_map(static fn (int $i): string => "line {$i} select * from orders where user_id = ?", range(1, 2000));
    $lines[1500] = 'leak me@example.com here';
    $prompt = "\n".implode("\n", $lines)."\n";

    $provider->complete(new AiRequest('s', $prompt));

    $sent = $inner->last->userPrompt;

    expect(strlen($prompt))->toBeGreaterThan(Redactor::MAX_STRING_LENGTH)
        ->and($sent)->not->toContain('truncated')
        ->not->toContain('me@example.com')
        ->toContain('line 2000 select')
        ->and(substr_count($sent, "\n"))->toBe(substr_count($prompt, "\n"));
});
