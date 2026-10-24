<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Data;

final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $finishReason = null,
    ) {}
}
