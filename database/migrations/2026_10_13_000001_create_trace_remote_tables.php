<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Vortech\Trace\Support\TraceConfig;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return TraceConfig::connection();
    }

    public function up(): void
    {
        Schema::create('trace_links', function (Blueprint $table): void {
            $table->id();
            $table->string('source_instance_id', 64);
            $table->string('source_connection');
            $table->string('feed');
            $table->string('remote_key', 191);
            $table->string('model_type');
            $table->string('model_key', 191);
            $table->uuid('last_seen_run_id')->nullable()->index();
            $table->timestamps();

            // model_type is part of the remote key index so one feed can feed more than one local model
            $table->unique(['source_instance_id', 'feed', 'model_type', 'remote_key'], 'trace_links_remote_unique');
            $table->unique(['source_instance_id', 'feed', 'model_type', 'model_key'], 'trace_links_local_unique');
            $table->index(['model_type', 'model_key']);
        });

        Schema::create('trace_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('source_connection');
            // unknown until the first response of the source
            $table->string('source_instance_id', 64)->nullable();
            $table->string('feed');
            $table->string('model_type');
            $table->string('schema_hash')->nullable();
            $table->text('cursor')->nullable();
            $table->string('status', 30)->default('new');
            $table->text('bootstrap_cursor')->nullable();
            $table->string('bootstrap_watermark')->nullable();
            $table->uuid('bootstrap_run_id')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['source_connection', 'feed', 'model_type']);
            $table->unique(['source_instance_id', 'feed', 'model_type']);
        });

        Schema::create('trace_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_instance_id', 64)->nullable();
            $table->string('feed');
            $table->string('model_type');
            $table->string('mode', 20);
            $table->string('status', 20)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            foreach (['created', 'updated', 'unchanged', 'deleted', 'restored', 'errors'] as $counter) {
                $table->unsignedBigInteger($counter)->default(0);
            }
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_sync_runs');
        Schema::dropIfExists('trace_sync_states');
        Schema::dropIfExists('trace_links');
    }
};
