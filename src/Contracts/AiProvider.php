<?php

declare(strict_types=1);

namespace Anabil\Tracing\Contracts;

use Anabil\Tracing\Data\AiRequest;
use Anabil\Tracing\Data\AiResponse;

interface AiProvider
{
    public function name(): string;

    /** May throw on transport / API errors; callers handle it. */
    public function complete(AiRequest $request): AiResponse;
}
