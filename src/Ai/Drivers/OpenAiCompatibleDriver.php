<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai\Drivers;

/**
 * One class for every OpenAI-compatible preset: openai, deepseek, qwen, groq, openrouter,
 * mistral and openai_compatible (any self-hosted / proxy endpoint: vLLM, LM Studio, LiteLLM...).
 * Presets differ by config only. A key is optional (local servers often need none).
 */
final class OpenAiCompatibleDriver extends AbstractOpenAiCompatibleDriver
{
    protected function extraHeaders(): array
    {
        if ($this->name !== 'openrouter') {
            return [];
        }

        return array_filter([
            'HTTP-Referer' => $this->string('referer') ?? '',
            'X-Title' => $this->string('title') ?? '',
        ], static fn (string $value): bool => $value !== '');
    }
}
