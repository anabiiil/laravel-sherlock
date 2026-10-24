<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai\Drivers;

use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

/**
 * Google Gemini: POST {base}/models/{model}:generateContent. The key travels in the
 * x-goog-api-key header, never in the query string (URLs end up in logs).
 */
final class GeminiDriver extends AbstractHttpDriver
{
    public function complete(AiRequest $request): AiResponse
    {
        $model = $this->model($request);

        $generation = ['maxOutputTokens' => $request->maxTokens];
        if ($request->temperature !== null) {
            $generation['temperature'] = $request->temperature;
        }

        $data = $this->post($this->baseUrl().'/models/'.rawurlencode($model).':generateContent', [
            'systemInstruction' => ['parts' => [['text' => $request->systemPrompt]]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $request->userPrompt]]],
            ],
            'generationConfig' => $generation,
        ], [
            'x-goog-api-key' => $this->requireApiKey(),
        ], $request);

        $candidate = is_array($data['candidates'] ?? null) && is_array($data['candidates'][0] ?? null) ? $data['candidates'][0] : [];
        $content = is_array($candidate['content'] ?? null) ? $candidate['content'] : [];

        $text = '';
        foreach (is_array($content['parts'] ?? null) ? $content['parts'] : [] as $part) {
            // Skip "thought" parts of thinking models; keep the answer only.
            if (is_array($part) && is_string($part['text'] ?? null) && ($part['thought'] ?? false) !== true) {
                $text .= $part['text'];
            }
        }

        $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];

        return new AiResponse(
            $this->nonEmpty($text),
            is_string($data['modelVersion'] ?? null) ? $data['modelVersion'] : $model,
            self::int($usage['promptTokenCount'] ?? null),
            self::int($usage['candidatesTokenCount'] ?? null),
            is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : null,
        );
    }
}
