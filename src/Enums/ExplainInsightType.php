<?php

declare(strict_types=1);

namespace Anabil\Sherlock\Enums;

enum ExplainInsightType: string
{
    case FullTableScan = 'full_table_scan';
    case NoIndexUsed = 'no_index_used';
    case Filesort = 'filesort';
    case TemporaryTable = 'temporary_table';
    case LargeRowsExamined = 'large_rows_examined';
    case Other = 'other';
}
