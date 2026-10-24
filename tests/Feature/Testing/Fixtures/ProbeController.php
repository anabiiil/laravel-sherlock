<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Testing\Fixtures;

use Illuminate\Http\JsonResponse;

final class ProbeController
{
    /** Lazy loads the posts of every author: one query per author. */
    public function lazy(): JsonResponse
    {
        $counts = [];
        foreach (ProbeAuthor::query()->orderBy('id')->get() as $author) {
            $counts[$author->name] = $author->probePosts->count(); // @controller-n-plus-one
        }

        return new JsonResponse($counts);
    }

    /** Same data, eager loaded: two queries. */
    public function eager(): JsonResponse
    {
        $counts = [];
        foreach (ProbeAuthor::query()->with('probePosts')->orderBy('id')->get() as $author) {
            $counts[$author->name] = $author->probePosts->count();
        }

        return new JsonResponse($counts);
    }
}
