<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\AiProvider;
use Anabil\Sherlock\Contracts\AiProviderFactory;
use Closure;

final class NullAiProviderFactory implements AiProviderFactory
{
    public function driver(?string $name = null): AiProvider
    {
        return new NullAiProvider;
    }

    public function extend(string $driver, Closure $factory): void {}
}
