<?php

declare(strict_types=1);

namespace Anabil\Tracing\Ai\Drivers;

use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

/**
 * driver = "null": never calls anything. Its empty completion is recorded as a failed
 * analysis, so it is only useful to wire things up without a provider.
 */
final class NullDriver implements AiProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function complete(AiRequest $request): AiResponse
    {
        return new AiResponse('', 'null');
    }
}
