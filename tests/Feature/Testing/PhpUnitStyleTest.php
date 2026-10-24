<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Tests\Feature\Testing;

use Anabil\Sherlock\Testing\InteractsWithSherlock;
use Anabil\Sherlock\Tests\Feature\Testing\Fixtures\ProbeAuthor;
use Anabil\Sherlock\Tests\Feature\Testing\Support\ProbeFixtures;
use Anabil\Sherlock\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * A plain PHPUnit test case (no Pest API) using the trait, as a PHPUnit-only project would.
 */
final class PhpUnitStyleTest extends TestCase
{
    use InteractsWithSherlock;
    use ProbeFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sherlock.explain.enabled', false);
        self::createProbeSchema();
    }

    protected function tearDown(): void
    {
        self::dropProbeSchema();

        parent::tearDown();
    }

    public function test_eager_loading_passes_and_returns_the_response(): void
    {
        $response = $this->assertNoNPlusOne(fn () => $this->get('/probe/eager'));

        $response->assertOk()->assertJson(['author-3' => 2]);
    }

    public function test_lazy_loading_fails_with_the_controller_line(): void
    {
        try {
            $this->assertNoQueryIssues(fn () => $this->get('/probe/lazy'));
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('N+1 query on `probe_posts` (3×)', $e->getMessage());
            $this->assertStringContainsString(self::locationOf(self::controllerFile(), 'controller-n-plus-one'), $e->getMessage());

            return;
        }

        $this->fail('assertNoQueryIssues() should have failed on a lazy loaded relation.');
    }

    public function test_query_count(): void
    {
        $authors = $this->assertQueryCountLessThan(2, fn () => ProbeAuthor::query()->get());

        $this->assertCount(self::AUTHORS, $authors);
    }
}
