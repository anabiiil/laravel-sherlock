<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai;

use Anabil\Tracing\Ai\Drivers\AnthropicDriver;
use Anabil\Tracing\Ai\Drivers\GeminiDriver;
use Anabil\Tracing\Ai\Drivers\NullDriver;
use Anabil\Tracing\Ai\Drivers\OllamaDriver;
use Anabil\Tracing\Ai\Drivers\OpenAiCompatibleDriver;
use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Contracts\AiProviderFactory;
use Anabil\Tracing\Contracts\Redactor;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * AI driver manager (Factory + Strategy). Driver config = preset (tracing.ai.drivers.<name>)
 * <- user overrides: the top-level model / base_url / api_key apply to the selected driver
 * (tracing.ai.driver) only, so resolving another driver explicitly never inherits a model
 * meant for a different provider. Every resolved provider, custom ones included, is wrapped
 * in RedactingAiProvider.
 */
final class AiManager implements AiProviderFactory
{
    /** Built-in drivers; the OpenAI-compatible presets are config for one driver class. */
    public const OPENAI_COMPATIBLE = ['openai', 'deepseek', 'qwen', 'groq', 'openrouter', 'mistral', 'openai_compatible'];

    public const DRIVERS = ['anthropic', 'gemini', 'ollama', 'null', ...self::OPENAI_COMPATIBLE];

    private const OVERRIDABLE = ['model', 'base_url', 'api_key'];

    /** @var array<string, Closure(Application, array<string, mixed>): AiProvider> */
    private array $customCreators = [];

    /** @var array<string, AiProvider> */
    private array $drivers = [];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly Redactor $redactor,
    ) {}

    public function driver(?string $name = null): AiProvider
    {
        $name ??= $this->defaultDriver();

        return $this->drivers[$name] ??= new RedactingAiProvider($this->resolve($name), $this->redactor);
    }

    public function extend(string $driver, Closure $factory): void
    {
        $this->customCreators[$driver] = $factory;
        unset($this->drivers[$driver]);
    }

    public function defaultDriver(): string
    {
        $driver = $this->config->get('tracing.ai.driver');

        return is_string($driver) && trim($driver) !== '' ? trim($driver) : 'anthropic';
    }

    /**
     * Resolved config of a driver (preset <- overrides). Contains the API key: never log it.
     *
     * @return array<string, mixed>
     */
    public function configFor(string $name): array
    {
        $ai = $this->config->get('tracing.ai', []);
        $ai = is_array($ai) ? $ai : [];
        $drivers = is_array($ai['drivers'] ?? null) ? $ai['drivers'] : [];
        $preset = is_array($drivers[$name] ?? null) ? $drivers[$name] : [];

        $config = [];
        foreach ($preset as $key => $value) {
            $config[(string) $key] = $value;
        }

        if ($name === $this->defaultDriver()) {
            foreach (self::OVERRIDABLE as $key) {
                $value = $ai[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $config[$key] = trim($value);
                }
            }
        }

        $config['timeout'] ??= $ai['timeout'] ?? 30;
        $config['connect_timeout'] ??= $ai['connect_timeout'] ?? 10;

        return $config;
    }

    /** Drop resolved instances (after a config change). */
    public function forgetDrivers(): void
    {
        $this->drivers = [];
    }

    private function resolve(string $name): AiProvider
    {
        $config = $this->configFor($name);

        if (isset($this->customCreators[$name])) {
            return ($this->customCreators[$name])($this->app, $config);
        }

        $http = $this->app->make(HttpFactory::class);

        return match (true) {
            $name === 'anthropic' => new AnthropicDriver($name, $config, $http),
            $name === 'gemini' => new GeminiDriver($name, $config, $http),
            $name === 'ollama' => new OllamaDriver($name, $config, $http),
            $name === 'null' => new NullDriver,
            in_array($name, self::OPENAI_COMPATIBLE, true) => new OpenAiCompatibleDriver($name, $config, $http),
            default => throw new InvalidArgumentException(sprintf(
                'AI driver [%s] is not supported. Use one of: %s, or register it with Tracing::extendAi().',
                $name,
                implode(', ', [...self::DRIVERS, ...array_keys($this->customCreators)]),
            )),
        };
    }
}
