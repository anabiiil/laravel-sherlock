<?php

declare(strict_types=1);

use Anabil\Sherlock\Tests\TestCase;

/*
| Feature and Privacy tests boot Laravel (Testbench, MySQL). Unit and Arch tests are
| plain PHP for speed; a Unit test needing the container can opt in with uses(TestCase::class).
*/

pest()->extend(TestCase::class)->in('Feature', 'Privacy');
