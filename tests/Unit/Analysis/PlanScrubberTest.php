<?php

declare(strict_types=1);

use Anabil\Sherlock\Analysis\Explain\PlanScrubber;
use Anabil\Sherlock\Tests\Unit\Analysis\AnalysisFactory as F;

it('replaces literal values in plan strings and keeps identifiers', function (string $input, string $expected): void {
    expect(PlanScrubber::scrubString($input))->toBe($expected);
})->with([
    'mysql string + charset' => ["(`db`.`users`.`email` = _utf8mb4'ann@example.com')", '(`db`.`users`.`email` = ?)'],
    'mysql number' => ['(`db`.`orders`.`user_id` = 5)', '(`db`.`orders`.`user_id` = ?)'],
    'escaped quotes' => ["(`t`.`name` = 'O''Brien\\'s')", '(`t`.`name` = ?)'],
    'postgres cast' => ["((status)::text = 'paid'::text)", '((status)::text = ?::text)'],
    'postgres array' => ["(id = ANY ('{1,2,3}'::bigint[]))", '(id = ANY (?::bigint[]))'],
    'decimal and hex' => ['((total > 10.5) AND (flags = 0x1F))', '((total > ?) AND (flags = ?))'],
    'identifiers with digits' => ['(`t1`.`c2` = `orders_2`.`id`) and "Order3".x = $1', '(`t1`.`c2` = `orders_2`.`id`) and "Order3".x = $1'],
    'no literal' => ['Seq Scan', 'Seq Scan'],
    'numeric leaf (cost)' => ['1.25', '1.25'],
]);

it('scrubs every string leaf of a real plan without touching numbers or structure', function (): void {
    $plan = F::fixture('mysql-unused-key')['plan'];
    $scrubbed = PlanScrubber::scrub($plan);
    $json = (string) json_encode($scrubbed);

    expect($json)->not->toContain("'x'")
        ->and($json)->toContain('<> ?')
        ->and(array_keys($scrubbed))->toBe(array_keys($plan))
        ->and((string) json_encode(PlanScrubber::scrub(F::fixture('postgres-seq-scan')['plan'])))->toContain('"Filter":"(user_id = ?)"')->toContain('"Total Cost":2041');
});
