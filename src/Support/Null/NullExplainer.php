<?php

declare(strict_types=1);

namespace Anabil\Tracing\Support\Null;

use Anabil\Tracing\Contracts\Explainer;
use Anabil\Tracing\Data\ExplainPlan;
use Anabil\Tracing\Data\QueryRecord;

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
