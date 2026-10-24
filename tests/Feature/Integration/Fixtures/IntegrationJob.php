<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Integration\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job with its own N+1, so a standalone run produces a persisted (threshold) trace.
 */
final class IntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $authors = 0;

    public function handle(): void
    {
        $names = [];
        foreach (Post::query()->orderBy('id')->get() as $post) {
            $names[] = $post->author->name; // @job-n-plus-one
        }

        $this->authors = count(array_unique($names));
    }
}
