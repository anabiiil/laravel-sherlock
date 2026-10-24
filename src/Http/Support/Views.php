<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Support;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;

/**
 * Renders the dashboard views ("sherlock::dashboard.*", namespace registered by
 * DashboardServiceProvider; publishable / overridable like any package view).
 */
final class Views
{
    public function __construct(
        private readonly Factory $factory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function make(string $view, array $data = []): View
    {
        if (! $this->factory->exists($view)) {
            throw new InvalidArgumentException("Dashboard view [{$view}] not found.");
        }

        return $this->factory->make($view, $data);
    }
}
