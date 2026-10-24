<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Digest;

use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Data\AiRequest;
use Anabil\Sherlock\Data\AiResponse;
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
