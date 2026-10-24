<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Support\Null;

use Anabil\Sherlock\Contracts\Explainer;
use Anabil\Sherlock\Data\ExplainPlan;
use Anabil\Sherlock\Data\QueryRecord;

final class NullExplainer implements Explainer
{
    public function supports(string $driver): bool
    {
        return false;
    }

    public function explain(QueryRecord $query): ?ExplainPlan
    {
        return null;
    }
}
