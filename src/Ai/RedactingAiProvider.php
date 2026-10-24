<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai;

use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Contracts\Redactor;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

/**
 * Decorator applied by AiManager to every provider (built-in and custom): system and user
 * prompts pass the Redactor again right before leaving the app (defense in depth, PLAN §9).
 * Prompts are redacted in line-aligned chunks so the Redactor's string cap never truncates them.
 */
final class RedactingAiProvider implements AiProvider
{
    private const CHUNK_BYTES = 8000;

    public function __construct(
        private readonly AiProvider $inner,
        private readonly Redactor $redactor,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function complete(AiRequest $request): AiResponse
    {
        return $this->inner->complete($request->withPrompts(
            $this->redact($request->systemPrompt),
            $this->redact($request->userPrompt),
        ));
    }

    public function inner(): AiProvider
    {
        return $this->inner;
    }

    private function redact(string $text): string
    {
        $out = [];
        $lines = [];
        $size = 0;

        foreach (explode("\n", $text) as $line) {
            if ($lines !== [] && $size + strlen($line) + 1 > self::CHUNK_BYTES) {
                $out[] = $this->redactor->redactString(implode("\n", $lines));
                $lines = [];
                $size = 0;
            }

            $lines[] = $line;
            $size += strlen($line) + 1;
        }

        $out[] = $this->redactor->redactString(implode("\n", $lines));

        return implode("\n", $out);
    }
}
