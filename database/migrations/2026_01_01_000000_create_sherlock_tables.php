<?php

declare(strict_types=1);

use Anabil\Sherlock\Storage\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sherlock storage on the configured connection (config "sherlock.storage.connection").
 * No foreign keys on purpose: the tables may live on another connection, inserts stay cheap
 * and sherlock:prune removes child rows itself, in chunks.
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

        $schema->create($tables->traces(), static function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('type', 16);
            $table->string('name', 255);
            $table->string('method', 10)->nullable();
            $table->integer('status_code')->nullable();
            $table->double('duration_ms')->default(0);
            $table->double('db_ms')->default(0);
            $table->unsignedInteger('db_count')->default(0);
            $table->double('http_ms')->default(0);
            $table->unsignedInteger('http_count')->default(0);
            $table->unsignedInteger('cache_hits')->default(0);
            $table->unsignedInteger('cache_misses')->default(0);
            $table->double('bootstrap_ms')->default(0);
            $table->unsignedBigInteger('memory_peak')->default(0);
            $table->string('bottleneck', 32);
            $table->string('verdict_reason', 1000)->nullable();
            $table->string('record_reason', 16);
            $table->string('user_id', 64)->nullable();
            // uri, route, failed, tags, metadata, verdict breakdown, http calls, logs, findings (all redacted).
            $table->json('details')->nullable();
            $table->dateTime('created_at');

            $table->index('created_at');
            $table->index(['type', 'name', 'created_at']);
            $table->index(['bottleneck', 'created_at']);
        });

        $schema->create($tables->queries(), static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('trace_id', 26);
            $table->string('fingerprint', 64);
            $table->mediumText('normalized_sql');
            // Redacted bindings; NULL when bindings are not stored (production default).
            $table->json('bindings')->nullable();
            $table->double('duration_ms')->default(0);
            $table->double('offset_ms')->default(0);
            $table->string('connection', 64);
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->dateTime('created_at');

            $table->index('trace_id');
            $table->index(['fingerprint', 'created_at']);
            $table->index('created_at');
        });

        $schema->create($tables->issues(), static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('fingerprint', 64)->unique();
            $table->string('type', 32);
            $table->string('severity', 16);
            $table->string('status', 16)->default('open');
            $table->string('title', 255);
            $table->json('context')->nullable();
            $table->unsignedBigInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('regressed_at')->nullable();
            $table->dateTime('last_notified_at')->nullable();
            $table->unsignedInteger('pending_notify_count')->default(0);
            $table->string('ai_status', 32)->nullable();
            $table->mediumText('ai_analysis')->nullable();
            $table->string('ai_model', 128)->nullable();
            $table->dateTime('ai_analyzed_at')->nullable();

            $table->index(['status', 'severity', 'last_seen_at']);
            $table->index(['type', 'last_seen_at']);
            $table->index('last_seen_at');
            $table->index('first_seen_at');
            $table->index('regressed_at');
        });

        $schema->create($tables->issueTraces(), static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('issue_id');
            $table->char('trace_id', 26);
            $table->dateTime('created_at');

            $table->unique(['issue_id', 'trace_id']);
            $table->index(['issue_id', 'created_at']);
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (array_reverse(Tables::fromConfig()->all()) as $table) {
            $schema->dropIfExists($table);
        }
    }
};
