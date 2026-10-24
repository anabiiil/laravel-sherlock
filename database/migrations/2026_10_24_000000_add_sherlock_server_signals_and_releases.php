<?php

declare(strict_types=1);

use Anabil\Sherlock\Storage\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server signals, queue wait and release on traces; first / last seen release and cumulative
 * impact on issues; the sherlock_releases table. Additive: every new column is nullable or has a
 * default, so rows written before this migration stay valid.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        $tables = Tables::fromConfig();
        $schema = Schema::connection($this->getConnection());

        $schema->table($tables->traces(), static function (Blueprint $table): void {
            $table->string('release', 64)->nullable();
            // User + system CPU time of the unit (getrusage).
            $table->double('cpu_ms')->nullable();
            // 1-minute load average when the unit ended, and the server's core count.
            $table->double('load_avg')->nullable();
            $table->unsignedSmallInteger('cpu_cores')->nullable();
            // Available system memory (Linux MemAvailable) when the trace was persisted.
            $table->unsignedInteger('memory_free_mb')->nullable();
            // Queue jobs: time between becoming available and starting.
            $table->double('queue_wait_ms')->nullable();

            $table->index(['release', 'type', 'name']);
            $table->index(['release', 'created_at']);
        });

        $schema->table($tables->issues(), static function (Blueprint $table): void {
            $table->string('first_seen_release', 64)->nullable();
            $table->string('last_seen_release', 64)->nullable();
            // Cumulative time cost of every occurrence (ms).
            $table->double('impact_ms')->default(0);

            $table->index('first_seen_release');
            $table->index('last_seen_release');
            $table->index('impact_ms');
        });

        $schema->create($tables->releases(), static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('release', 64)->unique();
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');

            $table->index('first_seen_at');
        });
    }

    public function down(): void
    {
        $tables = Tables::fromConfig();
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists($tables->releases());

        $this->dropColumns($tables->issues(), ['first_seen_release', 'last_seen_release', 'impact_ms']);
        $this->dropColumns($tables->traces(), ['release', 'cpu_ms', 'load_avg', 'cpu_cores', 'memory_free_mb', 'queue_wait_ms']);
    }

    /**
     * Drop the indexes using $columns, then the columns; tolerant of a partially migrated or
     * already dropped table (sherlock:clear, a failed earlier run).
     *
     * @param  list<string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter($columns, static fn (string $column): bool => $schema->hasColumn($table, $column)));
        if ($existing === []) {
            return;
        }

        $indexes = [];
        foreach ($schema->getIndexes($table) as $index) {
            if (! ($index['primary'] ?? false) && array_intersect($index['columns'], $existing) !== []) {
                $indexes[] = $index['name'];
            }
        }

        $schema->table($table, static function (Blueprint $blueprint) use ($indexes, $existing): void {
            foreach ($indexes as $index) {
                $blueprint->dropIndex($index);
            }
            $blueprint->dropColumn($existing);
        });
    }
};
