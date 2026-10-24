<?php

declare(strict_types=1);

namespace Anabil\Tracing\Enums;

enum Bottleneck: string
{
    case Database = 'database';
    case Application = 'application';
    case ExternalService = 'external_service';
    case Cache = 'cache';
    case Bootstrap = 'bootstrap';
    case Mixed = 'mixed';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Database => 'Database',
            self::Application => 'Application / Server',
            self::ExternalService => 'External service',
            self::Cache => 'Cache',
            self::Bootstrap => 'Bootstrap',
            self::Mixed => 'Mixed',
            self::Unknown => 'Unknown',
        };
    }
}
