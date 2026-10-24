<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai\Drivers;

use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

/**
 * Template Method for OpenAI-style Chat Completions APIs (POST {base}/chat/completions,
 * Bearer key). Subclasses customize headers / body through the hooks only.
 */
abstract class AbstractOpenAiCompatibleDriver extends AbstractHttpDriver
{
    final public function complete(AiRequest $request): AiResponse
    {
        $model = $this->model($request);

        $data = $this->post($this->endpoint(), $this->payload($request, $model), $this->headers(), $request);

        $choice = is_array($data['choices'] ?? null) && is_array($data['choices'][0] ?? null) ? $data['choices'][0] : [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AiResponse(
            $this->nonEmpty(is_string($message['content'] ?? null) ? $message['content'] : ''),
            is_string($data['model'] ?? null) && $data['model'] !== '' ? $data['model'] : $model,
            self::int($usage['prompt_tokens'] ?? null),
            self::int($usage['completion_tokens'] ?? null),
            is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
        );
    }

    protected function endpoint(): string
    {
        return $this->baseUrl().'/chat/completions';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiRequest $request, string $model): array
    {
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            $this->maxTokensParameter() => $request->maxTokens,
        ];

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        return $body + $this->extraBody($request);
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        $key = $this->apiKey();

        return ($key === null ? [] : ['Authorization' => 'Bearer '.$key]) + $this->extraHeaders();
    }

    /** "max_tokens" by default; some newer models want "max_completion_tokens" (drivers.*.max_tokens_parameter). */
    protected function maxTokensParameter(): string
    {
        return $this->string('max_tokens_parameter') ?: 'max_tokens';
    }

    /**
     * @return array<string, string>
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraBody(AiRequest $request): array
    {
        return [];
    }
}
