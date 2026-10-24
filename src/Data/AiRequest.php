<?php

declare(strict_types=1);

namespace Anabil\Tracing\Data;

/**
 * Provider-agnostic completion request. Prompts contain redacted data only.
 */
final readonly class AiRequest
{
    /**
     * @param  string|null  $model  null = driver default
     * @param  float|null  $temperature  null = do not send (some providers reject sampling params)
     * @param  array<string, scalar|null>  $metadata  e.g. issue fingerprint (never sent to the provider)
     */
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public int $maxTokens = 1500,
        public ?string $model = null,
        public int $timeout = 30,
        public ?float $temperature = null,
        public array $metadata = [],
    ) {}

    public function withPrompts(string $systemPrompt, string $userPrompt): self
    {
        return new self($systemPrompt, $userPrompt, $this->maxTokens, $this->model, $this->timeout, $this->temperature, $this->metadata);
    }
}
