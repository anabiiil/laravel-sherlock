<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Data\AiRequest;
use Anabil\Sherlock\Data\AiResponse;

final class NullAiProvider implements AiProvider
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
