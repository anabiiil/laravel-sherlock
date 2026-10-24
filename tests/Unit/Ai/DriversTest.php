<?php

declare(strict_types=1);

use Anabil\Tracing\Ai\AiProviderException;
use Anabil\Tracing\Ai\Drivers\AnthropicDriver;
use Anabil\Tracing\Ai\Drivers\GeminiDriver;
use Anabil\Tracing\Ai\Drivers\NullDriver;
use Anabil\Tracing\Ai\Drivers\OllamaDriver;
use Anabil\Tracing\Ai\Drivers\OpenAiCompatibleDriver;
use Anabil\Tracing\Data\AiRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

function aiRequest(?float $temperature = null): AiRequest
{
    return new AiRequest('SYSTEM PROMPT', 'USER PROMPT', 800, null, 20, $temperature, ['issue_id' => 1]);
}

function aiOpenAiBody(string $content = 'Answer', string $model = 'deepseek-chat'): array
{
    return [
        'model' => $model,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22],
    ];
}

beforeEach(function (): void {
    $this->http = new Factory;
});

describe('anthropic', function (): void {
    beforeEach(function (): void {
        $this->driver = new AnthropicDriver('anthropic', [
            'base_url' => 'https://api.anthropic.com/v1/',
            'model' => 'claude-sonnet-5-5',
            'version' => '2023-06-01',
            'api_key' => 'sk-ant-secret',
        ], $this->http);
    });

    it('sends a Messages API request without sampling parameters', function (): void {
        $this->http->fake(['api.anthropic.com/*' => $this->http->response([
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => '## Root cause'], ['type' => 'text', 'text' => "\nX"]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'stop_reason' => 'end_turn',
        ])]);

        $response = $this->driver->complete(aiRequest(temperature: 0.2));

        $this->http->assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->method() === 'POST'
                && $request->header('x-api-key') === ['sk-ant-secret']
                && $request->header('anthropic-version') === ['2023-06-01']
                && $data['model'] === 'claude-sonnet-5-5'
                && $data['max_tokens'] === 800
                && $data['system'] === 'SYSTEM PROMPT'
                && $data['messages'] === [['role' => 'user', 'content' => 'USER PROMPT']]
                && ! array_key_exists('temperature', $data)
                && ! array_key_exists('top_p', $data);
        });

        expect($response->content)->toBe("## Root cause\nX")
            ->and($response->model)->toBe('claude-sonnet-5-5')
            ->and($response->inputTokens)->toBe(100)
            ->and($response->outputTokens)->toBe(50)
            ->and($response->finishReason)->toBe('end_turn');
    });

    it('treats a refusal as an error', function (): void {
        $this->http->fake(['*' => $this->http->response(['content' => [], 'stop_reason' => 'refusal'])]);

        $this->driver->complete(aiRequest());
    })->throws(AiProviderException::class, 'declined');

    it('requires an api key', function (): void {
        $driver = new AnthropicDriver('anthropic', ['base_url' => 'https://api.anthropic.com/v1', 'model' => 'm'], $this->http);
        $this->http->fake();

        $driver->complete(aiRequest());
    })->throws(AiProviderException::class, 'no API key');
});

describe('openai compatible', function (): void {
    it('sends chat completions with a bearer key and max_tokens', function (): void {
        $driver = new OpenAiCompatibleDriver('deepseek', ['base_url' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'api_key' => 'ds-key'], $this->http);
        $this->http->fake(['api.deepseek.com/*' => $this->http->response(aiOpenAiBody('## Fix'))]);

        $response = $driver->complete(aiRequest());

        $this->http->assertSent(fn (Request $r): bool => $r->url() === 'https://api.deepseek.com/v1/chat/completions'
            && $r->header('Authorization') === ['Bearer ds-key']
            && $r->data()['model'] === 'deepseek-chat'
            && $r->data()['max_tokens'] === 800
            && $r->data()['messages'] === [
                ['role' => 'system', 'content' => 'SYSTEM PROMPT'],
                ['role' => 'user', 'content' => 'USER PROMPT'],
            ]
            && ! array_key_exists('temperature', $r->data()));

        expect($response->content)->toBe('## Fix')
            ->and($response->model)->toBe('deepseek-chat')
            ->and($response->inputTokens)->toBe(11)
            ->and($response->outputTokens)->toBe(22)
            ->and($response->finishReason)->toBe('stop');
    });

    it('sends temperature only when requested and supports max_completion_tokens', function (): void {
        $driver = new OpenAiCompatibleDriver('openai', ['base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-x', 'api_key' => 'k', 'max_tokens_parameter' => 'max_completion_tokens'], $this->http);
        $this->http->fake(['*' => $this->http->response(aiOpenAiBody())]);

        $driver->complete(aiRequest(temperature: 0.1));

        $this->http->assertSent(fn (Request $r): bool => $r->data()['temperature'] === 0.1
            && $r->data()['max_completion_tokens'] === 800
            && ! array_key_exists('max_tokens', $r->data()));
    });

    it('adds the openrouter attribution headers', function (): void {
        $driver = new OpenAiCompatibleDriver('openrouter', ['base_url' => 'https://openrouter.ai/api/v1', 'model' => 'm', 'api_key' => 'k', 'referer' => 'https://example.test', 'title' => 'Laravel Tracing'], $this->http);
        $this->http->fake(['*' => $this->http->response(aiOpenAiBody())]);

        $driver->complete(aiRequest());

        $this->http->assertSent(fn (Request $r): bool => $r->header('HTTP-Referer') === ['https://example.test']
            && $r->header('X-Title') === ['Laravel Tracing']);
    });

    it('sends no Authorization header without a key (local servers)', function (): void {
        $driver = new OpenAiCompatibleDriver('openai_compatible', ['base_url' => 'http://localhost:8000/v1', 'model' => 'local'], $this->http);
        $this->http->fake(['*' => $this->http->response(aiOpenAiBody())]);

        $driver->complete(aiRequest());

        $this->http->assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:8000/v1/chat/completions' && ! $r->hasHeader('Authorization'));
    });

    it('fails clearly without a base url or model', function (array $config, string $message): void {
        $this->http->fake();

        expect(fn () => (new OpenAiCompatibleDriver('openai_compatible', $config, $this->http))->complete(aiRequest()))
            ->toThrow(AiProviderException::class, $message);
    })->with([
        'no base url' => [['model' => 'm'], 'no base URL'],
        'no model' => [['base_url' => 'http://localhost/v1'], 'no model'],
    ]);

    it('rejects an empty completion', function (): void {
        $driver = new OpenAiCompatibleDriver('groq', ['base_url' => 'https://api.groq.com/openai/v1', 'model' => 'm', 'api_key' => 'k'], $this->http);
        $this->http->fake(['*' => $this->http->response(aiOpenAiBody(''))]);

        $driver->complete(aiRequest());
    })->throws(AiProviderException::class, 'empty completion');
});

