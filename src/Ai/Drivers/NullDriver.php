<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Ai\Drivers;

use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Data\AiRequest;
use Anabil\Sherlock\Data\AiResponse;

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
