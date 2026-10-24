<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Contracts;

use Closure;

/**
 * Driver manager (AiManager). Returned providers are wrapped by RedactingAiProvider.
 */
interface AiProviderFactory
{
    public function driver(?string $name = null): AiProvider;

    /**
     * @param  Closure(\Illuminate\Contracts\Foundation\Application, array<string, mixed>): AiProvider  $factory
     */
    public function extend(string $driver, Closure $factory): void;
}
