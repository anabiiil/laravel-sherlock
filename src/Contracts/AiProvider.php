<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Anabil\Sherlock\Data\AiRequest;
use Anabil\Sherlock\Data\AiResponse;

interface AiProvider
{
    public function name(): string;

    /** May throw on transport / API errors; callers handle it. */
    public function complete(AiRequest $request): AiResponse;
}
