<?php

declare(strict_types=1);

use Anabil\Sherlock\Ai\AiManager;
use Anabil\Sherlock\Ai\Drivers\AnthropicDriver;
use Anabil\Sherlock\Ai\Drivers\GeminiDriver;
use Anabil\Sherlock\Ai\Drivers\NullDriver;
use Anabil\Sherlock\Ai\Drivers\OllamaDriver;
use Anabil\Sherlock\Ai\Drivers\OpenAiCompatibleDriver;
use Anabil\Sherlock\Ai\RedactingAiProvider;
use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Contracts\AiProviderFactory;
use Anabil\Sherlock\Data\AiRequest;
use Anabil\Sherlock\Data\AiResponse;
use Anabil\Sherlock\Facades\Sherlock;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aiManager(): AiManager
{
    $manager = app(AiManager::class);
    $manager->forgetDrivers();

    return $manager;
}

it('binds the manager as the provider factory and wraps the default provider', function (): void {
    config()->set('sherlock.ai.driver', 'deepseek');

    expect(app(AiProviderFactory::class))->toBe(app(AiManager::class))
        ->and(app(AiProvider::class))->toBeInstanceOf(RedactingAiProvider::class)
        ->and(app(AiProvider::class)->inner())->toBeInstanceOf(OpenAiCompatibleDriver::class)
        ->and(app(AiProvider::class)->name())->toBe('deepseek');
});

it('resolves every built-in driver, wrapped by redaction', function (string $name, string $class): void {
    $provider = aiManager()->driver($name);

    expect($provider)->toBeInstanceOf(RedactingAiProvider::class)
        ->and($provider->inner())->toBeInstanceOf($class)
        ->and($provider->name())->toBe($name);
})->with([
    ['anthropic', AnthropicDriver::class],
    ['openai', OpenAiCompatibleDriver::class],
    ['gemini', GeminiDriver::class],
    ['deepseek', OpenAiCompatibleDriver::class],
    ['qwen', OpenAiCompatibleDriver::class],
    ['groq', OpenAiCompatibleDriver::class],
    ['openrouter', OpenAiCompatibleDriver::class],
    ['mistral', OpenAiCompatibleDriver::class],
    ['ollama', OllamaDriver::class],
    ['openai_compatible', OpenAiCompatibleDriver::class],
    ['null', NullDriver::class],
]);

it('resolves preset base urls and models', function (string $name, ?string $baseUrl, ?string $model): void {
    config()->set('sherlock.ai.driver', 'something-else');

    $config = aiManager()->configFor($name);

    expect($config['base_url'])->toBe($baseUrl)
        ->and($config['model'])->toBe($model)
        ->and($config['timeout'])->toBe(30);
})->with([
    ['anthropic', 'https://api.anthropic.com/v1', 'claude-sonnet-5-5'],
    ['openai', 'https://api.openai.com/v1', 'gpt-4o-mini'],
    ['gemini', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-2.0-flash'],
    ['deepseek', 'https://api.deepseek.com/v1', 'deepseek-chat'],
    ['qwen', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1', 'qwen-plus'],
    ['groq', 'https://api.groq.com/openai/v1', 'llama-3.3-70b-versatile'],
    ['openrouter', 'https://openrouter.ai/api/v1', 'meta-llama/llama-3.3-70b-instruct:free'],
    ['mistral', 'https://api.mistral.ai/v1', 'mistral-small-latest'],
    ['ollama', 'http://localhost:11434', 'qwen2.5-coder'],
    ['openai_compatible', null, null],
]);

it('lets the user override model, base url and key of the selected driver only', function (): void {
    config()->set('sherlock.ai.driver', 'qwen');
    config()->set('sherlock.ai.model', 'qwen-max');
    config()->set('sherlock.ai.base_url', 'https://dashscope.aliyuncs.com/compatible-mode/v1');
    config()->set('sherlock.ai.api_key', 'qwen-key');

    $qwen = aiManager()->configFor('qwen');
    $anthropic = aiManager()->configFor('anthropic');

    expect($qwen['model'])->toBe('qwen-max')
        ->and($qwen['base_url'])->toBe('https://dashscope.aliyuncs.com/compatible-mode/v1')
        ->and($qwen['api_key'])->toBe('qwen-key')
        ->and($anthropic['model'])->toBe('claude-sonnet-5-5')
        ->and($anthropic)->not->toHaveKey('api_key');
});

it('accepts per-driver overrides in the preset', function (): void {
    config()->set('sherlock.ai.drivers.ollama.model', 'llama3.2');

    expect(aiManager()->configFor('ollama')['model'])->toBe('llama3.2');
});

it('sends the request to the overridden endpoint and model', function (): void {
    config()->set('sherlock.ai.driver', 'openai_compatible');
    config()->set('sherlock.ai.base_url', 'http://vllm.test/v1');
    config()->set('sherlock.ai.model', 'my-local-model');
    Http::fake(['vllm.test/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

    aiManager()->driver()->complete(new AiRequest('s', 'u'));

    Http::assertSent(fn (Request $r): bool => $r->url() === 'http://vllm.test/v1/chat/completions' && $r->data()['model'] === 'my-local-model');
});

it('fails clearly on an unknown driver', function (): void {
    aiManager()->driver('watsonx');
})->throws(InvalidArgumentException::class, 'AI driver [watsonx] is not supported');

it('wraps custom drivers registered with Sherlock::extendAi in redaction', function (): void {
    config()->set('sherlock.ai.drivers.custom', ['model' => 'custom-1', 'endpoint' => 'x']);
    $captured = new ArrayObject;

    Sherlock::extendAi('custom', function ($app, array $config) use ($captured): AiProvider {
        $captured['config'] = $config;

        return new class($captured) implements AiProvider
        {
            public function __construct(private ArrayObject $captured) {}

            public function name(): string
            {
                return 'custom';
            }

            public function complete(AiRequest $request): AiResponse
            {
                $this->captured['request'] = $request;

                return new AiResponse('fine', 'custom-1');
            }
        };
    });

    $provider = aiManager()->driver('custom');
    $provider->complete(new AiRequest('system for root@example.com', 'token=abc123secret and mail ops@example.com'));

    expect($provider)->toBeInstanceOf(RedactingAiProvider::class)
        ->and($captured['config']['model'])->toBe('custom-1')
        ->and($captured['config']['endpoint'])->toBe('x')
        ->and($captured['request']->systemPrompt)->not->toContain('root@example.com')
        ->and($captured['request']->userPrompt)->not->toContain('abc123secret')
        ->not->toContain('ops@example.com');
});

it('can make a custom driver the default', function (): void {
    config()->set('sherlock.ai.driver', 'mine');
    aiManager()->extend('mine', fn () => new NullDriver);

    expect(app(AiProvider::class))->toBeInstanceOf(RedactingAiProvider::class)
        ->and(app(AiProvider::class)->inner())->toBeInstanceOf(NullDriver::class);
});
