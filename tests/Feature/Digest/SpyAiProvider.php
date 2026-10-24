<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Digest;

use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;
use RuntimeException;

final class SpyAiProvider implements AiProvider
{
    public int $calls = 0;

    public function name(): string
    {
        return 'spy';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->calls++;

        throw new RuntimeException('The digest must never call the AI.');
    }
}
