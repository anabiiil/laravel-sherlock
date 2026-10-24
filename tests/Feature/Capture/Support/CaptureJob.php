<?php

declare(strict_types=1);

namespace Anabil\Tracing\Tests\Feature\Capture\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CaptureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;

    public function __construct(public int $queries = 2, public bool $fail = false) {}

    public function handle(): void
    {
        for ($i = 0; $i < $this->queries; $i++) {
            DB::select('select ? as n', [$i]);
        }

        if ($this->fail) {
            throw new RuntimeException('Job failed for password=Hunter2-Secret!');
        }
    }
}
