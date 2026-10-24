<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai\Drivers;

use Anabil\Sherlock\Data\AiRequest;
use Anabil\Sherlock\Data\AiResponse;

/**
 * Local models via Ollama: POST {base}/api/chat with stream=false. No key needed; one is
 * sent as a Bearer token when configured (e.g. Ollama behind an authenticating proxy).
 */
final class OllamaDriver extends AbstractHttpDriver
{
    public function complete(AiRequest $request): AiResponse
    {
        $model = $this->model($request);

        $options = ['num_predict' => $request->maxTokens];
        if ($request->temperature !== null) {
            $options['temperature'] = $request->temperature;
        }

        $key = $this->apiKey();

        $data = $this->post($this->baseUrl().'/api/chat', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            'stream' => false,
            'options' => $options,
        ], $key === null ? [] : ['Authorization' => 'Bearer '.$key], $request);

        $message = is_array($data['message'] ?? null) ? $data['message'] : [];

        return new AiResponse(
            $this->nonEmpty(is_string($message['content'] ?? null) ? $message['content'] : ''),
            is_string($data['model'] ?? null) ? $data['model'] : $model,
            self::int($data['prompt_eval_count'] ?? null),
            self::int($data['eval_count'] ?? null),
            is_string($data['done_reason'] ?? null) ? $data['done_reason'] : null,
        );
    }
}