describe('gemini', function (): void {
    it('sends generateContent with the key in a header, never in the query string', function (): void {
        $driver = new GeminiDriver('gemini', ['base_url' => 'https://generativelanguage.googleapis.com/v1beta', 'model' => 'gemini-2.0-flash', 'api_key' => 'g-key'], $this->http);
        $this->http->fake(['generativelanguage.googleapis.com/*' => $this->http->response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'thinking...', 'thought' => true], ['text' => '## Root cause']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 7, 'candidatesTokenCount' => 9],
            'modelVersion' => 'gemini-2.0-flash-001',
        ])]);

        $response = $driver->complete(aiRequest());

        $this->http->assertSent(fn (Request $r): bool => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'
            && ! str_contains($r->url(), 'key=')
            && ! str_contains($r->url(), 'g-key')
            && $r->header('x-goog-api-key') === ['g-key']
            && $r->data()['systemInstruction'] === ['parts' => [['text' => 'SYSTEM PROMPT']]]
            && $r->data()['contents'] === [['role' => 'user', 'parts' => [['text' => 'USER PROMPT']]]]
            && $r->data()['generationConfig'] === ['maxOutputTokens' => 800]);

        expect($response->content)->toBe('## Root cause')
            ->and($response->model)->toBe('gemini-2.0-flash-001')
            ->and($response->inputTokens)->toBe(7)
            ->and($response->outputTokens)->toBe(9)
            ->and($response->finishReason)->toBe('STOP');
    });
});

describe('ollama', function (): void {
    it('posts to /api/chat without streaming', function (): void {
        $driver = new OllamaDriver('ollama', ['base_url' => 'http://localhost:11434', 'model' => 'qwen2.5-coder'], $this->http);
        $this->http->fake(['localhost:11434/*' => $this->http->response([
            'model' => 'qwen2.5-coder',
            'message' => ['role' => 'assistant', 'content' => '## Fix'],
            'done' => true,
            'done_reason' => 'stop',
            'prompt_eval_count' => 3,
            'eval_count' => 4,
        ])]);

        $response = $driver->complete(aiRequest());

        $this->http->assertSent(fn (Request $r): bool => $r->url() === 'http://localhost:11434/api/chat'
            && $r->data()['stream'] === false
            && $r->data()['model'] === 'qwen2.5-coder'
            && $r->data()['options'] === ['num_predict' => 800]
            && count($r->data()['messages']) === 2
            && ! $r->hasHeader('Authorization'));

        expect($response->content)->toBe('## Fix')
            ->and($response->inputTokens)->toBe(3)
            ->and($response->outputTokens)->toBe(4);
    });
});

describe('errors', function (): void {
    it('maps HTTP errors to a short message without the api key', function (): void {
        $driver = new OpenAiCompatibleDriver('openai', ['base_url' => 'https://api.openai.com/v1', 'model' => 'm', 'api_key' => 'sk-live-very-secret'], $this->http);
        $this->http->fake(['*' => $this->http->response(['error' => ['message' => 'Incorrect API key provided: sk-live-very-secret.']], 401)]);

        try {
            $driver->complete(aiRequest());
            $this->fail('Expected an exception');
        } catch (AiProviderException $e) {
            expect($e->getMessage())->toContain('HTTP 401')
                ->toContain('Incorrect API key')
                ->not->toContain('sk-live-very-secret');
        }
    });

    it('maps connection failures and timeouts to "unreachable"', function (): void {
        $driver = new AnthropicDriver('anthropic', ['base_url' => 'https://api.anthropic.com/v1', 'model' => 'm', 'api_key' => 'sk-ant-secret'], $this->http);
        $this->http->fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out (x-api-key: sk-ant-secret)'));

        try {
            $driver->complete(aiRequest());
            $this->fail('Expected an exception');
        } catch (AiProviderException $e) {
            expect($e->getMessage())->toContain('unreachable or timed out')->not->toContain('sk-ant-secret');
        }
    });

    it('rejects a non JSON body', function (): void {
        $driver = new OllamaDriver('ollama', ['base_url' => 'http://localhost:11434', 'model' => 'm'], $this->http);
        $this->http->fake(['*' => $this->http->response('<html>bad gateway</html>', 200)]);

        $driver->complete(aiRequest());
    })->throws(AiProviderException::class, 'not JSON');
});

it('has a null driver that never calls anything', function (): void {
    expect((new NullDriver)->complete(aiRequest())->content)->toBe('')
        ->and((new NullDriver)->name())->toBe('null');
});
