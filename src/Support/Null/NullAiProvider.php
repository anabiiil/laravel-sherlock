<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

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
