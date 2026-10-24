<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Integration\Fixtures;

use Anabil\Sherlock\Tests\Feature\Integration\Support\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A small "application" controller. Lines carrying an "@marker" comment are located by
 * the tests (Integration::lineOf()), so the assertions survive edits of this file.
 */
final class IntegrationController
{
    /** Real N+1 (lazy belongsTo inside a loop) + one slow query + one outgoing HTTP call. */
    public function report(): JsonResponse
    {
        $names = [];
        foreach (Post::query()->orderBy('id')->get() as $post) {
            $names[] = $post->author->name; // @n-plus-one
        }

        DB::select('select sleep(?) as slept', [Integration::SLOW_QUERY_SECONDS]); // @slow-query

        Http::withToken('integration-bearer-value')->get('https://api.example.test/v1/rates?token=integration-url-token'); // @http-call

        return new JsonResponse(['authors' => count(array_unique($names))]);
    }

    /** A clean, fast request: one cheap query, nothing to report. */
    public function fast(): JsonResponse
    {
        return new JsonResponse(['authors' => Author::query()->count()]);
    }

    /** Only the N+1, no slow query (production threshold path). */
    public function nPlusOne(): JsonResponse
    {
        $names = [];
        foreach (Post::query()->orderBy('id')->get() as $post) {
            $names[] = $post->author->name; // @n-plus-one-only
        }

        return new JsonResponse(['authors' => count(array_unique($names))]);
    }

    public function boom(): never
    {
        throw new RuntimeException('Order 42 could not be charged for anna.secret@example.com'); // @boom
    }

    public function logError(): JsonResponse
    {
        Log::error('Payment gateway rejected the charge', [ // @log-error
            'order' => 42,
            'password' => 'log-context-password',
            'nested' => ['api_token' => 'log-context-token'],
        ]);

        return new JsonResponse(['logged' => true]);
    }

    /** A queue job dispatched (sync driver) while the request is in flight. */
    public function nested(): JsonResponse
    {
        dispatch(new IntegrationJob);

        return new JsonResponse(['dispatched' => true]);
    }
}
