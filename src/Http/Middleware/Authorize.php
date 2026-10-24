<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Guards every dashboard route with the "viewSherlock" gate (403 otherwise).
 */
final class Authorize
{
    public const ABILITY = 'viewSherlock';

    public function __construct(
        private readonly Gate $gate,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->gate->check(self::ABILITY, [$request->user()])) {
            throw new HttpException(403, 'This action is unauthorized.');
        }

        return $next($request);
    }
}
