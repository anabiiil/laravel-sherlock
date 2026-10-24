<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai\Drivers;

use Anabil\Tracing\Ai\AiProviderException;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

/**
 * Anthropic Messages API: POST {base}/messages. Sampling parameters (temperature...) are
 * never sent: current Claude models reject them.
 */
final class AnthropicDriver extends AbstractHttpDriver
{
    private const DEFAULT_VERSION = '2023-06-01';

    public function complete(AiRequest $request): AiResponse
    {
        $model = $this->model($request);

        $data = $this->post($this->baseUrl().'/messages', [
            'model' => $model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
        ], [
            'x-api-key' => $this->requireApiKey(),
            'anthropic-version' => $this->string('version') ?: self::DEFAULT_VERSION,
        ], $request);

        $stopReason = is_string($data['stop_reason'] ?? null) ? $data['stop_reason'] : null;

        if ($stopReason === 'refusal') {
            throw AiProviderException::invalidResponse($this->name, 'the model declined the request.');
        }

        $text = '';
        foreach (is_array($data['content'] ?? null) ? $data['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new AiResponse(
            $this->nonEmpty($text),
            is_string($data['model'] ?? null) ? $data['model'] : $model,
            self::int($usage['input_tokens'] ?? null),
            self::int($usage['output_tokens'] ?? null),
            $stopReason,
        );
    }
}
