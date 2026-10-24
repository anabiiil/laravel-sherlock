<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\AiProvider;
use Anabil\Tracing\Contracts\AiProviderFactory;
use Closure;

final class NullAiProviderFactory implements AiProviderFactory
{
    public function driver(?string $name = null): AiProvider
    {
        return new NullAiProvider;
    }

    public function extend(string $driver, Closure $factory): void {}
}
