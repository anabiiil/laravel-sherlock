<?php

declare(strict_types=1);

use Anabil\Sherlock\Context\WorkerMemoryMonitor;
use Anabil\Sherlock\Support\QueueWait;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * @param  array<string, mixed>  $payload
 */
function payloadJob(array $payload, int $attempts = 1): JobContract
{
    return new class($payload, $attempts) extends Job implements JobContract
    {
        /**
         * @param  array<string, mixed>  $payload
         */
        public function __construct(private readonly array $data, private readonly int $tries) {}

        public function getJobId(): string
        {
            return '1';
        }

        public function getRawBody(): string
        {
            return (string) json_encode($this->data);
        }

        public function attempts(): int
        {
            return $this->tries;
        }
    };
}

/**
 * @param  list<int>  $values  memory_get_usage() values returned one per job
 */
function memorySource(array &$values): Closure
{
    return static function () use (&$values): int {
        return (int) array_shift($values);
    };
}

const MB = 1048576;

describe('QueueWait', function (): void {
    it('measures from Horizon pushedAt with microsecond precision', function (): void {
        $job = payloadJob(['pushedAt' => '1700000000.250', 'createdAt' => 1699999000]);

        expect(QueueWait::measure($job, 1700000045.5))->toBe(45250.0);
    });

    it('measures from the Laravel 12+ createdAt minus the delay', function (): void {
        expect(QueueWait::measure(payloadJob(['createdAt' => 1700000000, 'delay' => 30]), 1700000045.0))->toBe(15000.0)
            ->and(QueueWait::measure(payloadJob(['createdAt' => 1700000000, 'delay' => null]), 1700000002.0))->toBe(2000.0)
            // Clock skew between servers never yields a negative wait.
            ->and(QueueWait::measure(payloadJob(['createdAt' => 1700000100]), 1700000000.0))->toBe(0.0);
    });

    it('reports nothing for retries and Laravel 11 payloads without a push time', function (): void {
        expect(QueueWait::measure(payloadJob(['createdAt' => 1700000000], attempts: 2), 1700000045.0))->toBeNull()
            ->and(QueueWait::measure(payloadJob(['uuid' => 'x', 'displayName' => 'App\\Jobs\\A']), 1700000045.0))->toBeNull();
    });
});

describe('WorkerMemoryMonitor', function (): void {
    it('detects steady growth once per threshold step and names the contributors', function (): void {
        // 5 warm-up jobs, then +2MB per job.
        $values = [...array_fill(0, 5, 50 * MB), ...array_map(static fn (int $i): int => 50 * MB + $i * 2 * MB, range(1, 60))];
        $monitor = new WorkerMemoryMonitor(growthBytes: 32 * MB, minJobs: 10, bufferSize: 20, warmupJobs: 5, reader: memorySource($values));

        $signals = [];
        for ($job = 1; $job <= 65; $job++) {
            $signal = $monitor->sample($job % 3 === 0 ? 'App\\Jobs\\Import' : 'App\\Jobs\\Mail');
            if ($signal !== null) {
                $signals[$job] = $signal;
            }
        }

        // Growth since the baseline crosses 32MB after 16 jobs (job 21), 64MB after 32 (job 37), 96MB after 48 (job 53).
        expect(array_keys($signals))->toBe([21, 37, 53])
            ->and($signals[21]->step)->toBe(1)
            ->and($signals[21]->baselineBytes)->toBe(50 * MB)
            ->and($signals[21]->growthBytes())->toBe(32 * MB)
            ->and($signals[21]->jobs)->toBe(16)
            ->and($signals[21]->growingRatio)->toBe(1.0)
            ->and(array_keys($signals[21]->contributors))->toBe(['App\\Jobs\\Mail', 'App\\Jobs\\Import'])
            ->and($signals[53]->step)->toBe(3)
            ->and($monitor->bufferedCount())->toBe(20);
    });

    it('ignores a one-off allocation (a cache warmed once) that is not steady growth', function (): void {
        $values = [...array_fill(0, 5, 50 * MB), 90 * MB, ...array_fill(0, 40, 90 * MB)];
        $monitor = new WorkerMemoryMonitor(growthBytes: 32 * MB, minJobs: 10, bufferSize: 20, reader: memorySource($values));

        $signals = 0;
        for ($job = 1; $job <= 46; $job++) {
            $signals += $monitor->sample('App\\Jobs\\Warm') === null ? 0 : 1;
        }

        expect($signals)->toBe(0)
            ->and($monitor->baseline())->toBe(50 * MB);
    });

    it('needs the minimum number of jobs after the warm-up', function (): void {
        $values = [...array_fill(0, 5, 10 * MB), ...array_map(static fn (int $i): int => 10 * MB + $i * 40 * MB, range(1, 5))];
        $monitor = new WorkerMemoryMonitor(growthBytes: 32 * MB, minJobs: 20, reader: memorySource($values));

        $signals = 0;
        for ($job = 1; $job <= 10; $job++) {
            $signals += $monitor->sample('App\\Jobs\\Big') === null ? 0 : 1;
        }

        expect($signals)->toBe(0);
    });

    it('keeps a bounded buffer whatever the number of jobs', function (): void {
        $monitor = new WorkerMemoryMonitor(bufferSize: 50, reader: static fn (): int => 10 * MB);

        for ($job = 0; $job < 5000; $job++) {
            $monitor->sample('App\\Jobs\\Tick');
        }

        expect($monitor->bufferedCount())->toBe(50);
    });
});
